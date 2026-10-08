<?php

use App\Models\Account;
use App\Models\ComplimentaryGrant;
use App\Models\Entitlement;
use App\Models\StoreSubscription;
use App\Services\Billing\EntitlementService;

/**
 * The one rule: no source may take away access that another source still
 * grants.
 *
 * These tests are written in the mean direction on purpose — each one sets up a
 * situation where a naive "last writer wins" implementation would revoke
 * somebody's access, and asserts that it does not. The failure this guards
 * against is a paying subscriber losing the app in the middle of their Fourth
 * Step because a webhook arrived out of order.
 */
beforeEach(function () {
    $this->account = Account::make()->forceFill(['nickname' => ' ', 'created' => now()]);
    $this->account->save();
    $this->service = app(EntitlementService::class);

    // The bridge is off by default in `phpunit.xml`, so that no test reaches
    // RevenueCat by accident. The tests below that are *about* the bridge turn
    // it on deliberately.
    config(['billing.revenuecat.enabled' => true]);
});

function appleSub(int $accountId, array $attributes = []): StoreSubscription
{
    $sub = new StoreSubscription;
    $sub->forceFill(array_merge([
        'store' => StoreSubscription::APPLE,
        'account_id' => $accountId,
        'original_transaction_id' => 'otx-'.bin2hex(random_bytes(4)),
        'product_id' => 'com.12stepapp.recoverybox.annual1',
        'cycle' => 'annual',
        'status' => 'active',
        'will_renew' => true,
        'purchased_at' => now()->subMonth(),
        'expires_at' => now()->addYear(),
        'verified_at' => now(),
    ], $attributes))->save();

    return $sub;
}

/**
 * The Google equivalent. The product ids are bare — `annual`, `profeatures` —
 * where Apple's are reverse-DNS, which is why `StoreSubscription::mapping()`
 * reads the config by array key rather than through `config()`'s dot notation.
 */
function googleSub(int $accountId, array $attributes = []): StoreSubscription
{
    $sub = new StoreSubscription;
    $sub->forceFill(array_merge([
        'store' => StoreSubscription::GOOGLE,
        'account_id' => $accountId,
        'original_transaction_id' => 'gtx-'.bin2hex(random_bytes(4)),
        'product_id' => 'annual',
        'cycle' => 'annual',
        'status' => 'active',
        'will_renew' => true,
        'purchased_at' => now()->subMonth(),
        'expires_at' => now()->addYear(),
        'verified_at' => now(),
    ], $attributes))->save();

    return $sub;
}

it('grants nothing when there is nothing', function () {
    $row = $this->service->refresh($this->account->id);

    expect($row->is_active)->toBeFalse()
        ->and($row->state)->toBe('none')
        ->and($row->source)->toBeNull()
        ->and($this->service->isActive($this->account->id))->toBeFalse();
});

it('grants a verified store subscription', function () {
    appleSub($this->account->id);

    $row = $this->service->refresh($this->account->id);

    expect($row->is_active)->toBeTrue()
        ->and($row->state)->toBe('active')
        ->and($row->source)->toBe('apple')
        ->and($row->cycle)->toBe('annual')
        ->and($row->will_renew)->toBeTrue();
});

it('does not let a stale RevenueCat answer revoke a verified subscription', function () {
    // Apple says: paid until next year. This is the order things actually
    // arrive in — a verification, then a RevenueCat webhook from before it.
    appleSub($this->account->id);
    $this->service->refresh($this->account->id);

    $row = $this->service->recordRevenueCat($this->account->id, [
        'is_active' => false,
        'state' => 'expired',
        'expires_at' => now()->subDay()->toIso8601String(),
    ]);

    expect($row->is_active)->toBeTrue()
        ->and($row->source)->toBe('apple')
        ->and($row->expires_at->isNextYear())->toBeTrue();
});

it('does not let a missing store subscription revoke what RevenueCat still grants', function () {
    // The reverse case: every subscription sold before this server existed is
    // only visible through RevenueCat, so "no store row" must never mean "no
    // access" during the overlap.
    $row = $this->service->recordRevenueCat($this->account->id, [
        'is_active' => true,
        'state' => 'active',
        'product_id' => 'legacy_annual',
        'expires_at' => now()->addMonths(6)->toIso8601String(),
        'will_renew' => true,
    ]);

    expect($row->is_active)->toBeTrue()
        ->and($row->source)->toBe('revenuecat')
        ->and($this->service->isActive($this->account->id))->toBeTrue();
});

