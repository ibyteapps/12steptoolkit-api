<?php

use App\Models\Account;
use App\Models\AccountSecurity;
use App\Models\SignIn;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Support\Facades\Hash;

it('creates an anonymous account and issues a token', function () {
    $response = $this->post('/api/v1/android/19/login_new_account.php', [
        'fcmToken' => 'fcm-1', 'timeZone' => 'Europe/London', 'language' => 'en',
    ])->assertOk()->assertJsonPath('status', true);

    $accountId = $response->json('response.account_id');
    expect($accountId)->toBeInt()->toBeGreaterThan(0);

    // The token works, and names that account.
    expect(LegacyJwt::make()->accountId($response->json('response.access_token')))->toBe($accountId);

    $account = Account::query()->find($accountId);
    expect($account->sociallogin)->toBe(Account::SOCIAL_ANONYMOUS);
    // A single space, as the old code writes it.
    expect($account->nickname)->toBe(' ');
    // `country` and `country_code` were swapped in the original defaults.
    expect($account->details->country)->toBe('United States');
    expect($account->details->countrycode)->toBe('US');
});

it('does not issue a nine-year token', function () {
    $response = $this->post('/api/v1/android/19/login_new_account.php', []);
    $ttl = $response->json('response.expires_at') - time();

    // `issue_jwt()` is called with 296,000,000 seconds by every legacy login.
    expect($ttl)->toBeLessThan(90 * 86400);
});

it('signs in with a password and upgrades a plaintext one to bcrypt', function () {
    $account = Account::make();
    $account->forceFill(['email' => 'p@example.com', 'password' => 'hunter2', 'nickname' => ' ', 'created' => now()])->save();

    $this->post('/api/v1/android/19/login_email.php', ['email' => 'P@Example.com', 'password' => 'hunter2'])
        ->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Logged In!');

    $account->refresh();
    expect(Hash::check('hunter2', $account->getRawOriginal('hashed_password')))->toBeTrue();
    // `keep` by default: blanking it is what breaks that person's iOS sign-in.
    expect($account->getRawOriginal('password'))->toBe('hunter2');
});

it('blanks the plaintext password when told to', function () {
    config(['legacy.plaintext_password' => 'clear']);
    $account = Account::make();
    $account->forceFill(['email' => 'p@example.com', 'password' => 'hunter2', 'nickname' => ' ', 'created' => now()])->save();

    $this->post('/api/v1/android/19/login_email.php', ['email' => 'p@example.com', 'password' => 'hunter2'])->assertOk();

    expect($account->refresh()->getRawOriginal('password'))->toBe('');
});

it('answers a wrong password with 200 and status false, as the clients expect', function () {
    $account = Account::make();
    $account->forceFill(['email' => 'p@example.com', 'password' => 'hunter2', 'nickname' => ' ', 'created' => now()])->save();

    $this->post('/api/v1/android/19/login_email.php', ['email' => 'p@example.com', 'password' => 'wrong'])
        ->assertOk()
        ->assertJsonPath('status', false);
});

it('refuses a Google sign-in that carries only an email address', function () {
    // `19/login_google.php` looks the address up and issues a nine-year token
    // for whatever account has it. Knowing somebody's email was the whole of
    // the authentication (SRV-010).
    $account = Account::make();
    $account->forceFill(['email' => 'g@example.com', 'nickname' => ' ', 'created' => now()])->save();

    $this->post('/api/v1/android/19/login_google.php', ['email' => 'g@example.com'])
        ->assertOk()
        ->assertJsonPath('status', false);
});

it('seals an account against the Apple path on a signed-in client', function () {
    $response = $this->post('/api/v1/android/19/login_new_account.php', []);
    $accountId = $response->json('response.account_id');

    $security = AccountSecurity::query()->find($accountId);
    expect($security->v1_sealed_at)->not->toBeNull();
    expect(Account::query()->find($accountId)->isSealed())->toBeTrue();
});

it('records the sign-in without an address or a user agent', function () {
    $this->post('/api/v1/android/19/login_new_account.php', []);

    $row = SignIn::query()->first();
    expect($row->method)->toBe('anonymous');
    expect($row->getAttributes())->not->toHaveKey('ip_address');
});

it('will not hand a token to an account that has already been sealed', function () {
    $account = Account::make();
    $account->forceFill(['nickname' => ' ', 'created' => now()])->save();
    $account->securityRow()->forceFill(['v1_sealed_at' => now()])->save();

    $this->post('/api/v1/android/19/login_upgrade.php', ['account_id' => $account->id])
        ->assertStatus(404);
});

it('answers the same way for an unknown account id as for a sealed one', function () {
    // So that this cannot be used to find out which account ids exist.
    $unknown = $this->post('/api/v1/android/19/login_upgrade.php', ['account_id' => 987654]);
    $account = Account::make();
    $account->forceFill(['nickname' => ' ', 'created' => now()])->save();
    $account->securityRow()->forceFill(['v1_sealed_at' => now()])->save();
    $sealed = $this->post('/api/v1/android/19/login_upgrade.php', ['account_id' => $account->id]);

    expect($unknown->status())->toBe($sealed->status());
    expect($unknown->json('message'))->toBe($sealed->json('message'));
});
