<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * `billing:check` is a diagnostic, so what it *says* is the whole product. A
 * wrong sentence here sends somebody to the wrong console for an afternoon.
 *
 * Each test puts the command in front of one real failure shape — the bodies
 * are the ones Apple and Google actually return — and asserts that it names
 * the right system. The three Google cases are the point of the exercise: the
 * key, the Cloud project and the Play Console fail identically from the
 * outside and are fixed in three different places.
 *
 * Keys are generated for real, so the signing path is exercised rather than
 * mocked: a test that stubs the signature cannot catch a key the wrong curve.
 */

/** A real P-256 .p8 and a real service-account JSON, made once. */
function storeKeys(): array
{
    static $keys = null;

    if ($keys !== null) {
        return $keys;
    }

    $dir = sys_get_temp_dir().'/billing-check-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($ec, $p8);
    $p8Path = $dir.'/AuthKey_TESTKEY123.p8';
    file_put_contents($p8Path, $p8);
    chmod($p8Path, 0600);

    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($rsa, $pem);
    $jsonPath = $dir.'/play-service-account.json';
    file_put_contents($jsonPath, json_encode([
        'type' => 'service_account',
        'project_id' => 'aa-12-step-toolkit',
        'private_key_id' => 'abc123',
        'private_key' => $pem,
        'client_email' => 'play-billing@aa-12-step-toolkit.iam.gserviceaccount.com',
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    chmod($jsonPath, 0600);

    return $keys = ['p8' => $p8Path, 'json' => $jsonPath, 'dir' => $dir];
}

/** Point the config at the generated keys. */
function withStoreKeys(): void
{
    $keys = storeKeys();

    config([
        'billing.apple.private_key' => $keys['p8'],
        'billing.apple.key_id' => 'TESTKEY123',
        'billing.apple.issuer_id' => '11111111-2222-3333-4444-555555555555',
        'billing.apple.connect_private_key' => $keys['p8'],
        'billing.apple.connect_key_id' => 'TESTKEY123',
        'billing.apple.connect_issuer_id' => '11111111-2222-3333-4444-555555555555',
        'billing.google.credentials' => $keys['json'],
    ]);
}

/** Run it and hand back the output. */
function runCheck(array $options = []): array
{
    $code = Artisan::call('billing:check', $options);

    return [$code, Artisan::output()];
}

const PLAY_TOKEN = ['access_token' => 'ya29.a-test-token', 'expires_in' => 3599, 'token_type' => 'Bearer'];

beforeEach(function () {
    Http::preventStrayRequests();
});

// ------------------------------------------------------------- without any keys

it('fails, naming the variable, when no key is configured', function () {
    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('APPLE_PRIVATE_KEY_PATH is not set')
        ->and($out)->toContain('GOOGLE_PLAY_CREDENTIALS is not set')
        // The Connect key falls back to the Server key's path, so naming only
        // APPLE_CONNECT_KEY_PATH would send somebody to set a variable that
        // was never going to be read.
        ->and($out)->toContain('neither APPLE_CONNECT_KEY_PATH nor APPLE_PRIVATE_KEY_PATH');
});

it('says plainly that --offline asked the stores nothing', function () {
    [, $out] = runCheck(['--offline' => true]);

    expect($out)->toContain('nothing was asked of Apple or Google');
});

it('refuses a file that is not an App Store key', function () {
    $path = storeKeys()['dir'].'/not-a-key.p8';
    file_put_contents($path, "hello\n");
    config(['billing.apple.private_key' => $path]);

    [$code, $out] = runCheck(['--offline' => true, '--apple' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('BEGIN PRIVATE KEY');
});

it('refuses a key of the wrong type', function () {
    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($rsa, $pem);
    $path = storeKeys()['dir'].'/rsa.p8';
    file_put_contents($path, $pem);
    config([
        'billing.apple.private_key' => $path,
        'billing.apple.key_id' => 'X',
        'billing.apple.issuer_id' => 'Y',
    ]);

    [$code, $out] = runCheck(['--offline' => true, '--apple' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('not an elliptic-curve key');
});

// ------------------------------------------------------------------- catalogue

it('catches a typo in `grants`, which otherwise grants nothing silently', function () {
    config(['billing.apple.products' => [
        'com.example.one' => ['cycle' => 'annual', 'grants' => 'subscriptions'],
    ]]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('unknown `grants` value `subscriptions`')
        ->and($out)->toContain('silently');
});

it('catches two products at the same level in one group', function () {
    config(['billing.apple.products' => [
        'a' => ['cycle' => 'annual', 'grants' => 'subscription', 'group' => 'g', 'level' => 1],
        'b' => ['cycle' => 'quarterly', 'grants' => 'subscription', 'group' => 'g', 'level' => 1],
    ]]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)->and($out)->toContain('two products at level 1');
});

it('accepts the same level in two different groups, because a level is per group', function () {
    config(['billing.apple.products' => [
        'a' => ['cycle' => 'annual', 'grants' => 'subscription', 'group' => 'all_features', 'level' => 1],
        'b' => ['cycle' => 'annual', 'grants' => 'subscription', 'group' => 'premium', 'level' => 1],
    ], 'billing.offered.ios' => ['a']]);

    [, $out] = runCheck(['--offline' => true]);

    expect($out)->toContain('all_features: 1 product, level 1')
        ->and($out)->not->toContain('two products at level');
});

it('catches a recurring subscription with no level', function () {
    config(['billing.apple.products' => [
        'a' => ['cycle' => 'annual', 'grants' => 'subscription'],
    ]]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)->and($out)->toContain('no level');
});

it('does not demand a level of a lifetime unlock or a gift', function () {
    config(['billing.apple.products' => [
        'life' => ['cycle' => 'lifetime', 'grants' => 'subscription', 'restore_via' => 'transaction_history'],
        'gift' => ['cycle' => 'annual', 'grants' => 'sponsee_gift', 'slots' => 1],
    ]]);

    [, $out] = runCheck(['--offline' => true]);

    expect($out)->not->toContain('no level');
});

it('catches an offered product that is not mapped', function () {
    config(['billing.offered.ios' => ['com.example.never-heard-of-it']]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('com.example.never-heard-of-it')
        ->and($out)->toContain('would buy it and get nothing');
});

it('catches an offered product that is hidden, like a flash sale', function () {
    config(['billing.offered.ios' => ['Quarterly']]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)->and($out)->toContain('marked hidden');
});

it('catches a gift with no slots', function () {
    config(['billing.google.products' => [
        'gift' => ['cycle' => 'annual', 'grants' => 'sponsee_gift'],
    ], 'billing.offered.android' => []]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)->and($out)->toContain('pay and get nothing');
});

/*
 | The restore path for the lifetime *consumable* is the one mapping somebody
 | could delete as untidy. Deleting it loses every lifetime buyer on reinstall,
 | and nothing else in the suite would notice.
 */
it('catches the lifetime consumable losing its restore route', function () {
    $products = config('billing.apple.products');
    unset($products['com.12stepapp.recoverybox.profeatures']['restore_via']);
    config(['billing.apple.products' => $products]);

    [$code, $out] = runCheck(['--offline' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('currentEntitlements')
        ->and($out)->toContain('loses access on a reinstall');
});

it('passes the shipped catalogue', function () {
    [, $out] = runCheck(['--offline' => true]);

    expect($out)->toContain('13 mapped')
        ->and($out)->toContain('17 mapped')
        ->and($out)->toContain('all_features: 6 products, levels 1–6')
        ->and($out)->toContain('all mapped and all granting a subscription')
        ->and($out)->toContain('restores through transaction history');
});

// ------------------------------------------------------------- the two RevenueCat flags

it('explains the two RevenueCat flags in the order they must be flipped', function () {
    config(['billing.revenuecat.enabled' => true, 'billing.revenuecat.grants_access' => true]);

    [, $out] = runCheck(['--offline' => true]);

    expect($out)->toContain('listening and granting');
});

it('flags granting without listening, which grants nothing', function () {
    config(['billing.revenuecat.enabled' => false, 'billing.revenuecat.grants_access' => true]);

    [, $out] = runCheck(['--offline' => true]);

    expect($out)->toContain('has no effect');
});

it('warns when the bridge is off while the old apps are still out there', function () {
    config(['billing.revenuecat.enabled' => false, 'billing.revenuecat.grants_access' => false]);

    [, $out] = runCheck(['--offline' => true]);

    expect($out)->toContain('1.9.0 and 1.6.6 are retired');
});

// -------------------------------------------------------- Apple, App Store Server API

it('reports a working App Store Server key', function () {
    withStoreKeys();
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response(['notificationHistory' => [], 'hasMore' => false]),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => []]),
    ]);

    [, $out] = runCheck(['--apple' => true]);

    expect($out)->toContain('the key works (production)')
        ->and($out)->toContain('nothing in the last 24 hours');
});

/*
 | The most valuable single line in the command: a 4040007 means the
 | credentials are GOOD and it is the notification URL that is missing. Read as
 | a plain 404 it looks like an auth failure and sends somebody to re-issue a
 | key that was never the problem.
 */
it('reads "no notification URL" as the key working', function () {
    withStoreKeys();
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response([
            'errorCode' => 4040007,
            'errorMessage' => 'No App Store Server Notification URL found for the app in the environment.',
        ], 404),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => []]),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($out)->toContain('the key works')
        ->and($out)->toContain('App Store Server Notifications')
        // A caution, so a server waiting on one console setting is still green
        // for a monitor.
        ->and($code)->toBe(0);
});

it('explains a 401 from Apple, which arrives with no body at all', function () {
    withStoreKeys();
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response('', 401),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => []]),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('carries no body saying why')
        ->and($out)->toContain('In-App Purchase key');
});

it('names the bundle id when Apple does not recognise the app', function () {
    withStoreKeys();
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response(['errorCode' => 4040003, 'errorMessage' => 'App not found.'], 404),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => []]),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('com.12stepapp.recoverybox')
        ->and($out)->toContain('different team');
});

it('treats a rate limit as the credentials working', function () {
    withStoreKeys();
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response(['errorCode' => 4290000, 'errorMessage' => 'Rate limit exceeded.'], 429),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => []]),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($code)->toBe(0)->and($out)->toContain('credentials are fine');
});

