<?php

use App\Models\Account;
use App\Models\ReminderSubscription;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 | `sync_reminders.php` has always written `reminder_subscriptions`. Until
 | now nothing read it, so every morning and nightly reminder a member had
 | set was recorded, scheduled, and never delivered. This is the other half.
 */

/** A sender that remembers what it was asked to send. */
class RecordingSender implements PushSender
{
    /** @var array<int, array{tokens: array<int, string>, message: PushMessage}> */
    public array $sent = [];

    public bool $throw = false;

    public function send(array $tokens, PushMessage $message): int
    {
        if ($this->throw) {
            throw new RuntimeException('transport is down');
        }

        $this->sent[] = ['tokens' => $tokens, 'message' => $message];

        return count($tokens);
    }
}

beforeEach(function () {
    $this->sender = new RecordingSender;
    $this->app->instance(PushSender::class, $this->sender);

    $this->account = Account::make()->forceFill([
        'nickname' => ' ', 'created' => now(), 'fcm_token' => 'token-from-the-old-script',
    ]);
    $this->account->save();

    $this->reminder = function (array $overrides = []): ReminderSubscription {
        $row = new ReminderSubscription;
        $row->forceFill(array_merge([
            'account_id' => $this->account->id,
            'type' => ReminderSubscription::NIGHT,
            'hhmm' => '21:30',
            'days_mask' => ReminderSubscription::EVERY_DAY,
            'timezone' => 'UTC',
            'language' => 'en',
            'enabled' => true,
            'next_fire_at' => now()->subMinute(),
            'created' => now(),
        ], $overrides));
        $row->save();

        return $row;
    };
});

$dispatch = fn (?Carbon $at = null) => app(ReminderDispatcher::class)->dispatch($at);

it('sends a reminder that is due', function () use ($dispatch) {
    ($this->reminder)();

    $stats = $dispatch();

    expect($stats['due'])->toBe(1)
        ->and($stats['sent'])->toBe(1)
        ->and($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0]['tokens'])->toBe(['token-from-the-old-script']);
});

it('leaves one that is not due yet', function () use ($dispatch) {
    ($this->reminder)(['next_fire_at' => now()->addHour()]);

    expect($dispatch()['due'])->toBe(0)
        ->and($this->sender->sent)->toBe([]);
});

it('leaves a disabled one and a snoozed one', function () use ($dispatch) {
    ($this->reminder)(['enabled' => false, 'hhmm' => '06:00']);
    ($this->reminder)(['snoozed_until' => now()->addHours(2), 'hhmm' => '07:00']);

    expect($dispatch()['due'])->toBe(0);
});

it('moves the schedule on after sending', function () use ($dispatch) {
    $reminder = ($this->reminder)();
    $before = $reminder->next_fire_at;

    $dispatch();

    $after = $reminder->fresh();

    expect($after->next_fire_at->greaterThan($before))->toBeTrue()
        ->and($after->last_fired_at)->not->toBeNull();
});

/*
 | The row has to move on even when nothing was sent, or it is re-read every
 | minute for ever — and the day somebody fixes the push key, a week of
 | backlog arrives at once. A missed reminder is missed; it is not owed.
 */
it('moves the schedule on when the member has no device', function () use ($dispatch) {
    DB::table('accounts')->where('id', $this->account->id)->update(['fcm_token' => '']);
    $reminder = ($this->reminder)();

    $stats = $dispatch();

    expect($stats['no_device'])->toBe(1)
        ->and($stats['sent'])->toBe(0)
        ->and($reminder->fresh()->next_fire_at->isFuture())->toBeTrue();
});

it('moves the schedule on when the transport throws', function () use ($dispatch) {
    $this->sender->throw = true;
    $reminder = ($this->reminder)();

    $dispatch();

    expect($reminder->fresh()->next_fire_at->isFuture())->toBeTrue();
});

it('does not stop the run when one member fails', function () use ($dispatch) {
    ($this->reminder)(['hhmm' => '21:30']);
    ($this->reminder)(['hhmm' => '22:30']);

    $this->sender->throw = true;
    $stats = $dispatch();

    expect($stats['due'])->toBe(2);
});

/*
 | `ix_pick_order` on (type, language, times_used, last_used) says how the
 | wording is meant to be chosen: least used first. Otherwise somebody who
 | has had a nightly reminder for three years reads the same sentence a
 | thousand times.
 */
