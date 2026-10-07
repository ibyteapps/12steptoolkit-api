<?php

use App\Models\ConsoleUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * How somebody becomes staff, and the ways they must not be able to.
 *
 * The old system's back office was `19/admin/reported.php` — an
 * unauthenticated HTML page listing every user report with the reporter's id,
 * the reported person's id and the free-text reason. So "who can get in" is
 * worth more tests than the pages themselves.
 */
it('creates an account that cannot sign in until the link is used', function () {
    $this->artisan('console:user', ['email' => 'kim@example.com', '--name' => 'Kim'])
        ->assertSuccessful();

    $user = ConsoleUser::query()->where('email', 'kim@example.com')->sole();

    expect($user->name)->toBe('Kim')
        ->and($user->role)->toBe('support')
        ->and($user->active)->toBeTrue()
        // The whole point: created, and useless until the link is followed.
        ->and($user->password)->toBeNull();

    $this->post('/console/login', ['email' => 'kim@example.com', 'password' => 'anything at all'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('console');
});

it('stores the set-password token hashed, so the table is not a way in', function () {
    $this->artisan('console:user', ['email' => 'kim@example.com'])->assertSuccessful();

    $row = DB::table('console_password_reset_tokens')->where('email', 'kim@example.com')->sole();

    expect($row->token)->toStartWith('$2y$')
        ->and(strlen($row->token))->toBeLessThan(80);
});

it('sets a password and signs in, once', function () {
    [$user, $token] = invite('kim@example.com');

    $this->post("/console/set-password/{$token}", [
        'email' => 'kim@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertRedirect(route('console.home'));

    $this->assertAuthenticatedAs($user->fresh(), 'console');
    expect(Hash::check('correct horse battery', $user->fresh()->password))->toBeTrue()
        // Single use.
        ->and(DB::table('console_password_reset_tokens')->count())->toBe(0);

    // And the same link again does nothing.
    $this->post('/console/logout');
    $this->post("/console/set-password/{$token}", [
        'email' => 'kim@example.com',
        'password' => 'a different long one',
        'password_confirmation' => 'a different long one',
    ])->assertSessionHasErrors('password');
});

it('refuses a link that has expired', function () {
    [, $token] = invite('kim@example.com');

    $this->travel(config('console.password_link_minutes') + 1)->minutes();

    $this->post("/console/set-password/{$token}", [
        'email' => 'kim@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertSessionHasErrors('password');

    $this->assertGuest('console');
});

it('refuses a link belonging to somebody else', function () {
    [, $kimsToken] = invite('kim@example.com');
    invite('sam@example.com');

    // Sam's address, Kim's token.
    $this->post("/console/set-password/{$kimsToken}", [
        'email' => 'sam@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertSessionHasErrors('password');

    $this->assertGuest('console');
    expect(ConsoleUser::query()->whereNotNull('password')->count())->toBe(0);
});

it('refuses a short password', function () {
    [, $token] = invite('kim@example.com');

    $this->post("/console/set-password/{$token}", [
        'email' => 'kim@example.com', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(DB::table('console_password_reset_tokens')->count())->toBe(1);
});

it('will not create the same account twice without --reset', function () {
    $this->artisan('console:user', ['email' => 'kim@example.com'])->assertSuccessful();
    $this->artisan('console:user', ['email' => 'kim@example.com'])->assertFailed();

    expect(ConsoleUser::query()->count())->toBe(1);
});

it('clears the old password on --reset, so a compromised one stops working', function () {
    [$user, $token] = invite('kim@example.com');
    $this->post("/console/set-password/{$token}", [
        'email' => 'kim@example.com', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery',
    ]);
    $this->post('/console/logout');

    $this->artisan('console:user', ['email' => 'kim@example.com', '--reset' => true])->assertSuccessful();

    expect($user->fresh()->password)->toBeNull();

    $this->post('/console/login', ['email' => 'kim@example.com', 'password' => 'correct horse battery'])
        ->assertSessionHasErrors('email');
});

it('deactivates without deleting, so the audit trail keeps its author', function () {
    [$user, $token] = invite('kim@example.com');
    $this->post("/console/set-password/{$token}", [
        'email' => 'kim@example.com', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery',
    ]);
    $this->post('/console/logout');

    $this->artisan('console:user', ['email' => 'kim@example.com', '--deactivate' => true])->assertSuccessful();

    expect(ConsoleUser::query()->count())->toBe(1)
        ->and($user->fresh()->active)->toBeFalse()
        ->and(DB::table('console_password_reset_tokens')->count())->toBe(0);

    $this->post('/console/login', ['email' => 'kim@example.com', 'password' => 'correct horse battery'])
        ->assertSessionHasErrors('email');
});

it('refuses a role it does not recognise', function () {
    $this->artisan('console:user', ['email' => 'kim@example.com', '--role' => 'root'])
        ->assertExitCode(2);

    expect(ConsoleUser::query()->count())->toBe(0);
});

it('keeps the overview behind the login', function () {
    $this->get('/console')->assertRedirect(route('console.login'));
});

/**
 * Runs `console:user` and digs the one-time token back out.
 *
 * The command prints the link rather than returning it, so the test reads the
 * printed output — which also means the printed link is covered: if the route
 * and the printed path ever disagree, these tests fail.
 *
 * @return array{0: ConsoleUser, 1: string}
 */
function invite(string $email): array
{
    expect(Artisan::call('console:user', ['email' => $email]))->toBe(0);

    preg_match('#/console/set-password/([A-Za-z0-9]+)#', Artisan::output(), $m);
    expect($m[1] ?? null)->not->toBeNull('the command did not print a set-password link');

    return [ConsoleUser::query()->where('email', $email)->sole(), $m[1]];
}