// ------------------------------------------------------- Apple, App Store Connect API

it('offers the APPLE_APP_APPLE_ID value when the Connect key works', function () {
    withStoreKeys();
    config(['billing.apple.app_apple_id' => 0]);
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response(['notificationHistory' => []]),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => [[
            'type' => 'apps',
            'id' => '987654321',
            'attributes' => ['name' => '12 Step Toolkit', 'bundleId' => 'com.12stepapp.recoverybox'],
        ]]]),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($out)->toContain('APPLE_APP_APPLE_ID=987654321')->and($code)->toBe(0);
});

it('fails when the configured Apple app id is not the one Apple reports', function () {
    withStoreKeys();
    config(['billing.apple.app_apple_id' => 111]);
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response(['notificationHistory' => []]),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => [[
            'id' => '987654321',
            'attributes' => ['name' => '12 Step Toolkit'],
        ]]]),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($code)->toBe(1)->and($out)->toContain('APPLE_APP_APPLE_ID is 111');
});

/*
 | A Connect refusal must never fail the command: an In-App Purchase key cannot
 | read that API at all, so a server verifying purchases perfectly well would
 | otherwise exit 1 for ever.
 */
it('cautions, but does not fail, when the key cannot read the Connect API', function () {
    withStoreKeys();
    config(['billing.apple.app_apple_id' => 987654321]);
    Http::fake([
        'api.storekit.itunes.apple.com/*' => Http::response(['notificationHistory' => []]),
        'api.appstoreconnect.apple.com/*' => Http::response([
            'errors' => [['status' => '401', 'code' => 'NOT_AUTHORIZED', 'title' => 'Authentication credentials are missing or invalid.']],
        ], 401),
    ]);

    [$code, $out] = runCheck(['--apple' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('Team key')
        ->and($out)->toContain('purchases do not');
});

// ------------------------------------------------------------- Google, the three ways

it('blames the key, not Play, for invalid_grant', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Invalid JWT Signature.',
        ], 400),
    ]);

    [$code, $out] = runCheck(['--google' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('refused the key itself')
        ->and($out)->toContain('clock is more than a few minutes out')
        ->and($out)->toContain('Nothing to do with Play Console permissions');
});

