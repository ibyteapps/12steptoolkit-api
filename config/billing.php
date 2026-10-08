<?php

/*
|--------------------------------------------------------------------------
| In-app purchases
|--------------------------------------------------------------------------
|
| The owner's decision (2026-10-07): **new purchases are verified by this
| server, and RevenueCat stays connected read-only** while the old apps are
| still installed.
|
| That is two sources of truth for one question — "is this person premium?" —
| and the rule that keeps it safe is written into `EntitlementService`:
| neither source may take away access the other still grants. A RevenueCat
| webhook arriving late cannot revoke a subscription this server has just
| verified with Apple, and vice versa.
|
| It needs two things switched on in the stores, and neither can be done from
| here: App Store Server Notifications V2 pointed at /api/v2/webhooks/apple,
| and Google Real-time Developer Notifications pointed at a Pub/Sub topic that
| pushes to /api/v2/webhooks/google. `GO_LIVE.md` has the steps.
|
| Secrets never go in this file or in git: the In-App Purchase key (.p8) and
| the Play service account JSON live outside the web root and only their PATHS
| are in .env.
*/

return [

    /*
     | What the app's paywall offers, in order. Changing this needs no app
     | update. Every product listed under apple/google below keeps granting a
     | subscription whether it is offered or not — that is what stops a price
     | change taking somebody's paid access away.
     */
    'offered' => [
        'ios' => ['com.12stepapp.recoverybox.annual1', 'com.12stepapp.recoverybox.quarterly1'],
        'android' => ['annual', 'quarterly'],
    ],

    /*
     | The stores' share, for the console's "after fees" figures where the store
     | does not say what it kept. Apple takes 30% in a subscriber's first year
     | and 15% after — or 15% throughout under the Small Business Program.
     | Google takes 15% of subscriptions.
     */
    'fees' => [
        'apple_small_business' => filter_var(env('APPLE_SMALL_BUSINESS_PROGRAM', true), FILTER_VALIDATE_BOOL),
        'apple_first_year' => (float) env('APPLE_FEE_FIRST_YEAR', 0.30),
        'apple_after' => (float) env('APPLE_FEE_AFTER', 0.15),
        'google' => (float) env('GOOGLE_FEE', 0.15),
    ],

    'apple' => [

        // Public identifiers — not secrets.
        'bundle_id' => env('APPLE_BUNDLE_ID', 'com.12stepapp.recoverybox'),
        'app_apple_id' => (int) env('APPLE_APP_APPLE_ID', 0),

        // App Store Connect → Users and Access → Integrations. Without them,
        // purchases are still verified from Apple's own signature on the
        // transaction; the key adds the authoritative status look-up, the
        // nightly reconcile and test notifications.
        'issuer_id' => env('APPLE_ISSUER_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY_PATH'),

        // The key that reads the catalogue (prices, periods, intro offers) for
        // the console. Falls back to the key above, which is usually the same one.
        'connect_issuer_id' => env('APPLE_CONNECT_ISSUER_ID') ?: env('APPLE_ISSUER_ID'),
        'connect_key_id' => env('APPLE_CONNECT_KEY_ID') ?: env('APPLE_KEY_ID'),
        'connect_private_key' => env('APPLE_CONNECT_KEY_PATH') ?: env('APPLE_PRIVATE_KEY_PATH'),

        'environment' => strtolower((string) env('APPLE_STORE_ENVIRONMENT', 'production')),

        // App Review buys in the sandbox against the PRODUCTION server, so the
        // live site must accept sandbox purchases or the app is rejected.
        'accept_sandbox' => filter_var(env('APPLE_ACCEPT_SANDBOX', true), FILTER_VALIDATE_BOOL),

        /*
         | Product id → what it grants. **Reconciled 2026-10-08** against the
         | RevenueCat product list, which is the only place both stores are
         | visible at once and the only place the pairings were ever recorded.
         | Twelve live Apple products, and one awaiting its first submission.
         |
         | ## Two subscription groups, which is history rather than design
         |
         | Group "Unlock All Features" holds six, ranked by level — and level is
         | the only thing telling Apple whether a switch between two of them is
         | an upgrade (immediate, prorated) or a downgrade (deferred to the end
         | of the paid period). Group "Unlock Premium" holds only the original
         | `…annual`, which is not sold any more; anyone still on it keeps it.
         | A person can hold one subscription from *each* group at once, which
         | R8 handles by taking the most generous.
         */
        'products' => [

            // ── Group "Unlock All Features", by level ────────────────────
            'com.12stepapp.recoverybox.annual1' => ['cycle' => 'annual', 'grants' => 'subscription', 'level' => 1, 'label' => 'Annual'],
            'com.12stepapp.recoverybox.quarterly1' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'level' => 2, 'label' => 'Quarterly'],
            'com.12stepapp.recoverybox.annual1999' => ['cycle' => 'annual', 'grants' => 'subscription', 'level' => 3, 'label' => 'Annual'],

            /*
             | ⚠ THE PRODUCT ID IS LITERALLY `Quarterly`, AND THAT IS NOT A
             | TRANSCRIPTION ERROR. Created 28 Nov 2025 for the Christmas flash
             | sale, with the id and the reference name entered into each
             | other's fields: its *name* in both App Store Connect and
             | RevenueCat is `com.12stepapp.recoverybox.annual1999`, which is
             | the id of a different product three levels above it.
             |
             | Two consequences worth keeping written down. Every sale of this
             | product is **reported under the annual product's name** until the
             | display names are corrected in both consoles — the ids cannot be.
             | And it is ranked above the regular quarterly at level 5, so
             | somebody switching onto the sale upgrades immediately with
             | credit for unused days, which is the right behaviour for a sale
             | but comes from the ranking and not from the price.
             |
             | `promotional` so it can be counted separately; `hidden` so it
             | never appears in a list of plans anybody can choose from.
             */
            'Quarterly' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'level' => 4, 'label' => 'Quarterly, flash sale', 'promotional' => true, 'hidden' => true],

            'com.12stepapp.recoverybox.quarterly999' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'level' => 5, 'label' => 'Quarterly'],
            'com.12stepapp.recoverybox.weekly1' => ['cycle' => 'weekly', 'grants' => 'subscription', 'level' => 6, 'label' => 'Weekly'],

            // ── Group "Unlock Premium" — the original, no longer sold ────
            // Carries a one-week free introductory offer (`.storekit:187-192`).
            'com.12stepapp.recoverybox.annual' => ['cycle' => 'annual', 'grants' => 'subscription', 'level' => 1, 'label' => 'Annual (original)'],

            /*
             | ⚠ LIFETIME, SOLD AS A **CONSUMABLE**, WHICH IS THE DEFECT THIS
             | ENTRY EXISTS TO SURVIVE. Apple does not return consumables from
             | `Transaction.currentEntitlements`, so a naive restore loses every
             | one of these buyers on a reinstall or a new phone. They are still
             | recoverable — consumables do appear in `Transaction.all` and in
             | the App Store Server API's transaction history — so the restore
             | path MUST read history rather than entitlements. A product's type
             | cannot be changed after creation, hence the replacement below.
             */
            'com.12stepapp.recoverybox.profeatures' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime (consumable, legacy)', 'restore_via' => 'transaction_history'],

            /*
             | The replacement, created 2026-10-08: a proper non-consumable, so
             | it restores. Not yet approved — Apple requires a first
             | non-consumable to be submitted alongside a new app version, so it
             | goes live with 2.0 and not before. Mapped in advance because a
             | product that is not mapped grants nothing, and the day it is
             | approved is not a day to be editing config.
             |
             | Family Sharing is OFF and staying off — see ENTITLEMENT_RULES R13.
             */
            'com.12stepapp.recoverybox.profeatures_non_consumable' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],

            /*
             | Consumables a sponsor buys to unlock the app for one sponsee.
             | They grant the **buyer** nothing; a gift becomes a slot. The
             | `_1999` / `_999` pair is the same grant at a later price.
             |
             | Confirmed against RevenueCat: all four have no entitlement
             | attached, which is correct and is the behaviour this mapping
             | preserves.
             */
            'com.12stepapp.recoverybox.annual_sponsee1' => ['cycle' => 'annual', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, annual'],
            'com.12stepapp.recoverybox.quarterly_sponsee1' => ['cycle' => 'quarterly', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, quarterly'],
            'com.12stepapp.recoverybox.annual_sponsee1_1999' => ['cycle' => 'annual', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, annual'],
            'com.12stepapp.recoverybox.quarterly_sponsee1_999' => ['cycle' => 'quarterly', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, quarterly'],
        ],

        /*
         | Apple's JWS carries its certificate chain; the chain must end in one
         | of these roots. SHA-256 fingerprint of "Apple Root CA - G3" as
         | published at https://www.apple.com/certificateauthority/.
         */
        'root_fingerprints' => [
            '63343ABFB89A6A03EBB57E9B3F5FA7BE7C4F5C756F3017B3A8C488C3653E9179',
        ],

        // While installed copies of the old (RevenueCat) apps still exist, App
        // Store Connect sends notifications here and this server passes each
        // verified one on to RevenueCat's own URL, so one notification URL
        // serves both. Empty = no forwarding.
        'notifications_forward_url' => env('APPLE_NOTIFICATIONS_FORWARD_URL'),

        'timeout' => (int) env('APPLE_API_TIMEOUT', 15),
    ],

    'google' => [

        'package_name' => env('GOOGLE_PACKAGE_NAME', 'com.ibyteapps.aa12steptoolkit'),

        // Path to the service account's JSON key, outside the web root, chmod 600.
        'credentials' => env('GOOGLE_PLAY_CREDENTIALS'),

        /*
         | Play product id → what it grants. **Reconciled 2026-10-08** against
         | the RevenueCat product list. Fifteen live products.
         |
         | ## Keyed on the product, with the base plan beside it
         |
         | RevenueCat displays a Play subscription as `productId:basePlanId` —
         | `annual:p1y`, `quarterly_2025:p3m999`, `weekly:weekly`. The Play
         | Developer API does not: `purchases.subscriptionsv2.get` reports
         | `lineItems[].productId` and `offerDetails.basePlanId` as separate
         | fields. So the key here is the **product id alone**, and the base
         | plan is recorded beside it. Keyed on the joined form, nothing would
         | ever match and every Google purchase would come back unmapped.
         |
         | ## The duplicates stay, for ever
         |
         | Play will not let a product's price history be rewritten, so a price
         | change is a new id: `annual` / `annual_1999`, `quarterly` /
         | `quarterly_2025`, and four separate `profeatures*`. Which ids are
         | *offered* is a paywall question that never appears in this repo;
         | which are *honoured* is this list, and the answer is all of them.
         */
        'products' => [
            // ── Subscriptions ────────────────────────────────────────────
            'weekly' => ['cycle' => 'weekly', 'grants' => 'subscription', 'base_plan' => 'weekly', 'label' => 'Weekly'],
            'monthly' => ['cycle' => 'monthly', 'grants' => 'subscription', 'base_plan' => 'p1m', 'label' => 'Monthly'],
            'quarterly' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'base_plan' => 'p3m', 'label' => 'Quarterly'],
            'quarterly_2025' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'base_plan' => 'p3m999', 'label' => 'Quarterly'],
            'annual' => ['cycle' => 'annual', 'grants' => 'subscription', 'base_plan' => 'p1y', 'label' => 'Annual'],
            'annual_1999' => ['cycle' => 'annual', 'grants' => 'subscription', 'base_plan' => 'p1y1999', 'label' => 'Annual'],

            /*
             | The two "Sponsor & 3 Sponsees" bundles. **Not in RevenueCat at
             | all**, and the Android app buys through RevenueCat packages — so
             | a product that is not there was never purchasable in-app and
             | nobody should hold one. `OPEN_QUESTIONS.md` C6 is closed as
             | unsold.
             |
             | Mapped anyway, as plain subscriptions with no slots, so that if
             | one ever does arrive the person gets access rather than nothing.
             | Being generous to a purchase that should not exist costs less
             | than refusing one that does.
             */
            'annual_3' => ['cycle' => 'annual', 'grants' => 'subscription', 'label' => 'Annual, sponsor bundle (unsold)'],
            'quarterly_3' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'label' => 'Quarterly, sponsor bundle (unsold)'],

            // ── One-time: the lifetime unlock, at four price points ───────
            'profeatures' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],
            'profeatures1499' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],
            'profeatures2999' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],
            'profeatures_discounted' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime, sale', 'promotional' => true, 'hidden' => true],

            // ── Consumables: a gift for one sponsee ──────────────────────
            'sponsee_1_annual' => ['cycle' => 'annual', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, annual'],
            'sponsee_1_quarterly' => ['cycle' => 'quarterly', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, quarterly'],

            /*
             | The à-la-carte unlocks, sold roughly a decade ago and retired
             | long since. **They grant nothing** — the owner's decision,
             | 2026-10-08, reversing an earlier call to grant them premium.
             |
             | Two facts established while deciding, both of which stand: no
             | entitlement was ever attached to any of the three in RevenueCat,
             | and `19/add_order.php` writes a `subscription_orders` row without
             | touching `accounts.subscribed`. So they have granted nothing from
             | either direction since they were sold, and nobody has raised it
             | in ten years.
             |
             | `none`, not `unresolved`: this is decided, and the two values
             | must stay distinguishable. The orders remain in
             | `subscription_orders` and the console shows them, so if somebody
             | ever does surface they are granted by hand with an audit row —
             | which is a better answer than a config entry nobody revisits.
             | ENTITLEMENT_RULES R2.
             */
            'steps8and9' => ['cycle' => 'lifetime', 'grants' => 'none', 'label' => 'Steps 8 & 9 (retired)'],
            'steps10and11' => ['cycle' => 'lifetime', 'grants' => 'none', 'label' => 'Steps 10 & 11 (retired)'],
            'otherfeatures' => ['cycle' => 'lifetime', 'grants' => 'none', 'label' => 'Other features (retired)'],
        ],

        'pubsub_service_account' => env('GOOGLE_PUBSUB_SERVICE_ACCOUNT'),
        'pubsub_audience' => env('GOOGLE_PUBSUB_AUDIENCE'),

        'timeout' => (int) env('GOOGLE_API_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | RevenueCat — read-only, for the overlap
    |--------------------------------------------------------------------------
    |
    | Every subscription sold before this server existed was sold through
    | RevenueCat, and both old apps ask RevenueCat directly. The bridge keeps
    | those people premium:
    |
    |  * the webhook records events and refreshes the entitlement row;
    |  * `revenuecat:reconcile` catches anything the webhook missed;
    |  * nothing here ever *writes* to RevenueCat, and nothing here grants a
    |    purchase — RevenueCat's answer is kept in its own column so that it
    |    can be read, audited and eventually switched off without touching the
    |    store-verified state beside it.
    |
    | The app user id is `accounts.id` as a string, which is what the old apps
    | already use, so no identity mapping is needed.
    */
    'revenuecat' => [
        'enabled' => filter_var(env('REVENUECAT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'api_key' => env('REVENUECAT_SECRET_KEY'),
        'webhook_auth' => env('REVENUECAT_WEBHOOK_AUTH'),
        'entitlement_id' => env('REVENUECAT_ENTITLEMENT_ID', 'subscribed'),
        'base_url' => env('REVENUECAT_BASE_URL', 'https://api.revenuecat.com/v1'),
        'timeout' => (int) env('REVENUECAT_TIMEOUT', 15),
    ],
];
