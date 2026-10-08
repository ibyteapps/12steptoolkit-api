<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One subscription as the store describes it.
 *
 * Keyed on `original_transaction_id` for Apple and on the purchase-token chain
 * for Google, because those are the only identifiers that survive a renewal, an
 * upgrade, a restore on a new phone and a re-download years later. A product id
 * does not: somebody who upgrades from quarterly to annual has one subscription
 * with two product ids, and treating that as two subscriptions is how a person
 * ends up paying twice or losing access on the switch.
 *
 * `account_id` is nullable on purpose. Both stores let a purchase complete
 * before anybody signs in, and the old system simply lost those — the money was
 * taken and nothing was recorded. Here the row exists unattached and is claimed
 * when the person signs in, which is also how a support person can find
 * "somebody paid and has no premium" instead of guessing.
 */
class StoreSubscription extends Model
{
    protected $guarded = ['id'];

    public const APPLE = 'apple';

    public const GOOGLE = 'google';

    /** Statuses that still let somebody in. A cancelled subscription runs to its end. */
    public const STATUSES_WITH_ACCESS = ['active', 'grace_period', 'cancelled'];

    protected function casts(): array
    {
        return [
            'is_sandbox' => 'bool',
            'started_with_trial' => 'bool',
            'will_renew' => 'bool',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_period_expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'state_signed_at' => 'datetime',
            'verified_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    /**
     * Does this subscription grant access right now?
     *
     * A refund revokes immediately — that is the one case where the store is
     * telling us the money came back and access should not continue. Everything
     * else runs to whichever end date is further out: `expires_at` or the grace
     * period, because during a billing-retry grace period Apple and Google both
     * expect the subscriber to keep their access while the card is retried.
     */
    public function grantsAccess(): bool
    {
        if ($this->refunded_at !== null) {
            return false;
        }

        if (! in_array($this->status, self::STATUSES_WITH_ACCESS, true)) {
            return false;
        }

        $until = $this->accessUntil();

        return $until === null || $until->isFuture();
    }

    public function accessUntil(): ?Carbon
    {
        $dates = array_filter([$this->expires_at, $this->grace_period_expires_at]);

        return $dates === [] ? null : max($dates);
    }

    /**
     * What this product grants, from `config/billing.php`. Null means unmapped.
     *
     * Looked up by array key rather than with `config('…products.'.$id)`,
     * because a product id contains dots and `config()` would read them as
     * nesting: `com.12stepapp.recoverybox.annual1` would become a walk through
     * four levels that do not exist, and every purchase would come back
     * unmapped.
     */
    public function mapping(): ?array
    {
        $products = (array) config("billing.{$this->store}.products", []);

        return $products[$this->product_id] ?? null;
    }

    /**
     * A purchase of a product this server does not know about at all.
     *
     * The console's question, not the resolver's: "have we ever seen this id?"
     * Recorded, shown, and granting nothing from this server, while the person
     * keeps their access through the RevenueCat bridge.
     */
    public function isUnmapped(): bool
    {
        return $this->mapping() === null;
    }

    /**
     * The `grants` values that make the **buyer** a subscriber.
     *
     * Deliberately a list of what does, not of what does not. Two of the three
     * values in `config/billing.php` do not:
     *
     *  * `sponsee_gift` — a consumable a sponsor buys *for somebody else*. It
     *    becomes a slot; it never makes the buyer premium.
     *  * `unresolved` — a product whose entitlement is not yet known. The three
     *    2024 à-la-carte unlocks (`steps8and9`, `steps10and11`,
     *    `otherfeatures`) are in this state pending one fact from the
     *    RevenueCat dashboard; `docs/OPEN_QUESTIONS.md` C5a.
     */
    public const GRANTS_SUBSCRIBER_ACCESS = ['subscription'];

    /**
     * Does this row make its owner a subscriber *according to this server*?
     *
     * This exists because the test it replaces was `! isUnmapped()`, which asks
     * whether the product id appears in the config at all — and "appears in the
     * config" is not "grants access". Adding the three unresolved 2024 products
     * to that list, so that the console could name them, would have made them
     * mapped, and a mapped lifetime purchase with no expiry granted premium.
     * Encoding "we have not decided yet" as *absence from a list* makes it
     * indistinguishable from "we decided it grants nothing", and the two need
     * different answers from a resolver whose whole rule is to err generously.
     */
    public function grantsSubscriberAccess(): bool
    {
        $grants = $this->mapping()['grants'] ?? null;

        return $grants !== null
            && in_array($grants, self::GRANTS_SUBSCRIBER_ACCESS, true);
    }

    public function scopeGrantingAccess(Builder $query): Builder
    {
        return $query->whereNull('refunded_at')
            ->whereIn('status', self::STATUSES_WITH_ACCESS)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
                    ->orWhere('grace_period_expires_at', '>', now());
            });
    }

    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_sandbox', false);
    }
}
