<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-install HMAC key, over the adopted `install_secrets` table.
 *
 * `secret` is `BINARY(32)` — written by the old code with `UNHEX(?)` and read
 * back as raw bytes straight into `hash_hmac`. It is never cast, never
 * stringified for a log and never returned to anybody except the install that
 * is bootstrapping, once.
 *
 * `19/bootstrap_secret.php` hands the raw secret back on **every** call, not
 * only when it rotates. This application returns an existing secret as well,
 * because the shipped client expects it to — but it is one of the first things
 * to change when the field is only running 2.0.
 */
class InstallSecret extends Model
{
    protected $table = 'install_secrets';

    public $timestamps = false;

    protected $guarded = ['*'];

    public const ACTIVE = 1;

    public const REVOKED = 0;

    /** The raw 32 bytes for a live install, or null. */
    public static function activeSecret(int $accountId, string $deviceId): ?string
    {
        $row = static::query()
            ->where('account_id', $accountId)
            ->where('device_id', $deviceId)
            ->where('status', self::ACTIVE)
            ->first();

        if ($row === null) {
            return null;
        }

        $secret = $row->getRawOriginal('secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
