<?php

use App\Mail\PasswordResetLink;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 | The live script is one of the better-written files in the tree: a random
 | code, a 30-minute expiry, old codes cleared, and — the part that matters —
 | the same answer whether or not the address is registered. A reset form that
 | says "no account with that email" is a way of asking whether somebody is in
 | A.A.
 |
 | So these tests are mostly about the answer being identical, and about what
 | does and does not reach the log.
 */

beforeEach(function () {
    Mail::fake();

    $this->account = Account::make()->forceFill([
        'nickname' => 'Tom', 'email' => 'member@example.com', 'created' => now(),
    ]);
    $this->account->save();

    $this->ask = fn (string $email) => $this->post('/api/v1/android/19/reset_password_for_email.php', [
        'email' => $email,
    ]);
});

it('writes a code and sends the link', function () {
    ($this->ask)('member@example.com')
        ->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'If that email is registered, a reset link has been sent.');

    $row = DB::table('password_resets')->sole();

    expect((int) $row->account_id)->toBe((int) $this->account->id)
        // `reset_code` is char(32), so 32 hex characters and not a word.
        ->and(strlen((string) $row->reset_code))->toBe(32)
        ->and((int) $row->expires_at)->toBeGreaterThan(now()->addMinutes(25)->getTimestamp());

    Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use ($row) {
        return $mail->hasTo('member@example.com')
            && str_contains($mail->link, (string) $row->reset_code)
            // The page that is live today, which reset emails already in
            // people's inboxes point at.
            && str_contains($mail->link, '/app/validate2/reset_password.php');
    });
});

it('answers an unknown address exactly the same, and sends nothing', function () {
    $known = ($this->ask)('member@example.com');
    $unknown = ($this->ask)('nobody@example.com');

    expect($unknown->getContent())->toBe($known->getContent());

    expect(DB::table('password_resets')->count())->toBe(1);
    Mail::assertSentCount(1);
});

it('is case-insensitive about the address', function () {
    ($this->ask)('MEMBER@Example.com')->assertOk();

    expect(DB::table('password_resets')->count())->toBe(1);
});

it('will not reset an erased account', function () {
    $this->account->forceFill(['email' => 'DELETED'])->save();

    ($this->ask)('DELETED')->assertStatus(400);

    expect(DB::table('password_resets')->count())->toBe(0);
});

it('clears the previous code, so the older email stops working', function () {
    ($this->ask)('member@example.com')->assertOk();
    $first = (string) DB::table('password_resets')->value('reset_code');

    ($this->ask)('member@example.com')->assertOk();

    $rows = DB::table('password_resets')->get();

    expect($rows)->toHaveCount(1)
        ->and((string) $rows[0]->reset_code)->not->toBe($first);
});

it('refuses something that is not an address', function () {
    ($this->ask)('not-an-address')->assertStatus(400)->assertJsonPath('status', false);

    Mail::assertNothingSent();
});

it('still answers when the mail server is down', function () {
    // The row is written either way; somebody who did not get the email asks
    // again. What must not happen is a 500 that tells them the address exists.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));

    ($this->ask)('member@example.com')->assertOk()->assertJsonPath('status', true);

    expect(DB::table('password_resets')->count())->toBe(1);
});

it('is rate limited', function () {
    for ($i = 0; $i < 10; $i++) {
        ($this->ask)('member@example.com');
    }

    ($this->ask)('member@example.com')->assertStatus(429);
});
