<?php

use App\Models\Account;
use App\Models\InstallSecret;
use App\Models\RequestNonce;
use App\Services\Legacy\CanonicalRequest;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Testing\TestResponse;

/**
 * The Android authentication, end to end, against the signature the shipped
 * clients actually produce.
 *
 * If one of these breaks, every signed request from every install in the field
 * breaks with it.
 */
beforeEach(function () {
    // `forceFill`, not `create`: the model guards everything, because nothing
    // should be able to mass-assign into the table that holds somebody's
    // recovery.
    $this->account = new Account;
    $this->account->forceFill([
        'email' => 'someone@example.com',
        'nickname' => ' ',
        'created' => now(),
    ])->save();
    $this->deviceId = 'device-abc';
    $this->secret = random_bytes(32);

    InstallSecret::query()->insert([
        'account_id' => $this->account->id,
        'device_id' => $this->deviceId,
        'secret' => $this->secret,
        'status' => InstallSecret::ACTIVE,
    ]);

    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];
});

/** Signs a form-encoded POST the way the clients do. */
function signedPost(object $test, string $path, array $fields = [], array $overrides = []): TestResponse
{
    $body = http_build_query($fields);
    $hash = CanonicalRequest::bodyHash($body);
    $ts = $overrides['ts'] ?? time();
    $nonce = $overrides['nonce'] ?? bin2hex(random_bytes(8));

    $canonical = CanonicalRequest::build('POST', $path, $hash, $ts, $nonce);
    $signature = CanonicalRequest::sign($canonical, $overrides['secret'] ?? $test->secret);

    return $test->call(
        'POST',
        $path,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_AUTHORIZATION' => 'Bearer '.($overrides['token'] ?? $test->token),
            'HTTP_X_ACCOUNT_ID' => (string) ($overrides['accountId'] ?? $test->account->id),
            'HTTP_X_DEVICE_ID' => $overrides['deviceId'] ?? $test->deviceId,
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_BODY_SHA256' => $hash,
            'HTTP_X_SIGNATURE' => $signature,
        ],
        $body,
    );
}

it('accepts a correctly signed request', function () {
    signedPost($this, '/api/v1/android/19/get_counts.php')
        ->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('response.inventories', 0);
});

it('refuses a request with no signature', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post('/api/v1/android/19/get_counts.php')
        ->assertStatus(401)
        ->assertJsonPath('status', false);
});

it('refuses a signature made with the wrong secret', function () {
    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['secret' => random_bytes(32)])
        ->assertStatus(401);
});

it('refuses a body that does not match its hash', function () {
    $body = http_build_query(['account_id' => $this->account->id]);
    $hash = CanonicalRequest::bodyHash($body);
    $ts = time();
    $nonce = 'n';
    $signature = CanonicalRequest::sign(
        CanonicalRequest::build('POST', '/api/v1/android/19/get_counts.php', $hash, $ts, $nonce),
        $this->secret,
    );

    // The signature is correct for the hash; the body is not the body it hashed.
    $this->call('POST', '/api/v1/android/19/get_counts.php', [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        'HTTP_X_ACCOUNT_ID' => (string) $this->account->id,
        'HTTP_X_DEVICE_ID' => $this->deviceId,
        'HTTP_X_TIMESTAMP' => (string) $ts,
        'HTTP_X_NONCE' => $nonce,
        'HTTP_X_BODY_SHA256' => $hash,
        'HTTP_X_SIGNATURE' => $signature,
    ], 'account_id=999999')->assertStatus(401);
});

it('refuses a timestamp outside the window', function () {
    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['ts' => time() - 400])
        ->assertStatus(401);
});

it('refuses a header account that disagrees with the token', function () {
    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['accountId' => $this->account->id + 1])
        ->assertStatus(401);
});

it('refuses the same request twice', function () {
    // `19/auth_checker.php` reads the nonce and throws it away, so this request
    // is replayable there for five minutes. Here it is not.
    $nonce = 'replay-me';

    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['nonce' => $nonce])->assertOk();
    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['nonce' => $nonce])->assertStatus(401);

    expect(RequestNonce::query()->count())->toBe(1);
});

it('does not spend a nonce on a bad signature', function () {
    $nonce = 'not-spent';

    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['nonce' => $nonce, 'secret' => random_bytes(32)])
        ->assertStatus(401);

    // A wrong guess must not be able to burn a real client's nonce.
    expect(RequestNonce::query()->count())->toBe(0);
    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['nonce' => $nonce])->assertOk();
});

it('refuses an unknown device', function () {
    signedPost($this, '/api/v1/android/19/get_counts.php', [], ['deviceId' => 'another-phone'])
        ->assertStatus(401);
});

it('answers the live Android path as well as the canonical one', function () {
    signedPost($this, '/12steptoolkit.com/aa/android/19/get_counts.php')->assertOk();
});
