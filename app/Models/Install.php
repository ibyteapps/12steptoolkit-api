<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One app install.
 *
 * The token is generated once, handed back once, and only its SHA-256 is kept —
 * so a copy of this table is not a set of working credentials, and a support
 * person looking at somebody's devices is not looking at a key to them.
 */
class Install extends Model
{
    protected $table = 'installs';

    protected $fillable = [
        'uuid', 'token_hash', 'account_id', 'platform', 'name', 'app_version',
        'os_version', 'push_token', 'push_token_hash', 'push_token_at',
        'push_enabled', 'timezone', 'language', 'last_seen_at',
    ];

    protected $hidden = ['token_hash', 'push_token', 'push_token_hash'];

    protected function casts(): array
    {
        return [
            'account_id' => 'int',
            'push_enabled' => 'bool',
            'push_token_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    /**
     * Records that this install is alive, at fifteen-minute granularity.
     *
     * Writing on every request would turn a read-mostly table into a write on
     * every API call, which is what `8/inserter_updater.php` does.
     */
    public function touchSeen(?string $appVersion = null, int|string|null $accountId = null): void
    {
        $changes = [];

        if ($this->last_seen_at === null || $this->last_seen_at->diffInMinutes(now()) >= 15) {
            $changes['last_seen_at'] = now();
        }
        if ($appVersion !== null && $appVersion !== '' && $appVersion !== $this->app_version) {
            $changes['app_version'] = mb_substr($appVersion, 0, 32);
        }
        if ($accountId !== null && (int) $accountId !== (int) $this->account_id) {
            $changes['account_id'] = (int) $accountId;
        }

        if ($changes !== []) {
            $this->forceFill($changes)->save();
        }
    }
}
