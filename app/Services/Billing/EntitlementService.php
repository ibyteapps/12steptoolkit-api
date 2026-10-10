<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\ComplimentaryGrant;
use App\Models\Entitlement;
use App\Models\StoreSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single answer to "is this person premium?", and the only thing allowed to
 * write it.
 *
 * ## Why this class exists at all
 *
 * There are three sources, and during the overlap all three are legitimate:
 *
 *  * **store subscriptions this server verified** — Apple and Google, the future;
 *  * **RevenueCat** — read-only, and the only source that knows about every
 *    subscription sold before this server existed, which is all of them;
 *  * **complimentary grants** — given from the console.
 *
 * Three sources for one boolean is ordinarily a bug. What makes it safe is one
 * rule, and it is the whole reason this class is not just a query:
 *
 * > **No source may take away access that another source still grants.**
 *
 * The resolved entitlement is the **most generous** of the three. A RevenueCat
 * webhook arriving late cannot revoke a subscription this server has just
 * verified with Apple; a verification failure cannot revoke what RevenueCat
 * still says is live; and neither can revoke a grant somebody made in the
 * console. The only thing that revokes immediately is a refund, because that is
 * the store telling us the money went back.
 *
 * Get this wrong in the generous direction and somebody has premium for a few
 * extra days. Get it wrong in the mean direction and somebody who is paying
 * loses the app in the middle of their Fourth Step. The asymmetry is not close.
 *
 * ## What it does not do
 *
 * It never clears `accounts.subscribed`. That column is read by both old apps,
 * no code path in the old system ever cleared it, and so there are people in the
 * field whose premium today rests on it having been set years ago. Clearing it
 * would take access away from people who have it — a product decision, not a
 * tidy-up, and it is written down in `docs/OPEN_QUESTIONS.md` rather than made
 * quietly here.
 */
class EntitlementService
{
    /**
     * Recompute an account's entitlement from every source and store the answer.
     *
     * Safe to call as often as you like: it is a pure function of the rows that
     * exist, so a webhook arriving twice produces the same row.
     */
    public function refresh(int $accountId): Entitlement
    {
        return DB::transaction(function () use ($accountId): Entitlement {
            $entitlement = Entitlement::query()->firstOrNew(['account_id' => $accountId]);

            $candidates = array_values(array_filter([
                $this->fromStore($accountId),
                $this->fromComplimentary($accountId),
                // A gift a sponsor bought for this member. R4: it survives the
                // sponsor's own subscription lapsing, because it was bought
                // outright and is not a seat on their plan.
                $this->fromGift($accountId),
                // RevenueCat's own answer, as last recorded. Kept in its own
                // column so it can be read, audited and switched off without
                // disturbing the store-verified state beside it.
                $this->fromRevenueCat($entitlement),
            ]));

            $winner = $this->mostGenerous($candidates);

            $entitlement->forceFill($winner === null
                ? $this->noAccess()
                : $winner,
            );

            $entitlement->synced_at = now();
            $entitlement->save();

            // Additive only: granted premium is written through to the column
            // the old apps read, so somebody who buys on 2.0 and then opens
            // 1.9.0 is not told they are not subscribed. It is never cleared.
            if ($entitlement->is_active) {
                $this->markLegacyColumn($accountId);
            }

            return $entitlement;
        });
    }

    /** Does this account have access right now, without recomputing? */
    public function isActive(int $accountId): bool
    {
        $row = Entitlement::query()->find($accountId);

        if ($row === null) {
            return false;
        }

        if (! $row->is_active) {
            return false;
        }

        // The stored row can be stale — an expiry is a date passing, not an
        // event anybody sends. So the date is re-read rather than trusted.
        return $row->expires_at === null || $row->expires_at->isFuture()
            || ($row->grace_period_expires_at?->isFuture() ?? false);
    }

    /**
     * Record RevenueCat's answer for an account.
     *
     * Deliberately dumb: it stores what RevenueCat said and recomputes. It does
     * not decide anything, because the bridge is read-only and the decision is
     * `mostGenerous()`'s.
     *
     * @param  array{is_active: bool, product_id?: ?string, expires_at?: ?string, will_renew?: bool, state?: ?string}  $answer
     */
    public function recordRevenueCat(int $accountId, array $answer): Entitlement
    {
        $entitlement = Entitlement::query()->firstOrNew(['account_id' => $accountId]);
        $entitlement->forceFill([
            'revenuecat' => $answer + ['recorded_at' => now()->toIso8601String()],
            'last_event_at' => now(),
        ])->save();

        return $this->refresh($accountId);
    }

    // ------------------------------------------------------------- the sources

    /** The best store subscription this server has verified. */
    private function fromStore(int $accountId): ?array
    {
        $subscriptions = StoreSubscription::query()
            ->where('account_id', $accountId)
            ->get()
            ->filter(fn (StoreSubscription $s) => $s->grantsAccess())
            // A purchase this server cannot map to subscriber access grants
            // nothing *from this server*: an id it has never seen, a sponsee
            // gift (which belongs to somebody else), or a product whose
            // entitlement is still unresolved. The person keeps their access
            // through RevenueCat meanwhile, so nobody is locked out by a
            // product id nobody has confirmed yet.
            //
            // `grantsSubscriberAccess`, not `! isUnmapped`: see that method for
            // why the difference matters.
            ->filter(fn (StoreSubscription $s) => $s->grantsSubscriberAccess());

        if ($subscriptions->isEmpty()) {
            return null;
        }

        /** @var StoreSubscription $best */
        $best = $subscriptions->sortByDesc(fn (StoreSubscription $s) => $s->accessUntil()?->timestamp ?? PHP_INT_MAX)->first();

        return [
            'is_active' => true,
            'state' => match (true) {
                $best->status === 'grace_period' => 'grace_period',
                $best->status === 'cancelled' => 'cancelled',
                $best->started_with_trial && $best->purchased_at?->isAfter(now()->subMonth()) => 'trial',
                default => 'active',
            },
            'source' => $best->store,
            'product_id' => $best->product_id,
            'cycle' => $best->cycle,
            'will_renew' => (bool) $best->will_renew,
            'started_at' => $best->purchased_at,
            'expires_at' => $best->expires_at,
            'grace_period_expires_at' => $best->grace_period_expires_at,
        ];
    }

