<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * One row per signed request, so a captured one cannot be sent twice.
 *
 * The UNIQUE key is the whole mechanism. The DDL in the security patch set
 * says so in a comment, and it is right: without it an `INSERT IGNORE` accepts
 * every replay and the table becomes a log of attacks rather than a defence.
 */
class RequestNonce extends Model
{
    protected $table = 'request_nonces';

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * Records a nonce. False means it has been seen before.
     *
     * The uniqueness is enforced by the index, not by a SELECT first: two
     * requests racing would both pass a check-then-insert.
     */
    public static function claim(int $accountId, string $deviceId, string $nonce, int $tsSec): bool
    {
        try {
            static::query()->insert([
                'account_id' => $accountId,
                'device_id' => mb_substr($deviceId, 0, 191),
                'nonce' => mb_substr($nonce, 0, 64),
                'ts_sec' => $tsSec,
            ]);
        } catch (QueryException $e) {
            // 23000/23505: a duplicate on `uniq_nonce`. Anything else is a real
            // database problem and must not be read as "replay".
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** Drops nonces older than the window; nothing can be replayed by then. */
    public static function sweep(): int
    {
        $cutoff = time() - (int) config('legacy.android.nonce_retention_seconds', 3600);

        return static::query()->where('ts_sec', '<', $cutoff)->delete();
    }
}
