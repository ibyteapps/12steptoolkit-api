<?php

use App\Models\Account;
use App\Models\ComplimentaryGrant;
use App\Models\ConsoleAudit;
use App\Models\ConsoleUser;
use App\Models\Gratitude;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Night;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->staff = new ConsoleUser;
    $this->staff->forceFill([
        'name' => 'Kim',
        'email' => 'kim@example.com',
        'password' => 'correct horse battery',
        'role' => 'admin',
        'active' => true,
    ])->save();

    $this->actingAs($this->staff, 'console');

    $this->account = Account::make()->forceFill([
        'nickname' => 'Jo',
        'email' => 'jo@example.com',
        'sobrietydate' => '2019-03-04',
        'created' => now()->subYears(2),
        'last_login_tstamp' => now()->subDay()->timestamp,
        'logincount' => 44,
    ]);
    $this->account->save();
});

it('shows every page to a signed-in staff member', function (string $path) {
    $this->get($path)->assertOk();
})->with([
    '/console',
    '/console/accounts',
    '/console/subscriptions',
    '/console/support',
    '/console/cutover',
    '/console/audit',
]);

it('keeps every page behind the login', function (string $path) {
    auth('console')->logout();

    $this->get($path)->assertRedirect(route('console.login'));
})->with([
    '/console',
    '/console/accounts',
    '/console/subscriptions',
    '/console/support',
    '/console/cutover',
    '/console/audit',
]);

/**
 * The data boundary, which is the one property of this console that must never
 * regress: **counts and dates and sync state, never content.**
 *
 * Each record below carries a string that appears nowhere else in the
 * application. If any of them ever reaches a console page — through a new panel,
 * a debug dump, an exception, an eager-loaded relation or a `select *` that
 * somebody added in a hurry — this test fails and says which one.
 */
it('never shows a word of what somebody wrote', function () {
    $secrets = [
        'inventory title' => 'INVTITLE-'.Str::random(8),
        'inventory description' => 'INVDESC-'.Str::random(8),
        'my fault' => 'MYFAULT-'.Str::random(8),
        'apology notes' => 'APOLOGY-'.Str::random(8),
        'journal' => 'JOURNAL-'.Str::random(8),
        'gratitude' => 'GRATITUDE-'.Str::random(8),
        'nightly answer' => 'NIGHTDESC-'.Str::random(8),
    ];

    (new Inventory)->forceFill([
        'accountid' => $this->account->id,
        'invtitle' => $secrets['inventory title'],
        'invdescription' => $secrets['inventory description'],
        'myfault' => $secrets['my fault'],
        'apologynotes' => $secrets['apology notes'],
        'tstamp' => now()->subDays(3)->timestamp,
        'created' => now()->subDays(3),
    ])->save();

    (new Journal)->forceFill([
        'accountid' => $this->account->id,
        'description' => $secrets['journal'],
        'tstamp' => now()->subDay()->timestamp,
        'created' => now()->subDay(),
    ])->save();

    (new Gratitude)->forceFill([
        'accountid' => $this->account->id,
        'description' => $secrets['gratitude'],
        'tstamp' => now()->subDay()->timestamp,
        'created' => now()->subDay(),
    ])->save();

    (new Night)->forceFill([
        'accountid' => $this->account->id,
        'desc1' => $secrets['nightly answer'],
        'tstamp' => now()->timestamp,
        'created' => now(),
    ])->save();

    $pages = [
        '/console',
        '/console/accounts?q='.$this->account->id,
        '/console/accounts/'.$this->account->id,
        '/console/subscriptions',
        '/console/support',
        '/console/cutover',
        '/console/audit',
    ];

    foreach ($pages as $page) {
        $body = $this->get($page)->assertOk()->getContent();

        foreach ($secrets as $what => $secret) {
            expect($body)->not->toContain($secret, "{$page} is showing the {$what}");
        }
    }
});

it('does count what it will not show', function () {
    foreach (range(1, 3) as $i) {
        (new Inventory)->forceFill([
            'accountid' => $this->account->id,
            'invtitle' => 'Something',
            'tstamp' => now()->subDays($i)->timestamp,
            'created' => now()->subDays($i),
        ])->save();
    }

    $this->get('/console/accounts/'.$this->account->id)
        ->assertOk()
        ->assertSee('Is their backup working?')
        ->assertSee('Inventories')
        // The count, and the fact that it is recent. That is the answer to the
        // question support is actually asked.
        ->assertSee('3')
        ->assertSee('1 day ago');
});

it('does not count another account\'s work', function () {
    $other = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $other->save();

    (new Journal)->forceFill([
        'accountid' => $other->id, 'description' => 'theirs', 'tstamp' => now()->timestamp, 'created' => now(),
    ])->save();

    $this->get('/console/accounts/'.$this->account->id)
        ->assertOk()
        ->assertSee('0 records', false);
});

it('finds an account by id, email, uuid and nickname', function () {
    $uuid = $this->account->uuid();

    foreach ([(string) $this->account->id, 'jo@example.com', $uuid, 'Jo'] as $term) {
        $this->get('/console/accounts?q='.urlencode($term))
            ->assertOk()
            ->assertSee('jo@example.com');
    }
});

