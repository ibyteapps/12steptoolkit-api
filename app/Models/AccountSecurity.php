<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What this application knows about an account that `accounts` does not hold.
 *
 * One row per account, created on demand. See the migration for why it is a
 * table of its own rather than four columns on `accounts`.
 */
class AccountSecurity extends Model
{
    protected $table = 'account_security';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'account_id', 'uuid', 'first_v2_login_at', 'last_login_at',
        'v1_sealed_at', 'sync_consent_at', 'sync_consent_withdrawn_at',
        'deletion_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'first_v2_login_at' => 'datetime',
            'last_login_at' => 'datetime',
            'v1_sealed_at' => 'datetime',
            'sync_consent_at' => 'datetime',
            'sync_consent_withdrawn_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    /**
     * Whether the person has agreed to a copy of their writing being kept on
     * the server. Backup is free (D-001); it is still theirs to switch off.
     */
    public function hasAgreedToBackup(): bool
    {
        if ($this->sync_consent_at === null) {
            return false;
        }
        if ($this->sync_consent_withdrawn_at === null) {
            return true;
        }

        return $this->sync_consent_withdrawn_at->lt($this->sync_consent_at);
    }
}