it('keeps whichever source reaches further', function () {
    appleSub($this->account->id, ['expires_at' => now()->addMonth()]);
    $this->service->recordRevenueCat($this->account->id, [
        'is_active' => true,
        'expires_at' => now()->addYear()->toIso8601String(),
    ]);

    $row = $this->service->refresh($this->account->id);

    expect($row->source)->toBe('revenuecat')
        ->and(now()->diffInDays($row->expires_at))->toBeGreaterThan(300);
});

it('lets a complimentary grant outrank both', function () {
    appleSub($this->account->id, ['expires_at' => now()->addMonth()]);
    $this->service->recordRevenueCat($this->account->id, ['is_active' => true, 'expires_at' => now()->addMonths(2)->toIso8601String()]);

    (new ComplimentaryGrant)->forceFill([
        'account_id' => $this->account->id,
        'period' => 'lifetime',
        'reason' => 'A refund that went wrong',
        'starts_at' => now()->subDay(),
        'ends_at' => null,
    ])->save();

    $row = $this->service->refresh($this->account->id);

    // Lifetime beats every date.
    expect($row->source)->toBe('complimentary')
        ->and($row->expires_at)->toBeNull()
        ->and($this->service->isActive($this->account->id))->toBeTrue();
});

it('revokes immediately on a refund', function () {
    // The one case where the store is telling us the money went back.
    appleSub($this->account->id, ['refunded_at' => now(), 'status' => 'refunded']);

    $row = $this->service->refresh($this->account->id);

    expect($row->is_active)->toBeFalse()
        ->and($row->state)->toBe('none');
});

it('keeps access through a billing-retry grace period', function () {
    // Apple and Google both expect the subscriber to keep their access while
    // the card is retried, and the expiry date has already passed by then.
    appleSub($this->account->id, [
        'status' => 'grace_period',
        'expires_at' => now()->subDays(2),
        'grace_period_expires_at' => now()->addDays(14),
        'will_renew' => true,
    ]);

    $row = $this->service->refresh($this->account->id);

    expect($row->is_active)->toBeTrue()
        ->and($row->state)->toBe('grace_period')
        ->and($this->service->isActive($this->account->id))->toBeTrue();
});

it('runs a cancelled subscription to its end', function () {
    appleSub($this->account->id, ['status' => 'cancelled', 'will_renew' => false, 'cancelled_at' => now()]);

    $row = $this->service->refresh($this->account->id);

    expect($row->is_active)->toBeTrue()
        ->and($row->state)->toBe('cancelled')
        ->and($row->will_renew)->toBeFalse();
});

it('grants nothing for a retired à-la-carte unlock, and that is decided rather than unknown', function () {
    // `steps8and9`, `steps10and11` and `otherfeatures` sold about a decade ago
    // and grant nothing (ENTITLEMENT_RULES R2, owner's call 2026-10-08,
    // reversing an earlier decision to grant them premium).
    //
    // They are `none`, not absent and not `unresolved`. The gate used to be
    // `! isUnmapped()` — is the id in the list — so listing them at all would
    // have made them mapped, and a mapped lifetime purchase with no expiry
    // granted premium. "Decided to grant nothing" and "not yet decided" have
    // to be different values, and both have to be different from "never heard
    // of it", because only the middle one is a question still open.
    foreach (['steps8and9', 'steps10and11', 'otherfeatures'] as $productId) {
        $sub = googleSub($this->account->id, [
            'product_id' => $productId,
            'cycle' => 'lifetime',
            'expires_at' => null,
        ]);

        expect($sub->isUnmapped())->toBeFalse("$productId should be known");
        expect($sub->grantsSubscriberAccess())->toBeFalse("$productId must not grant");
        expect(config('billing.google.products')[$productId]['grants'])->toBe('none');
        expect($this->service->refresh($this->account->id)->is_active)
            ->toBeFalse($productId);

        $sub->delete();
    }
});

