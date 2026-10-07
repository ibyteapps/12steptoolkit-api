<?php

use App\Models\Account;
use App\Models\AccountDetail;
use App\Models\InstallSecret;
use App\Models\ReminderSubscription;
use Illuminate\Support\Carbon;

use function Tests\Support\signAs;

/**
 * `sync_reminders.php` — rebuild a reminder schedule from the person's settings.
 *
 * The old script opens `define('REQUIRE_AUTH', false); define('REQUIRE_HMAC',
 * false);` and then takes `account_id` from the body, so it is an
 * unauthenticated write for any account and an account-existence oracle. It
 * also stores no computed fire time, so the sender has to do timezone
 * arithmetic per subscriber per minute.
 */
beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => 'Jo', 'created' => now()]);
    $this->account->save();

    $this->details = new AccountDetail;
    $this->details->forceFill([
        'accountid' => $this->account->id,
        'notification_morning' => '07:30',
        'notification_night' => '22:00',
        'notification_hourly_start' => '08:00',
        'notification_hourly_end' => '21:00',
        'timezone' => 'Europe/London',
        'language' => 'en',
        'created' => now(),
    ])->save();

    $this->deviceId = 'device-1';
    $this->secret = random_bytes(32);
    InstallSecret::query()->insert([
        'account_id' => $this->account->id, 'device_id' => $this->deviceId,
        'secret' => $this->secret, 'status' => InstallSecret::ACTIVE,
    ]);
    $this->token = $this->account->createToken('Test', ['*'], now()->addDay())->plainTextToken;
});

it('builds the three reminders from the stored times', function () {
    signAs($this, '/api/v1/android/19/sync_reminders.php')
        ->assertOk()
        ->assertJsonPath('response.timezone', 'Europe/London');

    $rows = ReminderSubscription::query()->where('account_id', $this->account->id)->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('hhmm', 'type')->all())->toEqualCanonicalizing([
            'MORNING' => '07:30', 'NIGHT' => '22:00', 'HOURLY' => '08:00',
        ])
        ->and($rows->every(fn ($r) => $r->next_fire_at !== null))->toBeTrue()
        ->and($rows->every(fn ($r) => $r->days_mask === ReminderSubscription::EVERY_DAY))->toBeTrue();
});

it('needs a token, which the old script did not', function () {
    $this->post('/api/v1/android/19/sync_reminders.php', ['account_id' => $this->account->id])
        ->assertStatus(401);

    expect(ReminderSubscription::query()->count())->toBe(0);
});

it('syncs the caller, never an account named in the body', function () {
    $other = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $other->save();

    signAs($this, '/api/v1/android/19/sync_reminders.php', ['account_id' => $other->id])
        ->assertStatus(403);

    expect(ReminderSubscription::query()->count())->toBe(0);
});

it('is idempotent, and does not accumulate rows', function () {
    foreach (range(1, 3) as $i) {
        signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();
    }

    expect(ReminderSubscription::query()->count())->toBe(3);
});

it('switches a reminder off when its time is cleared, and keeps the row', function () {
    signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();
    $night = ReminderSubscription::query()->where('type', 'NIGHT')->sole();
    $night->forceFill(['last_fired_at' => now()->subDay()])->save();

    $this->details->forceFill(['notification_night' => ''])->save();
    signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();

    // Switched off, not deleted: a snooze and a last-fired time should survive
    // being turned off and on again.
    expect((bool) $night->fresh()->enabled)->toBeFalse()
        ->and($night->fresh()->last_fired_at)->not->toBeNull()
        ->and(ReminderSubscription::query()->count())->toBe(3);
});

it('disables the old time when somebody changes it', function () {
    signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();

    $this->details->forceFill(['notification_morning' => '06:15'])->save();
    signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();

    $morning = ReminderSubscription::query()->where('type', 'MORNING')->get();

    expect($morning)->toHaveCount(2)
        ->and($morning->where('enabled', true)->pluck('hhmm')->all())->toBe(['06:15']);
});

it('ignores a time that is not a time', function () {
    $this->details->forceFill(['notification_morning' => 'sometimes', 'notification_night' => '25:99'])->save();

    signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();

    // Treated as off rather than scheduled at midnight. The column is a
    // varchar(5) with no constraint, so it holds whatever has ever been written.
    expect(ReminderSubscription::query()->where('enabled', true)->pluck('type')->all())->toBe(['HOURLY']);
});

it('prefers the timezone the request carries over the stored one', function () {
    // Somebody who has flown is in a new timezone before `account_details`
    // knows, and a reminder at half past seven in the wrong country is the
    // complaint this prevents.
    signAs($this, '/api/v1/android/19/sync_reminders.php', ['timezone' => 'America/New_York'])
        ->assertOk()
        ->assertJsonPath('response.timezone', 'America/New_York');

    expect(ReminderSubscription::query()->where('type', 'MORNING')->sole()->timezone)
        ->toBe('America/New_York');
});

it('falls back to UTC for a timezone it does not recognise', function () {
    $this->details->forceFill(['timezone' => 'Mars/Olympus_Mons'])->save();

    signAs($this, '/api/v1/android/19/sync_reminders.php')
        ->assertOk()
        ->assertJsonPath('response.timezone', 'UTC');
});

// ------------------------------------------------- the fire-time calculation

it('computes the next fire time in the subscriber\'s own timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00', 'UTC'));

    signAs($this, '/api/v1/android/19/sync_reminders.php')->assertOk();

    // 07:30 London in June is 06:30 UTC, and 10:00 UTC has passed it, so the
    // next one is tomorrow.
    $morning = ReminderSubscription::query()->where('type', 'MORNING')->sole();

    expect($morning->next_fire_at->toDateTimeString())->toBe('2026-06-02 06:30:00');

    Carbon::setTestNow();
});

it('keeps the local time across a clock change', function () {
    // The reason the timezone is stored and the UTC time is derived: 07:30
    // London is 07:30 UTC in winter and 06:30 UTC in summer, and a reminder
    // that drifts by an hour twice a year is the bug this avoids.
    $row = new ReminderSubscription;
    $row->forceFill([
        'account_id' => $this->account->id, 'type' => 'MORNING', 'hhmm' => '07:30',
        'timezone' => 'Europe/London', 'days_mask' => ReminderSubscription::EVERY_DAY,
        'enabled' => true, 'next_fire_at' => now(),
    ]);

    $winter = $row->nextFireAt(Carbon::parse('2026-01-15 00:00:00', 'UTC'));
    $summer = $row->nextFireAt(Carbon::parse('2026-07-15 00:00:00', 'UTC'));

    expect($winter->format('H:i'))->toBe('07:30')
        ->and($summer->format('H:i'))->toBe('06:30');
});

it('skips a day the mask excludes', function () {
    $row = new ReminderSubscription;
    $row->forceFill([
        'account_id' => $this->account->id, 'type' => 'MORNING', 'hhmm' => '09:00',
        'timezone' => 'UTC',
        // Weekdays only: bits 0-4.
        'days_mask' => 0b0011111,
        'enabled' => true,
    ]);

    // A Friday evening, so the next weekday slot is Monday.
    $next = $row->nextFireAt(Carbon::parse('2026-06-05 20:00:00', 'UTC'));

    expect($next->toDateString())->toBe('2026-06-08')
        ->and($next->isMonday())->toBeTrue();
});

it('never fires when no day is selected', function () {
    $row = new ReminderSubscription;
    $row->forceFill(['hhmm' => '09:00', 'timezone' => 'UTC', 'days_mask' => 0]);

    expect($row->nextFireAt())->toBeNull();
});
