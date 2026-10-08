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

it('grants nothing for a 2024 à-la-carte unlock, which is not the same as not knowing it', function () {
    // `steps8and9`, `steps10and11` and `otherfeatures` are in config so the
    // console can name them, with `grants => 'unresolved'`. The gate used to be
    // `! isUnmapped()` — is the id in the list — so listing them would have
    // made them mapped, and a lifetime purchase with no expiry granted premium
    // to anybody who bought a £2 step unlock in 2024.
    //
    // Whether they *should* grant anything is a RevenueCat dashboard question
    // (docs/OPEN_QUESTIONS.md C5a). Until it is answered, generous is wrong:
    // the people concerned are already served by the RevenueCat bridge, and
    // guessing here would hand lifetime premium to a cohort nobody has counted.
    foreach (['steps8and9', 'steps10and11', 'otherfeatures'] as $productId) {
        $sub = googleSub($this->account->id, [
            'product_id' => $productId,
            'cycle' => 'lifetime',
            'expires_at' => null,
        ]);

        expect($sub->isUnmapped())->toBeFalse("$productId should be known");
        expect($sub->grantsSubscriberAccess())->toBeFalse("$productId must not grant");
        expect($this->service->refresh($this->account->id)->is_active)
            ->toBeFalse($productId);

        $sub->delete();
    }
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

it('does not hand the sponsor bundles three sponsee slots on a guess', function () {
    // "Sponsor & 3 Sponsees", both from Jan 2024 with no live offer. The buyer
    // gets a subscription, which is certain. The three slots are not created,
    // which is the one deliberately ungenerous call in a resolver whose rule is
    // to err generously — granting a subscription wrongly affects one person
    // who paid; creating three slots wrongly hands free premium to three
    // accounts that did not. docs/OPEN_QUESTIONS.md C6.
    foreach (['annual_3', 'quarterly_3'] as $productId) {
        $mapping = config('billing.google.products')[$productId];

        expect($mapping['grants'])->toBe('subscription', $productId);
        expect($mapping)->not->toHaveKey('slots', $productId);
        expect($mapping['sponsee_slots_unconfirmed'])->toBe(3, $productId);
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
