<?php

use App\Models\Account;
use App\Models\InstallSecret;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Testing\TestResponse;

use function Tests\Support\signAs;

/**
 * `bootstrap_secret.php` — the one endpoint where holding a token turns into
 * holding a signing key, and therefore the one that decides whether the live
 * server's placeholder JWT secret becomes this server's problem.
 *
 * Two things are under test here:
 *
 *  1. **the unique key.** `install_secrets` carries
 *     `UNIQUE (account_id, device_id)` in production. The fixture had a plain
 *     index until the real schema arrived, so the original rotation — revoke
 *     the old row, insert a new one — passed everything and would have thrown a
 *     duplicate-key error on the first real rotation.
 *  2. **token provenance.** A token signed with the legacy secret may read an
 *     existing binding and nothing else, because anybody can mint one.
 */
beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $this->account->save();
    $this->deviceId = 'device-1';
});

/** A token this server issued, through a sign-in it verified. */
function serverToken(object $test): string
{
    return $test->account->createToken('Test device', ['*'], now()->addDays(30))->plainTextToken;
}

/** A token signed with the old server's secret — which anybody can mint. */
function legacyToken(object $test): string
{
    return LegacyJwt::make()->issue((int) $test->account->id)['access_token'];
}

function bootstrap(object $test, string $token, array $fields = []): TestResponse
{
    return $test->withHeader('Authorization', 'Bearer '.$token)
        ->post('/api/v1/android/19/bootstrap_secret.php', $fields + ['device_id' => $test->deviceId]);
}

// ----------------------------------------------------------- the normal path

it('issues a secret, then returns the same one', function () {
    $token = serverToken($this);
    $first = bootstrap($this, $token)->assertOk();

    expect($first->json('rotated'))->toBeFalse()
        ->and(strlen(base64_decode($first->json('secret'), true)))->toBe(32);

    // The client calls this on every launch and expects its key back, not a new
    // one — a new one would invalidate the requests already in flight.
    $second = bootstrap($this, $token)->assertOk();

    expect($second->json('secret'))->toBe($first->json('secret'))
        ->and($second->json('rotated'))->toBeFalse()
        ->and(InstallSecret::query()->count())->toBe(1);
});

it('rotates in place, because the table allows one row per device', function () {
    $token = serverToken($this);
    $first = bootstrap($this, $token)->assertOk()->json('secret');

    $rotated = bootstrap($this, $token, ['rotate' => true])->assertOk();

    expect($rotated->json('rotated'))->toBeTrue()
        ->and($rotated->json('secret'))->not->toBe($first)
        // The whole point: one row, replaced. Revoke-then-insert would be a
        // duplicate-key error against uq_account_device.
        ->and(InstallSecret::query()->count())->toBe(1)
        ->and(InstallSecret::query()->where('status', InstallSecret::ACTIVE)->count())->toBe(1);

    // And the new secret is the one that signs.
    $this->secret = base64_decode($rotated->json('secret'), true);
    $this->token = $token;
    signAs($this, '/api/v1/android/19/get_counts.php')->assertOk();
});

it('rotates repeatedly without ever leaving two rows', function () {
    $token = serverToken($this);
    bootstrap($this, $token);

    $seen = [];
    foreach (range(1, 5) as $i) {
        $seen[] = bootstrap($this, $token, ['rotate' => true])->assertOk()->json('secret');
    }

    expect(InstallSecret::query()->count())->toBe(1)
        ->and(array_unique($seen))->toHaveCount(5);
});

it('keeps a separate secret per device', function () {
    $token = serverToken($this);
    $one = bootstrap($this, $token)->json('secret');

    $this->deviceId = 'device-2';
    $two = bootstrap($this, $token)->json('secret');

    expect($two)->not->toBe($one)
        ->and(InstallSecret::query()->count())->toBe(2);

    // Rotating one leaves the other alone, which is what stops a new phone
    // signing the first one out.
    bootstrap($this, $token, ['rotate' => true]);

    $this->deviceId = 'device-1';
    expect(bootstrap($this, $token)->json('secret'))->toBe($one);
});

// -------------------------------------------------------------- provenance

it('refuses to register a new device for a legacy-signed token', function () {
    // Anybody can mint one of these: the live server's JWT secret is the
    // library's template placeholder. So this is the request that would turn a
    // forged token into a signing key, and it is refused.
    bootstrap($this, legacyToken($this))->assertStatus(403);

    expect(InstallSecret::query()->count())->toBe(0);
});

it('lets a legacy-signed token read a binding it already has', function () {
    // The real Android 1.9.0 case: the install already holds this key, so
    // handing it back tells the caller nothing it did not have.
    $mine = bootstrap($this, serverToken($this))->json('secret');

    $read = bootstrap($this, legacyToken($this))->assertOk();

    expect($read->json('secret'))->toBe($mine)
        ->and($read->json('rotated'))->toBeFalse();
});

it('refuses to rotate for a legacy-signed token', function () {
    $mine = bootstrap($this, serverToken($this))->json('secret');

    bootstrap($this, legacyToken($this), ['rotate' => true])->assertStatus(403);

    // And the real key is untouched.
    expect(bootstrap($this, serverToken($this))->json('secret'))->toBe($mine);
});

it('refuses a legacy-signed token for a device it cannot name', function () {
    bootstrap($this, serverToken($this));

    // A forger would have to guess a real install's device id, which only ever
    // exists on that phone.
    $this->deviceId = 'a-device-the-forger-guessed';
    bootstrap($this, legacyToken($this))->assertStatus(403);

    expect(InstallSecret::query()->count())->toBe(1);
});

it('can be relaxed in one line for the cutover window', function () {
    putenv('LEGACY_TOKENS_MAY_BOOTSTRAP=true');

    try {
        bootstrap($this, legacyToken($this))->assertOk();
        expect(InstallSecret::query()->count())->toBe(1);
    } finally {
        putenv('LEGACY_TOKENS_MAY_BOOTSTRAP');
    }
});

// ------------------------------------------------------------------ refusals

it('refuses a device id longer than the column', function () {
    // varchar(128). A truncated device id signs requests that verify against
    // the wrong row, which is a failure nobody would trace back to a length.
    bootstrap($this, serverToken($this), ['device_id' => str_repeat('d', 129)])->assertStatus(400);

    expect(InstallSecret::query()->count())->toBe(0);
});

it('refuses with no device id', function () {
    bootstrap($this, serverToken($this), ['device_id' => ''])->assertStatus(400);

    expect(InstallSecret::query()->count())->toBe(0);
});

it('refuses without a token, because you cannot sign before you hold the key', function () {
    $this->post('/api/v1/android/19/bootstrap_secret.php', ['device_id' => 'x'])->assertStatus(401);

    expect(InstallSecret::query()->count())->toBe(0);
});

it('refuses an expired token this server issued', function () {
    $expired = $this->account->createToken('Old phone', ['*'], now()->subDay())->plainTextToken;

    bootstrap($this, $expired)->assertStatus(401);

    expect(InstallSecret::query()->count())->toBe(0);
});