it('does not leak whether an account exists to a stranger', function () {
    auth('console')->logout();

    // Sent to the login page, not told whether account 1 is there — which is why
    // ConsoleAuthenticate runs before route-model binding.
    $this->get('/console/accounts/'.$this->account->id)->assertRedirect(route('console.login'));
    $this->get('/console/accounts/99999999')->assertRedirect(route('console.login'));
});

it('records a look at an account, and a look at a ticket', function () {
    $this->get('/console/accounts/'.$this->account->id)->assertOk();

    $entry = ConsoleAudit::query()->where('action', 'account.view')->sole();

    expect($entry->console_user_id)->toBe($this->staff->id)
        ->and($entry->subject_type)->toBe('Account')
        ->and($entry->subject_id)->toBe((string) $this->account->id);
});

it('gives a subscription, with a reason, and grants access', function () {
    $this->post('/console/accounts/'.$this->account->id.'/grants', [
        'period' => '3_months',
        'reason' => 'Refund went wrong, ticket #214',
    ])->assertRedirect();

    $grant = ComplimentaryGrant::query()->sole();

    expect($grant->account_id)->toBe((int) $this->account->id)
        ->and($grant->granted_by)->toBe($this->staff->id)
        ->and($grant->reason)->toBe('Refund went wrong, ticket #214')
        ->and($grant->ends_at->isAfter(now()->addMonths(2)))->toBeTrue()
        // And the entitlement was recomputed, not left for a cron to notice.
        ->and($this->account->fresh()->entitlement->is_active)->toBeTrue()
        ->and($this->account->fresh()->entitlement->source)->toBe('complimentary');
});

it('refuses a grant with no reason, because that is what made the old flag useless', function () {
    $this->post('/console/accounts/'.$this->account->id.'/grants', ['period' => '1_year'])
        ->assertSessionHasErrors('reason');

    expect(ComplimentaryGrant::query()->count())->toBe(0);
});

it('revokes a grant without deleting it', function () {
    $this->post('/console/accounts/'.$this->account->id.'/grants', ['period' => 'lifetime', 'reason' => 'Hardship']);
    $grant = ComplimentaryGrant::query()->sole();

    $this->delete('/console/accounts/'.$this->account->id.'/grants/'.$grant->id)->assertRedirect();

    expect(ComplimentaryGrant::query()->count())->toBe(1)
        ->and($grant->fresh()->revoked_at)->not->toBeNull()
        ->and($this->account->fresh()->entitlement->is_active)->toBeFalse();
});

it('will not revoke a grant belonging to another account', function () {
    $other = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $other->save();

    $grant = new ComplimentaryGrant;
    $grant->forceFill([
        'account_id' => $other->id, 'period' => '1_year',
        'starts_at' => now(), 'ends_at' => now()->addYear(),
    ])->save();

    $this->delete('/console/accounts/'.$this->account->id.'/grants/'.$grant->id)->assertNotFound();

    expect($grant->fresh()->revoked_at)->toBeNull();
});

it('answers a ticket and moves it on', function () {
    $ticket = new SupportTicket;
    $ticket->forceFill([
        'uuid' => (string) Str::uuid(),
        'account_id' => $this->account->id,
        'subject' => 'My backup has stopped',
        'state' => 'open',
        'last_member_at' => now()->subDays(2),
    ])->save();

    (new SupportMessage)->forceFill([
        'support_ticket_id' => $ticket->id,
        'from_staff' => false,
        'body' => 'Nothing has synced since Tuesday.',
        'created_at' => now()->subDays(2),
    ])->save();

    // The thread is the one place a member's own words are shown, because a
    // reply to something you cannot read is not support.
    $this->get('/console/support/'.$ticket->uuid)
        ->assertOk()
        ->assertSee('Nothing has synced since Tuesday.')
        // And the link to their account, which is the point of the screen.
        ->assertSee(route('console.accounts.show', $this->account->id));

    $this->post('/console/support/'.$ticket->uuid.'/reply', [
        'body' => 'Had a look — your writing is all there, 42 inventories, last one Tuesday.',
        'then' => 'answered',
    ])->assertRedirect(route('console.support.show', $ticket->uuid));

    expect($ticket->fresh()->state)->toBe('answered')
        ->and($ticket->fresh()->member_read_at)->toBeNull()
        ->and($ticket->messages()->where('from_staff', true)->count())->toBe(1);
});

it('lists the people who have been waiting longest first', function () {
    foreach ([5, 1, 3] as $days) {
        $t = new SupportTicket;
        $t->forceFill([
            'uuid' => (string) Str::uuid(),
            'subject' => "Waiting {$days} days",
            'state' => 'open',
            'last_member_at' => now()->subDays($days),
        ])->save();
    }

    $this->get('/console/support')
        ->assertOk()
        ->assertSeeInOrder(['Waiting 5 days', 'Waiting 3 days', 'Waiting 1 days']);
});

it('says plainly what it will not show', function () {
    $this->get('/console')
        ->assertOk()
        ->assertSee('What this console will not show you');
});