it('still knows about a retired product, so the console can show the purchase', function () {
    // The reason they stay in config at all. The orders are in
    // `subscription_orders`, and if somebody ever does surface they are
    // granted by hand — which needs the console to be able to name what they
    // bought rather than showing an unmapped id.
    $sub = googleSub($this->account->id, ['product_id' => 'steps8and9']);

    expect($sub->mapping())->not->toBeNull();
    expect($sub->mapping()['label'])->toContain('Steps 8 & 9');
});

it('does not make the buyer of a sponsee gift a subscriber', function () {
    // A consumable bought *for somebody else*. It becomes a slot; it never
    // makes the buyer premium — and it is mapped, so the old presence-based
    // gate would have granted it.
    $sub = googleSub($this->account->id, [
        'product_id' => 'sponsee_1_annual',
        'cycle' => 'annual',
    ]);

    expect($sub->isUnmapped())->toBeFalse();
    expect($sub->grantsSubscriberAccess())->toBeFalse();
    expect($this->service->refresh($this->account->id)->is_active)->toBeFalse();
});

it('grants every price-refresh duplicate, for ever', function () {
    // Play will not let a product's price history be rewritten, so a price
    // change is a new id: `annual` / `annual_1999`, `quarterly` /
    // `quarterly_2025`, and four separate `profeatures*`. Everyone who bought
    // an old one is still entitled. Which ids are *offered* is a dashboard
    // question; which are *honoured* is this list, and it is all of them.
    foreach ([
        'annual', 'annual_1999', 'quarterly', 'quarterly_2025',
        'weekly', 'monthly', 'annual_3', 'quarterly_3',
        'profeatures', 'profeatures1499', 'profeatures2999', 'profeatures_discounted',
    ] as $productId) {
        $sub = googleSub($this->account->id, ['product_id' => $productId]);

        expect($sub->grantsSubscriberAccess())->toBeTrue($productId);
        expect($this->service->refresh($this->account->id)->is_active)
            ->toBeTrue($productId);

        $sub->delete();
    }
});

it('maps the sponsor bundles as plain subscriptions, since nobody can hold one', function () {
    // "Sponsor & 3 Sponsees" — not in RevenueCat at all, and the Android app
    // buys through RevenueCat packages, so they were never purchasable in-app.
    // C6 closed as unsold.
    //
    // Mapped anyway, with no slots: being generous to a purchase that should
    // not exist costs less than refusing one that does.
    foreach (['annual_3', 'quarterly_3'] as $productId) {
        $mapping = config('billing.google.products')[$productId];

        expect($mapping['grants'])->toBe('subscription', $productId);
        expect(array_key_exists('slots', $mapping))->toBeFalse($productId);
        expect(array_key_exists('sponsee_slots_unconfirmed', $mapping))
            ->toBeFalse($productId);
    }
});

it('maps the flash-sale quarterly, whose id really is just "Quarterly"', function () {
    // Created 28 Nov 2025 with the id and the reference name entered into each
    // other's fields: the *name* in both consoles is another product's id. The
    // id cannot be changed, so the only defence is that it is written down.
    $mapping = config('billing.apple.products')['Quarterly'];

    expect($mapping['cycle'])->toBe('quarterly');
    expect($mapping['grants'])->toBe('subscription');
    expect($mapping['promotional'])->toBeTrue();
    expect($mapping['hidden'])->toBeTrue();

    // And it grants, because a sale is still a sale.
    $sub = appleSub($this->account->id, ['product_id' => 'Quarterly', 'cycle' => 'quarterly']);
    expect($sub->grantsSubscriberAccess())->toBeTrue();
});

it('maps the lifetime consumable AND its non-consumable replacement', function () {
    // The consumable is the defect: Apple does not return consumables from
    // `currentEntitlements`, so a naive restore loses every one of these
    // buyers. `restore_via` is the flag that says the restore path has to read
    // transaction history for this one. The replacement is mapped before it is
    // approved, because the day it goes live is not a day to be editing config.
    $legacy = config('billing.apple.products')['com.12stepapp.recoverybox.profeatures'];
    $replacement = config('billing.apple.products')['com.12stepapp.recoverybox.profeatures_non_consumable'];

    expect($legacy['restore_via'])->toBe('transaction_history');
    expect($legacy['cycle'])->toBe('lifetime');
    expect($replacement['cycle'])->toBe('lifetime');
    expect(array_key_exists('restore_via', $replacement))->toBeFalse();

    foreach ([$legacy, $replacement] as $m) {
        expect($m['grants'])->toBe('subscription');
    }
});