    /**
     * Months a sponsor gifted this member.
     *
     * The engine had three sources — the stores, a complimentary grant and
     * RevenueCat — and no gift, so a sponsee handed a seat was not premium
     * however many months had been bought for them. `SponseeGifts` holds the
     * two tables and the rules; this turns the answer into a candidate.
     */
    private function fromGift(int $accountId): ?array
    {
        $until = app(SponseeGifts::class)->accessUntil($accountId);

        if ($until === null || $until->isPast()) {
            return null;
        }

        return [
            'is_active' => true,
            'state' => 'active',
            'source' => 'sponsee_gift',
            'product_id' => null,
            'cycle' => null,
            // A consumable. Nothing renews; when the months are up they are up.
            'will_renew' => false,
            'started_at' => null,
            'expires_at' => $until,
            'grace_period_expires_at' => null,
        ];
    }

    private function fromComplimentary(int $accountId): ?array
    {
        $grant = ComplimentaryGrant::query()
            ->where('account_id', $accountId)
            ->grantingAccess()
            // A null end date is lifetime and beats every date.
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END DESC')
            ->orderByDesc('ends_at')
            ->first();

        if ($grant === null) {
            return null;
        }

        return [
            'is_active' => true,
            'state' => 'active',
            'source' => 'complimentary',
            'product_id' => null,
            'cycle' => null,
            'will_renew' => false,
            'started_at' => $grant->starts_at,
            'expires_at' => $grant->ends_at,
            'grace_period_expires_at' => null,
        ];
    }

    /**
     * RevenueCat's answer, while it is still allowed to grant one.
     *
     * Two flags, not one. `enabled` is whether this server listens to
     * RevenueCat at all; `grants_access` is whether what it hears may make
     * somebody premium. They separate because the cutover needs a middle
     * state: still ingesting (so the console stays truthful and a purchase
     * made in the old app is still seen), no longer granting (because the
     * import has been verified and this server now knows from the stores
     * directly).
     *
     * Turning both off together would un-subscribe everyone not yet imported.
     * See `config/billing.php` and `docs/ENTITLEMENT_RULES.md` §0.
     */
    private function fromRevenueCat(Entitlement $entitlement): ?array
    {
        if (! config('billing.revenuecat.enabled')) {
            return null;
        }

        if (! config('billing.revenuecat.grants_access')) {
            return null;
        }

        $answer = $entitlement->revenuecat;

        if (! is_array($answer) || ($answer['is_active'] ?? false) !== true) {
            return null;
        }

        $expires = isset($answer['expires_at']) && $answer['expires_at'] !== null
            ? Carbon::parse($answer['expires_at'])
            : null;

        // A RevenueCat answer that has already run out grants nothing. Its
        // staleness is handled by `revenuecat:reconcile`, not by trusting it
        // past its own date.
        if ($expires !== null && $expires->isPast()) {
            return null;
        }

        return [
            'is_active' => true,
            'state' => (string) ($answer['state'] ?? 'active'),
            'source' => 'revenuecat',
            'product_id' => $answer['product_id'] ?? null,
            'cycle' => $answer['cycle'] ?? null,
            'will_renew' => (bool) ($answer['will_renew'] ?? false),
            'started_at' => isset($answer['started_at']) ? Carbon::parse($answer['started_at']) : null,
            'expires_at' => $expires,
            'grace_period_expires_at' => null,
        ];
    }

    // ------------------------------------------------------------- the rule

    /**
     * The most generous candidate wins, and "generous" means the furthest access
     * date — with a null date (lifetime) beating every date.
     *
     * @param  list<array>  $candidates
     */
    private function mostGenerous(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $aUntil = $this->accessUntil($a);
            $bUntil = $this->accessUntil($b);

            if ($aUntil === null && $bUntil === null) {
                return 0;
            }

            // Lifetime first.
            if ($aUntil === null) {
                return -1;
            }

            if ($bUntil === null) {
                return 1;
            }

            return $bUntil->timestamp <=> $aUntil->timestamp;
        });

        return $candidates[0];
    }

    private function accessUntil(array $candidate): ?Carbon
    {
        $dates = array_filter([
            $candidate['expires_at'] ?? null,
            $candidate['grace_period_expires_at'] ?? null,
        ]);

        if ($dates === []) {
            return null;
        }

        return max(array_map(fn ($d) => $d instanceof Carbon ? $d : Carbon::parse($d), $dates));
    }

    private function noAccess(): array
    {
        return [
            'is_active' => false,
            'state' => 'none',
            'source' => null,
            'product_id' => null,
            'cycle' => null,
            'will_renew' => false,
            'expires_at' => null,
            'grace_period_expires_at' => null,
        ];
    }

    private function markLegacyColumn(int $accountId): void
    {
        $account = Account::query()->find($accountId);

        if ($account !== null && (int) $account->getRawOriginal('subscribed') !== 1) {
            // `forceFill`: the model guards everything, and this is a one-column
            // write into an adopted table.
            $account->forceFill(['subscribed' => 1])->save();
        }
    }
}