it('sends you to the Cloud Console, with the project, when the API is not enabled', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 403,
                'message' => 'Google Play Android Developer API has not been used in project 400719706081 before or it is disabled.',
                'status' => 'PERMISSION_DENIED',
                'errors' => [['reason' => 'accessNotConfigured', 'domain' => 'usageLimits']],
            ],
        ], 403),
    ]);

    [$code, $out] = runCheck(['--google' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('not enabled in the Cloud project this key came from')
        ->and($out)->toContain('project 400719706081')
        ->and($out)->toContain('project=aa-12-step-toolkit')
        ->and($out)->toContain('nothing to do with Play Console permissions');
});

it('sends you to the Play Console, with the account to invite, for a permission refusal', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 401,
                'message' => 'The current user has insufficient permissions to perform the requested operation.',
                'status' => 'UNAUTHENTICATED',
            ],
        ], 401),
    ]);

    [$code, $out] = runCheck(['--google' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('play-billing@aa-12-step-toolkit.iam.gserviceaccount.com')
        ->and($out)->toContain('Users and permissions')
        ->and($out)->toContain('few minutes to propagate');
});

it('blames the package name for a 404', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'message' => 'Package not found: com.ibyteapps.aa12steptoolkit.', 'status' => 'NOT_FOUND'],
        ], 404),
    ]);

    [$code, $out] = runCheck(['--google' => true]);

    expect($code)->toBe(1)->and($out)->toContain('GOOGLE_PACKAGE_NAME');
});