it('rotates the wording, least used first', function () use ($dispatch) {
    DB::table('notification_texts')->insert([
        ['type' => 'NIGHT', 'language' => 'en', 'description' => 'Used a lot', 'times_used' => 50],
        ['type' => 'NIGHT', 'language' => 'en', 'description' => 'Hardly used', 'times_used' => 1],
    ]);

    ($this->reminder)();
    $dispatch();

    expect($this->sender->sent[0]['message']->data['text'])->toBe('Hardly used');

    // And the pick is recorded, so the next one is a different line.
    expect((int) DB::table('notification_texts')->where('description', 'Hardly used')->value('times_used'))
        ->toBe(2);
});

it('falls back to English when the language has no line of its own', function () use ($dispatch) {
    DB::table('notification_texts')->insert([
        ['type' => 'NIGHT', 'language' => 'en', 'description' => 'The English one', 'times_used' => 0],
    ]);

    ($this->reminder)(['language' => 'pl']);
    $dispatch();

    expect($this->sender->sent[0]['message']->data['text'])->toBe('The English one');
});

it('still sends something when the table is empty', function () use ($dispatch) {
    ($this->reminder)();
    $dispatch();

    expect($this->sender->sent[0]['message']->data['text'])->toBe('Time for your nightly inventory.');
});

/*
 | The payload is the part that decides whether any of this arrives anywhere.
 | Both clients switch on `data['table']`, and neither reads `type`: the new
 | one answers `IgnoreLink('unknown_table')` for a value it does not know and
 | `empty_payload` when `table` is absent altogether, so a reminder sent
 | without it is delivered by Google, dropped by the app, and invisible from
 | here.
 */
it('routes on table and subtype, as both clients read it', function () use ($dispatch) {
    $reminder = ($this->reminder)();
    $dispatch();

    $message = $this->sender->sent[0]['message'];

    expect($message->table())->toBe('REMINDER')
        ->and($message->data['subtype'])->toBe('NIGHT')
        // Unique per firing. `PushRouter._reminder` ignores a repeat of the
        // last id it handled, so a bare row id would make every reminder
        // after the first look like a duplicate of the first.
        ->and($message->data['reminder_id'])->toBe($reminder->id.'-'.$reminder->next_fire_at->getTimestamp());
});

it('sends reminders data-only, so nobody gets them twice', function () use ($dispatch) {
    ($this->reminder)();
    $dispatch();

    // Both clients compose and show the reminder themselves — the new one
    // suppressing the server's copy when the device has already armed a
    // local one. An FCM `notification` block would show every morning
    // reminder twice, which is why the live scripts send no title.
    expect($this->sender->sent[0]['message']->isSilent())->toBeTrue()
        ->and($this->sender->sent[0]['message']->title)->toBe('');
});

/*
 | MORNING and NIGHT are once a day. HOURLY is a window: its hhmm is the
 | start, `notification_hourly_end` is the end, and it fires on the hour in
 | between.
 */
it('steps the hourly nudge by an hour inside its window', function () use ($dispatch) {
    DB::table('account_details')->insert([
        'accountid' => $this->account->id,
        'notification_hourly_end' => '21:00',
    ]);

    $at = Carbon::parse('2026-06-10 14:00:00', 'UTC');
    $reminder = ($this->reminder)([
        'type' => ReminderSubscription::HOURLY, 'hhmm' => '09:00',
        'next_fire_at' => $at->copy()->subMinute(),
    ]);

    $dispatch($at);

    expect($reminder->fresh()->next_fire_at->format('H:i'))->toBe('15:00');
});

it('rolls the hourly nudge to the next day once the window has closed', function () use ($dispatch) {
    DB::table('account_details')->insert([
        'accountid' => $this->account->id,
        'notification_hourly_end' => '21:00',
    ]);

    $at = Carbon::parse('2026-06-10 21:30:00', 'UTC');
    $reminder = ($this->reminder)([
        'type' => ReminderSubscription::HOURLY, 'hhmm' => '09:00',
        'next_fire_at' => $at->copy()->subMinute(),
    ]);

    $dispatch($at);

    $next = $reminder->fresh()->next_fire_at;

    expect($next->format('Y-m-d H:i'))->toBe('2026-06-11 09:00');
});
