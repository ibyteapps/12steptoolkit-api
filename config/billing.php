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
         | Product id → what it grants. Taken from the project's own StoreKit
         | configuration (`12 Step Toolkit.storekit`), which is the local mirror
         | of App Store Connect. **Check this against App Store Connect before
         | the cutover** — a product id that is wrong here is a purchase that
         | grants nothing, and a product id can never be renamed afterwards.
         |
         | Two subscription groups exist, which is unusual and is the old app's
         | history rather than a design: group 20509670 "Unlock Premium" holds
         | the original `…annual`, and group 20641515 "Unlock All Features"
         | holds the current `…annual1` and `…quarterly1`. Anyone still on the
         | old group keeps their subscription; it is simply not sold any more.
         */
        'products' => [
            'com.12stepapp.recoverybox.annual' => ['cycle' => 'annual', 'grants' => 'subscription'],
            'com.12stepapp.recoverybox.annual1' => ['cycle' => 'annual', 'grants' => 'subscription'],
            'com.12stepapp.recoverybox.quarterly1' => ['cycle' => 'quarterly', 'grants' => 'subscription'],

            // Consumables a sponsor buys to unlock the app for a sponsee. They
            // grant nothing to the buyer; `SponseeGifts` turns one into a slot.
            'com.12stepapp.recoverybox.annual_sponsee1' => ['cycle' => 'annual', 'grants' => 'sponsee_gift', 'slots' => 1],
            'com.12stepapp.recoverybox.quarterly_sponsee1' => ['cycle' => 'quarterly', 'grants' => 'sponsee_gift', 'slots' => 1],
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
         | Play product id → what it grants. **From the live Play Console
         | catalogue, supplied 2026-10-08**: 8 subscriptions and 9 one-time
         | products. This replaced four guessed entries.
         |
         | ## Why this list is much less load-bearing than it looks
         |
         | Neither shipped app decides anything from a product id. The Android
         | client asks RevenueCat for one entitlement — `subscribed` — and reads
         | `isActive` (`RevCatManager.kt:58, 95`). It records
         | `sku = ent.productIdentifier` only as a label, buys by RevenueCat
         | *package* identifier (`$rc_weekly`, `$rc_annual`, …), and decides
         | "lifetime" from a **null or zero expiry**, not from the id
         | (`:499`). `EntitlementService` here already does the same.
         |
         | So this table is not what keeps anybody premium. It does three
         | narrower jobs, and only the second one can go wrong quietly:
         |
         |  1. a human-readable plan label for Settings and the console;
         |  2. **telling a sponsee gift from a self-purchase**, which a webhook
         |     cannot infer any other way — the client knows because it chose
         |     the `sponsee_annual` package, but a Google RTDN arrives with a
         |     product id and nothing else, and a gift must credit a *different*
         |     account through `sponsee_orders`;
         |  3. the cycle, for reconciliation.
         |
         | ## The duplicates are deliberate and all of them must stay
         |
         | `annual` / `annual_1999`, `quarterly` / `quarterly_2025`, and four
         | separate `profeatures*` ids are the same entitlement re-published at a
         | new price — Play will not let a product's price history be rewritten,
         | so a price change is a new id. Everyone who bought an old one is still
         | entitled. Which ids are *offered* is a RevenueCat dashboard question
         | and never appears here; which are *honoured* is this list, and the
         | answer is all of them, for ever.
         */
        'products' => [
            // --- Subscriptions, single user ---------------------------------
            'weekly' => ['cycle' => 'weekly', 'grants' => 'subscription', 'label' => 'Weekly'],
            'monthly' => ['cycle' => 'monthly', 'grants' => 'subscription', 'label' => 'Monthly'],
            'quarterly' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'label' => 'Quarterly'],
            'quarterly_2025' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'label' => 'Quarterly'],
            'annual' => ['cycle' => 'annual', 'grants' => 'subscription', 'label' => 'Annual'],
            'annual_1999' => ['cycle' => 'annual', 'grants' => 'subscription', 'label' => 'Annual'],

            /*
             | Subscriptions that also mention sponsees in their title —
             | "Sponsor & 3 Sponsees". Both are from Jan 2024 and neither has a
             | live offer, so they look superseded by the one-slot consumables
             | below, which is what the client's gifting flow actually buys.
             |
             | They grant the **buyer** a subscription, which is certain. They do
             | *not* create sponsee slots here, which is the deliberately
             | ungenerous half of an otherwise generous resolver: granting a
             | subscription wrongly affects one person who paid, and creating
             | three slots wrongly hands free premium to three accounts that did
             | not. `docs/OPEN_QUESTIONS.md` C6 asks for the one fact that
             | settles it.
             */
            'quarterly_3' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'label' => 'Quarterly, sponsor bundle', 'sponsee_slots_unconfirmed' => 3],
            'annual_3' => ['cycle' => 'annual', 'grants' => 'subscription', 'label' => 'Annual, sponsor bundle', 'sponsee_slots_unconfirmed' => 3],

            // --- One-time: the lifetime unlock, at four price points ---------
            'profeatures' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],
            'profeatures1499' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],
            'profeatures2999' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],
            'profeatures_discounted' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'label' => 'Lifetime'],

            /*
             | Consumables a sponsor buys to unlock the app for one sponsee.
             | These grant the buyer nothing; `SponseeGifts` turns one into a
             | slot. The client reaches them through the `sponsee_annual` /
             | `sponsee_quarterly` packages, and the iOS pair
             | (`…annual_sponsee1`, `…quarterly_sponsee1`) is the same product.
             */
            'sponsee_1_annual' => ['cycle' => 'annual', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, annual'],
            'sponsee_1_quarterly' => ['cycle' => 'quarterly', 'grants' => 'sponsee_gift', 'slots' => 1, 'label' => 'Gift, 1 sponsee, quarterly'],

            /*
             | The 2024 à-la-carte unlocks, and **the one genuinely open
             | question in this file.**
             |
             | These predate the single `subscribed` entitlement. Nothing in
             | either shipped client reads a product id, so whether somebody who
             | bought `steps8and9` in 2024 has anything today depends entirely on
             | whether RevenueCat attaches these three products to the
             | `subscribed` entitlement:
             |
             |  * if it does, they have full premium already, this server needs
             |    to do nothing, and these rows exist only to label them;
             |  * if it does not, those people paid and have had nothing since —
             |    which would be a live bug in the current app, not a decision
             |    about the rebuild.
             |
             | `grants => 'unresolved'` until that is known. An unresolved
             | product is **recorded and grants nothing from this server**, and
             | the console shows it as unmapped. Nobody loses access in the
             | meantime, because every current entitlement comes through the
             | RevenueCat bridge, which does not consult this table at all.
             | `docs/OPEN_QUESTIONS.md` C5a.
             */
            'steps8and9' => ['cycle' => 'lifetime', 'grants' => 'unresolved', 'label' => 'Steps 8 & 9 (2024 unlock)'],
            'steps10and11' => ['cycle' => 'lifetime', 'grants' => 'unresolved', 'label' => 'Steps 10 & 11 (2024 unlock)'],
            'otherfeatures' => ['cycle' => 'lifetime', 'grants' => 'unresolved', 'label' => 'Other features (2024 unlock)'],
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