it('does not ask Play anything once the token exchange has failed', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    [, $out] = runCheck(['--google' => true]);

    // Nothing was faked for androidpublisher, and `preventStrayRequests` would
    // have thrown had it been called.
    expect($out)->toContain('the token exchange above did not succeed');
});

it('names a product Play sells that this config does not map', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*/inappproducts*' => Http::response([
            'inappproduct' => [['sku' => 'profeatures'], ['sku' => 'brand_new_unlock']],
        ]),
        'androidpublisher.googleapis.com/*/subscriptions*' => Http::response([
            'subscriptions' => [['productId' => 'annual', 'basePlans' => [['basePlanId' => 'p1y']]]],
        ]),
    ]);

    [$code, $out] = runCheck(['--google' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('brand_new_unlock')
        ->and($out)->toContain('would grant nothing');
});

it('names a base plan Play disagrees with', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*/inappproducts*' => Http::response(['inappproduct' => []]),
        'androidpublisher.googleapis.com/*/subscriptions*' => Http::response([
            'subscriptions' => [['productId' => 'annual', 'basePlans' => [['basePlanId' => 'p1y-renamed']]]],
        ]),
    ]);

    [$code, $out] = runCheck(['--google' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('recorded as base plan `p1y`')
        ->and($out)->toContain('p1y-renamed');
});

it('reports the service account and its Cloud project from the key file itself', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*' => Http::response(['inappproduct' => [], 'subscriptions' => []]),
    ]);

    [, $out] = runCheck(['--google' => true]);

    expect($out)->toContain('Cloud project `aa-12-step-toolkit`')
        ->and($out)->toContain('play-billing@aa-12-step-toolkit.iam.gserviceaccount.com');
});

// --------------------------------------------------------------------- the flags

it('asks Apple nothing when told --google', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*' => Http::response(['inappproduct' => [], 'subscriptions' => []]),
    ]);

    [, $out] = runCheck(['--google' => true]);

    expect($out)->not->toContain('App Store Server API');
});

it('never prints a key, a token or a signed assertion', function () {
    withStoreKeys();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(PLAY_TOKEN),
        'androidpublisher.googleapis.com/*' => Http::response(['inappproduct' => [], 'subscriptions' => []]),
        'api.storekit.itunes.apple.com/*' => Http::response(['notificationHistory' => []]),
        'api.appstoreconnect.apple.com/*' => Http::response(['data' => []]),
    ]);

    [, $out] = runCheck();

    expect($out)->not->toContain('ya29.a-test-token')
        ->not->toContain('BEGIN PRIVATE KEY')
        ->not->toContain('eyJ');
});
