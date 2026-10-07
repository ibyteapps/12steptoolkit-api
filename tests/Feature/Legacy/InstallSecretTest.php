<?php

use App\Models\Account;
use App\Models\InstallSecret;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Testing\TestResponse;

use function Tests\Support\signAs;

/**
 * `bootstrap_secret.php` — and the unique key that nearly broke it.
 *
 * `install_secrets` carries `UNIQUE (account_id, device_id)` in production. The
 * test fixture had a plain index until the real schema arrived, so the original
 * implementation — mark the old row revoked, insert a new one — passed
 * everything here and would have thrown a duplicate-key error on the first real
 * rotation. These tests hold the fixed behaviour to the constraint.
 */
beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $this->account->save();
    $this->deviceId = 'device-1';
    $this->token = LegacyJwt::make()->issue((int) $this->account->id)['access_token'];
});

function bootstrap(object $test, array $fields = []): TestResponse
{
    return $test->withHeader('Authorization', 'Bearer '.$test->token)
        ->post('/api/v1/android/19/bootstrap_secret.php', $fields + ['device_id' => $test->deviceId]);
}

it('issues a secret, then returns the same one', function () {
    $first = bootstrap($this)->assertOk();

    expect($first->json('rotated'))->toBeFalse()
        ->and(strlen(base64_decode($first->json('secret'), true)))->toBe(32);

    // The shipped client calls this on every launch and expects its key back,
    // not a new one — a new one would invalidate the requests already in flight.
    $second = bootstrap($this)->assertOk();

    expect($second->json('secret'))->toBe($first->json('secret'))
        ->and($second->json('rotated'))->toBeFalse()
        ->and(InstallSecret::query()->count())->toBe(1);
});

it('rotates in place, because the table allows one row per device', function () {
    $first = bootstrap($this)->assertOk()->json('secret');

    $rotated = bootstrap($this, ['rotate' => true])->assertOk();

    expect($rotated->json('rotated'))->toBeTrue()
        ->and($rotated->json('secret'))->not->toBe($first)
        // The whole point: one row, replaced. Revoke-then-insert would be a
        // duplicate-key error against uq_account_device.
        ->and(InstallSecret::query()->count())->toBe(1)
        ->and(InstallSecret::query()->where('status', InstallSecret::ACTIVE)->count())->toBe(1);

    // And the new secret is the one that signs.
    $this->secret = base64_decode($rotated->json('secret'), true);
    signAs($this, '/api/v1/android/19/get_counts.php')->assertOk();
});

it('rotates repeatedly without ever leaving two rows', function () {
    bootstrap($this);

    $seen = [];
    foreach (range(1, 5) as $i) {
        $seen[] = bootstrap($this, ['rotate' => true])->assertOk()->json('secret');
    }

    expect(InstallSecret::query()->count())->toBe(1)
        ->and(array_unique($seen))->toHaveCount(5);
});

it('keeps a separate secret per device', function () {
    $one = bootstrap($this)->json('secret');

    $this->deviceId = 'device-2';
    $two = bootstrap($this)->json('secret');

    expect($two)->not->toBe($one)
        ->and(InstallSecret::query()->count())->toBe(2);

    // Rotating one leaves the other alone, which is what stops a new phone
    // signing the first one out.
    bootstrap($this, ['rotate' => true]);

    $this->deviceId = 'device-1';
    expect(bootstrap($this)->json('secret'))->toBe($one);
});

it('refuses a device id longer than the column', function () {
    // varchar(128). A truncated device id signs requests that verify against
    // the wrong row, which is a failure nobody would trace back to a length.
    bootstrap($this, ['device_id' => str_repeat('d', 129)])->assertStatus(400);

    expect(InstallSecret::query()->count())->toBe(0);
});

it('refuses with no device id', function () {
    bootstrap($this, ['device_id' => ''])->assertStatus(400);

    expect(InstallSecret::query()->count())->toBe(0);
});

it('refuses without a token, because you cannot sign before you hold the key', function () {
    $this->post('/api/v1/android/19/bootstrap_secret.php', ['device_id' => 'x'])->assertStatus(401);

    expect(InstallSecret::query()->count())->toBe(0);
});