it('keys Google on the product id, with the base plan beside it', function () {
    // RevenueCat shows `annual:p1y`; the Play API reports productId and
    // basePlanId separately. Keyed on the joined form, nothing would ever
    // match and every Google purchase would come back unmapped.
    $products = config('billing.google.products');

    expect(array_key_exists('annual', $products))->toBeTrue();
    expect(array_key_exists('annual:p1y', $products))->toBeFalse();
    expect($products['annual']['base_plan'])->toBe('p1y');
    expect($products['quarterly_2025']['base_plan'])->toBe('p3m999');
    expect($products['weekly']['base_plan'])->toBe('weekly');
});

it('has every Apple subscription ranked, because level decides upgrade or downgrade', function () {
    // Level is the only thing telling Apple whether a switch is an upgrade
    // (immediate, prorated) or a downgrade (deferred). A subscription without
    // one is a switch Apple has to guess at.
    $subscriptions = array_filter(
        config('billing.apple.products'),
        fn (array $m) => $m['grants'] === 'subscription' && $m['cycle'] !== 'lifetime',
    );

    expect($subscriptions)->not->toBeEmpty();
    foreach ($subscriptions as $id => $mapping) {
        // `array_key_exists`, not `toHaveKey($key, $reason)` — Pest reads that
        // second argument as the expected *value*, so `toHaveKey('level', $id)`
        // asserts that the level equals the product id. It fails loudly here;
        // the `->not->toHaveKey('slots', $id)` form below passed silently,
        // which is the worse half of the same mistake.
        expect(array_key_exists('level', $mapping))->toBeTrue($id);
    }
});

it('grants nothing for a product this server does not recognise', function () {
    // The Google product ids are not confirmed. An unmapped purchase is
    // recorded and grants nothing FROM THIS SERVER — and the person keeps their
    // access through RevenueCat, so the unknown costs nobody anything.
    appleSub($this->account->id, ['product_id' => 'com.12stepapp.recoverybox.something_new']);

    expect($this->service->refresh($this->account->id)->is_active)->toBeFalse();

    $this->service->recordRevenueCat($this->account->id, ['is_active' => true, 'expires_at' => now()->addYear()->toIso8601String()]);

    expect($this->service->refresh($this->account->id)->is_active)->toBeTrue();
});

it('ignores a revoked complimentary grant', function () {
    (new ComplimentaryGrant)->forceFill([
        'account_id' => $this->account->id,
        'period' => '1_year',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addMonths(11),
        'revoked_at' => now(),
    ])->save();

    expect($this->service->refresh($this->account->id)->is_active)->toBeFalse();
});

it('writes through to the column the old apps read, and never clears it', function () {
    appleSub($this->account->id);
    $this->service->refresh($this->account->id);

    expect((int) $this->account->fresh()->getRawOriginal('subscribed'))->toBe(1);

    // Now everything lapses. `accounts.subscribed` stays set, because people in
    // the field have premium today on the strength of it having been set years
    // ago, and clearing it would take that away. OPEN_QUESTIONS.
    StoreSubscription::query()->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
    $row = $this->service->refresh($this->account->id);

    expect($row->is_active)->toBeFalse()
        ->and((int) $this->account->fresh()->getRawOriginal('subscribed'))->toBe(1);
});

it('is idempotent, so a webhook arriving twice changes nothing', function () {
    appleSub($this->account->id);

    $first = $this->service->refresh($this->account->id)->only(['is_active', 'state', 'source', 'product_id', 'expires_at']);
    $second = $this->service->refresh($this->account->id)->only(['is_active', 'state', 'source', 'product_id', 'expires_at']);

    expect($second)->toEqual($first)
        ->and(Entitlement::query()->count())->toBe(1);
});

it('treats an expiry as a date passing, not an event somebody sends', function () {
    appleSub($this->account->id, ['expires_at' => now()->addHour()]);
    $this->service->refresh($this->account->id);

    expect($this->service->isActive($this->account->id))->toBeTrue();

    // Nothing arrives. Nothing is recomputed. The date simply passes.
    $this->travel(2)->hours();

    expect($this->service->isActive($this->account->id))->toBeFalse();
});
