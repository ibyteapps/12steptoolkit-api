<?php

use App\Mail\SignInCode;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Facades\Mail;

/*
 | The member area replaces web.12steptoolkit.com, whose endpoints took an
 | account id out of the request body and believed it. `getlist_working.php`
 | returned any account's records to anyone who asked; `deleterecord.php`
 | deleted any row by id, with no account in the WHERE clause at all.
 |
 | These tests exist to make that class of bug impossible to reintroduce
 | here, so most of them are about somebody else's records rather than about
 | one's own.
 */

beforeEach(function () {
    $this->me = Account::make()->forceFill([
        'nickname' => 'Jo', 'email' => 'jo@example.com', 'created' => now(),
    ]);
    $this->me->save();

    $this->other = Account::make()->forceFill([
        'nickname' => 'Sam', 'email' => 'sam@example.com', 'created' => now(),
    ]);
    $this->other->save();

    // StepRecord guards every column, so rows are written the way the rest
    // of the application writes them.
    $this->mine = Journal::make()->forceFill([
        'accountid' => $this->me->id, 'description' => 'My own entry',
        'tstamp' => time(), 'created' => now(),
    ]);
    $this->mine->save();

    $this->theirs = Journal::make()->forceFill([
        'accountid' => $this->other->id, 'description' => 'Not for me',
        'tstamp' => time(), 'created' => now(),
    ]);
    $this->theirs->save();
});

function signInAsMember(Account $account): void
{
    test()->actingAs($account, 'member');
}

it('sends a guest to the sign-in page rather than anywhere else', function () {
    $this->get('/my')->assertRedirect(route('my.sign-in'));
    $this->get('/my/journals')->assertRedirect(route('my.sign-in'));
});

it('keeps the member area out of search results and out of caches', function () {
    signInAsMember($this->me);

    $this->get('/my')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private');
});

/*
 | The old page said "No account with that email", which turns a login form
 | into a way of confirming that a given person is in A.A.
 */
it('does not reveal whether an address has an account', function () {
    Mail::fake();

    $known = $this->post(route('my.sign-in.request'), ['email' => 'jo@example.com']);
    $unknown = $this->post(route('my.sign-in.request'), ['email' => 'nobody@example.com']);

    // Same status code and the same words either way.
    expect($unknown->getStatusCode())->toBe($known->getStatusCode());
    $known->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'If that address has an account'));
    $unknown->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'If that address has an account'));

    // One code sent, to the address that has an account.
    Mail::assertSent(SignInCode::class, 1);
    Mail::assertSent(SignInCode::class, fn (SignInCode $mail): bool => $mail->hasTo('jo@example.com'));
});

it('signs in with the emailed code and not with a wrong one', function () {
    Mail::fake();
    $this->post(route('my.sign-in.request'), ['email' => 'jo@example.com']);

    $code = null;
    Mail::assertSent(SignInCode::class, function (SignInCode $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    expect($code)->toMatch('/^\d{4}$/');

    $wrong = $code === '0000' ? '1111' : '0000';
    $this->post(route('my.sign-in.verify'), ['code' => $wrong]);
    expect(auth('member')->check())->toBeFalse();

    $this->post(route('my.sign-in.verify'), ['code' => $code])->assertRedirect(route('my.home'));
    expect(auth('member')->id())->toBe($this->me->id);
});

it('throws the code away after too many wrong attempts', function () {
    Mail::fake();
    $this->post(route('my.sign-in.request'), ['email' => 'jo@example.com']);

    $code = null;
    Mail::assertSent(SignInCode::class, function (SignInCode $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    $wrong = $code === '0000' ? '1111' : '0000';
    for ($i = 0; $i < (int) config('my.code_attempts'); $i++) {
        $this->post(route('my.sign-in.verify'), ['code' => $wrong]);
    }

    // Even the right code is no good now.
    $this->post(route('my.sign-in.verify'), ['code' => $code]);
    expect(auth('member')->check())->toBeFalse();
});

it('lists only the signed-in account\'s records', function () {
    signInAsMember($this->me);

    $this->get('/my/journals')
        ->assertOk()
        ->assertSee('My own entry')
        ->assertDontSee('Not for me');
});

/*
 | The one that matters. `getlist_working.php` would have returned this.
 */
it('will not show another account\'s record', function () {
    signInAsMember($this->me);

    $this->get('/my/journals/'.$this->theirs->id)->assertNotFound();
    $this->get('/my/journals/'.$this->mine->id)->assertOk()->assertSee('My own entry');
});

/*
 | And the one that lost data. `deleterecord.php` deleted by id alone.
 */
it('will not delete another account\'s record', function () {
    signInAsMember($this->me);

    $this->delete('/my/journals/'.$this->theirs->id)->assertNotFound();

    expect(Journal::query()->whereKey($this->theirs->id)->exists())->toBeTrue();
});

it('deletes the member\'s own record', function () {
    signInAsMember($this->me);

    $this->delete('/my/journals/'.$this->mine->id)
        ->assertRedirect(route('my.records.index', 'journals'));

    expect(Journal::query()->whereKey($this->mine->id)->exists())->toBeFalse()
        ->and(Journal::query()->whereKey($this->theirs->id)->exists())->toBeTrue();
});

/*
 | The old endpoints turned a number from the request into a table name with a
 | switch statement. Here the slug has to be one this application published.
 */
it('404s a list type it does not publish', function () {
    signInAsMember($this->me);

    $this->get('/my/accounts')->assertNotFound();
    $this->get('/my/../console/accounts')->assertNotFound();
});

it('signs out and stops being able to read anything', function () {
    signInAsMember($this->me);

    $this->post(route('my.sign-out'))->assertRedirect(route('my.sign-in'));
    expect(auth('member')->check())->toBeFalse();
});
