<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one row that answers "is this person premium?".
 *
 * `accounts.subscribed` cannot answer it: no code path in either old API ever
 * clears it, and there is no webhook anywhere in the old system, so a
 * cancellation, a refund or an expiry is never learned. It answers "has this
 * person ever paid", which is a different question, and it is left alone
 * because the old apps read it.
 *
 * Two sources write this row — store subscriptions verified by this server, and
 * RevenueCat for the old apps — and the rule that keeps that safe lives in
 * `EntitlementService`: neither may take away access the other still grants.
 */
class Entitlement extends Model
{
    protected $table = 'entitlements';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    /** States that still let somebody in. A cancelled subscription runs to its end. */
    public const STATES_WITH_ACCESS = ['active', 'trial', 'grace_period', 'cancelled'];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'will_renew' => 'bool',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_period_expires_at' => 'datetime',
            'last_event_at' => 'datetime',
            'synced_at' => 'datetime',
            'revenuecat' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }
}
