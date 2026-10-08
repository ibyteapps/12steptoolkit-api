<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsChecks;
use App\Exceptions\CheckCaution;
use App\Services\Billing\Apple\AppleKey;
use App\Services\Billing\Apple\AppStoreConnectApi;
use App\Services\Billing\Apple\AppStoreServerApi;
use App\Services\Billing\Google\PlayDeveloperApi;
use App\Services\Billing\Google\ServiceAccountKey;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * `php artisan billing:check` — can this server actually talk to the stores,
 * and does its product catalogue match theirs?
 *
 * Setting up store credentials is four systems that each blame the others. A
 * `.p8` that is the wrong *kind* of key fails with a bare 401 and no body. A
 * Play service account can hold every permission in the Play Console and still
 * be refused, because the API is disabled in the Cloud project the key came
 * from — reported as a 403 naming a project *number* nobody recognises. And a
 * product added in a store console but not mapped here silently grants nothing
 * to whoever buys it.
 *
 * So rather than reasoning about it, this asks. It makes one real authenticated
 * call to each API and names which half works and why the other does not, in
 * the words of the person who has to fix it.
 *
 * ## It is safe on production
 *
 * Every store call is a read. Nothing is written to Apple or Google, no test
 * notification is requested, no row in this database is touched, and no
 * transaction id, token, key or signed assertion is printed or logged. It
 * needs no database connection at all, which is deliberate: the credentials
 * are often the thing being fixed on a server whose database is still down.
 *
 * Exit code 1 if something failed. Cautions — true today, somebody's problem
 * tomorrow — are counted and do not change the exit code, so this can be the
 * body of a monitor.
 */
class BillingCheckCommand extends Command
{
    use ReportsChecks;

    protected $signature = 'billing:check
        {--offline : Check the key files and the catalogue only — no calls to Apple or Google}
        {--apple : Apple only}
        {--google : Only Google}';

    protected $description = 'Check the store credentials and the product catalogue: does this server reach Apple and Google?';

    /** The `grants` values `config/billing.php` is allowed to carry. */
    private const GRANTS = ['subscription', 'sponsee_gift', 'none', 'unresolved'];

    /** Cycles that are a recurring subscription rather than a one-off. */
    private const RECURRING = ['weekly', 'monthly', 'quarterly', 'annual'];

    /**
     * Held between checks so the Play catalogue is read with the token the
     * exchange above it just proved, rather than exchanging three times.
     */
    private ?string $playToken = null;

    private ?ServiceAccountKey $playKey = null;

    public function handle(): int
    {
        $only = array_keys(array_filter([
            'apple' => (bool) $this->option('apple'),
            'google' => (bool) $this->option('google'),
        ]));

        $apple = $only === [] || in_array('apple', $only, true);
        $google = $only === [] || in_array('google', $only, true);
        $online = ! $this->option('offline');

        $this->line('');

        $this->section('Switches and identifiers', $this->switches(...));
        $this->section('The catalogue, as this config has it', $this->catalogue(...));

        if ($apple) {
            $this->section('Apple — the key on disk', $this->appleKeyFiles(...));

            if ($online) {
                $this->section('Apple — App Store Server API (purchases)', $this->appleServerApi(...));
                $this->section('Apple — App Store Connect API (the catalogue)', $this->appleConnectApi(...));
            }
        }

        if ($google) {
            $this->section('Google — the key on disk', $this->googleKeyFile(...));

            if ($online) {
                $this->section('Google — Play Developer API', $this->googlePlayApi(...));
            }
        }

        if (! $online) {
            $this->line('');
            $this->note('--offline: nothing was asked of Apple or Google. Run it without the flag to find out whether the credentials work.');
        }

        return $this->report();
    }

    // ------------------------------------------------------ what is switched on

    private function switches(): void
    {
        $this->check('Apple bundle id', function (): string {
            $id = (string) config('billing.apple.bundle_id');

            if ($id === '') {
                throw new RuntimeException('APPLE_BUNDLE_ID is not set — every token this server signs will be refused');
            }

            return $id;
        });

        $this->check('Apple app id', function (): string {
            $id = (int) config('billing.apple.app_apple_id');

            if ($id === 0) {
                throw new CheckCaution(
                    'APPLE_APP_APPLE_ID is not set. Not needed to verify a purchase; '
                    .'the App Store Connect check below reports the value to use.',
                );
            }

            return (string) $id;
        });

        $this->check('Apple store environment', function (): string {
            $environment = (string) config('billing.apple.environment');

            if (! in_array($environment, ['production', 'sandbox'], true)) {
                throw new RuntimeException("APPLE_STORE_ENVIRONMENT is `{$environment}` — it must be production or sandbox");
            }

            $sandbox = config('billing.apple.accept_sandbox')
                ? 'sandbox purchases accepted'
                : 'sandbox purchases REFUSED';

            // App Review buys in the sandbox against whatever server the
            // submitted build points at. A production server that refuses
            // sandbox purchases fails review on the paywall.
            if ($environment === 'production' && ! config('billing.apple.accept_sandbox')) {
                throw new CheckCaution(
                    'production, and sandbox purchases are refused — App Review buys in the sandbox '
                    .'against this server, so the app will be rejected at the paywall',
                );
            }

            return $environment.', '.$sandbox;
        });

        $this->check('Google package name', function (): string {
            $name = (string) config('billing.google.package_name');

            if ($name === '') {
                throw new RuntimeException('GOOGLE_PACKAGE_NAME is not set');
            }

            return $name;
        });

        /*
         | The two RevenueCat flags and the order between them, because getting
         | that order wrong is the one deployment mistake in this whole area
         | that silently removes paid access. ENTITLEMENT_RULES §0.
         */
        $this->check('RevenueCat', function (): string {
            $enabled = (bool) config('billing.revenuecat.enabled');
            $grants = (bool) config('billing.revenuecat.grants_access');

            if (! $enabled && $grants) {
                throw new CheckCaution(
                    'REVENUECAT_GRANTS_ACCESS is true but REVENUECAT_ENABLED is false, so it grants nothing — '
                    .'the grant flag has no effect while the whole bridge is off',
                );
            }

            if (! $enabled) {
                throw new CheckCaution(
                    'off entirely. Correct only once 1.9.0 and 1.6.6 are retired — until then a purchase made '
                    .'in an old app arrives only by the RevenueCat webhook and this server never hears about it',
                );
            }

            if ($grants) {
                return 'listening and granting — correct until the export is imported and checked (ENTITLEMENT_RULES §0)';
            }

            return 'listening, no longer granting — the middle state, which assumes the export has been imported';
        });

        $this->check('RevenueCat secrets', function (): string {
            if (! config('billing.revenuecat.enabled')) {
                return 'not needed while the bridge is off';
            }

            $missing = array_keys(array_filter([
                'REVENUECAT_SECRET_KEY' => (string) config('billing.revenuecat.api_key') === '',
                'REVENUECAT_WEBHOOK_AUTH' => (string) config('billing.revenuecat.webhook_auth') === '',
            ]));

            if ($missing !== []) {
                throw new CheckCaution(
                    implode(' and ', $missing).' empty — '
                    .'without the first, `revenuecat:reconcile` cannot run; without the second, the webhook '
                    .'would accept anything that posts to it',
                );
            }

            return 'both set';
        });
    }

    // ------------------------------------------- the catalogue, with no network

    private function catalogue(): void
    {
        foreach (['apple' => 'Apple', 'google' => 'Google'] as $store => $label) {
            $this->check($label.' products', function () use ($store): string {
                $products = (array) config("billing.{$store}.products", []);

                if ($products === []) {
                    throw new RuntimeException('none mapped — every purchase in this store would grant nothing');
                }

                $byGrant = [];

                foreach ($products as $id => $mapping) {
                    $byGrant[$mapping['grants'] ?? '(no `grants` key)'][] = $id;
                }

                $unknown = array_diff(array_keys($byGrant), self::GRANTS);

                if ($unknown !== []) {
                    // A typo in `grants` is not a loud failure anywhere else:
                    // `grantsSubscriberAccess()` simply returns false and the
                    // purchase grants nothing. This is where it gets noticed.
                    throw new RuntimeException(
                        'unknown `grants` value '.implode(', ', array_map(fn ($g) => "`{$g}`", $unknown))
                        .' — anything outside '.implode('/', self::GRANTS).' grants nothing, silently',
                    );
                }

                ksort($byGrant);

                return count($products).' mapped — '.implode(', ', array_map(
                    fn (string $grant, array $ids): string => count($ids).' '.$grant,
                    array_keys($byGrant),
                    $byGrant,
                ));
            });
        }

        $this->check('Apple subscription groups and levels', $this->appleLevels(...));
        $this->check('Google base plans', $this->googleBasePlans(...));
        $this->check('sponsee gifts', $this->sponseeGifts(...));
        $this->check('the lifetime consumable', $this->lifetimeConsumable(...));
        $this->check('what the paywall offers', $this->offered(...));
    }

    /**
     * A level is Apple's *only* statement of whether a switch between two
     * products is an upgrade or a downgrade — and so of whether the customer
     * is charged now with credit for unused days, or at the end of the term.
     * A subscription with no level cannot be switched to predictably, and a
     * level is meaningful only within its group.
     */
    private function appleLevels(): string
    {
        $products = (array) config('billing.apple.products', []);
        $groups = [];
        $problems = [];

        foreach ($products as $id => $mapping) {
            $level = $mapping['level'] ?? null;
            $group = $mapping['group'] ?? null;
            $recurring = ($mapping['grants'] ?? null) === 'subscription'
                && in_array($mapping['cycle'] ?? null, self::RECURRING, true);

            if ($level !== null && $group === null) {
                $problems[] = "`{$id}` has a level but no group — a level means nothing on its own";

                continue;
            }

            if ($recurring && $level === null) {
                $problems[] = "`{$id}` is a recurring subscription with no level — Apple cannot rank a switch to it";

                continue;
            }

            if ($group !== null && $level !== null) {
                $groups[$group][(int) $level][] = $id;
            }
        }

        foreach ($groups as $group => $levels) {
            foreach ($levels as $level => $ids) {
                if (count($ids) > 1) {
                    $problems[] = "group `{$group}` has two products at level {$level}: ".implode(' and ', $ids);
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }

        $summary = [];

        foreach ($groups as $group => $levels) {
            ksort($levels);
            $keys = array_keys($levels);
            $summary[] = $group.': '.count($keys).' product'.(count($keys) === 1 ? '' : 's')
                .(min($keys) === max($keys) ? ', level '.min($keys) : ', levels '.min($keys).'–'.max($keys));
        }

        return implode(', ', $summary);
    }

    /**
     * Informational rather than functional — a Google purchase is matched on
     * the product id alone, because that is what the Play API reports — but a
     * base plan this config cannot name is a product nobody has looked at
     * since it was created.
     */
    private function googleBasePlans(): string
    {
        $products = (array) config('billing.google.products', []);
        $without = [];

        foreach ($products as $id => $mapping) {
            $recurring = ($mapping['grants'] ?? null) === 'subscription'
                && in_array($mapping['cycle'] ?? null, self::RECURRING, true);

            // `array_key_exists`, not `??`: an explicit `'base_plan' => null`
            // says "deliberately none" — the same distinction `grants` makes
            // between `none` and simply being absent.
            if ($recurring && ! array_key_exists('base_plan', $mapping)) {
                $without[] = $id;
            }
        }

        if ($without !== []) {
            throw new CheckCaution(
                'no base plan recorded for '.implode(', ', $without)
                .' — harmless in itself, since matching is on the product id, but a recurring product whose '
                .'base plan nobody has written down is a product nobody has looked at. Write `base_plan => null` '
                .'to say there deliberately is not one.',
            );
        }

        return 'every recurring product names its base plan';
    }

    private function sponseeGifts(): string
    {
        $gifts = [];
        $broken = [];

        foreach (['apple', 'google'] as $store) {
            foreach ((array) config("billing.{$store}.products", []) as $id => $mapping) {
                if (($mapping['grants'] ?? null) !== 'sponsee_gift') {
                    continue;
                }

                $gifts[] = $id;

                if ((int) ($mapping['slots'] ?? 0) < 1) {
                    $broken[] = "`{$id}` grants a gift but no slots — the sponsor would pay and get nothing";
                }
            }
        }

        if ($broken !== []) {
            throw new RuntimeException(implode('; ', $broken));
        }

        return count($gifts).' across both stores, each with its slots';
    }

    /**
     * The entry that exists to survive a defect, and so the entry most likely
     * to be "tidied up" by somebody who does not know why it is there.
     *
     * `…profeatures` is a **lifetime unlock sold as a consumable**. Apple does
     * not return consumables from `Transaction.currentEntitlements`, so a
     * restore that reads entitlements loses every one of those buyers on a
     * reinstall or a new phone. They are recoverable only through transaction
     * history, and `restore_via` is what tells the restore path to look there.
     */
    private function lifetimeConsumable(): string
    {
        $id = 'com.12stepapp.recoverybox.profeatures';
        $mapping = ((array) config('billing.apple.products', []))[$id] ?? null;

        if ($mapping === null) {
            throw new RuntimeException(
                "`{$id}` is not mapped — it is the lifetime unlock sold as a consumable, and unmapped it grants nothing",
            );
        }

        if (($mapping['restore_via'] ?? null) !== 'transaction_history') {
            throw new RuntimeException(
                "`{$id}` has lost `restore_via => transaction_history`. It is a consumable, so Apple does not "
                .'return it from currentEntitlements: without this, every lifetime buyer loses access on a reinstall',
            );
        }

        return 'restores through transaction history, as a consumable must';
    }

    private function offered(): string
    {
        $platforms = [
            'ios' => 'apple',
            'android' => 'google',
        ];
        $problems = [];
        $counts = [];

        foreach ($platforms as $platform => $store) {
            $offered = (array) config("billing.offered.{$platform}", []);
            $products = (array) config("billing.{$store}.products", []);
            $counts[] = count($offered).' on '.$platform;

            if ($offered === []) {
                $problems[] = "nothing is offered on {$platform} — the paywall would be empty";
            }

            foreach ($offered as $id) {
                $mapping = $products[$id] ?? null;

                if ($mapping === null) {
                    $problems[] = "{$platform} offers `{$id}`, which is not mapped — somebody would buy it and get nothing";

                    continue;
                }

                if (($mapping['grants'] ?? null) !== 'subscription') {
                    $problems[] = "{$platform} offers `{$id}`, which grants `".($mapping['grants'] ?? 'nothing').'`';
                }

                if ($mapping['hidden'] ?? false) {
                    $problems[] = "{$platform} offers `{$id}`, which is marked hidden";
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }

        return implode(', ', $counts).', all mapped and all granting a subscription';
    }

    // ------------------------------------------------------------- Apple, local

    private function appleKeyFiles(): void
    {
        $this->check('App Store Server key', fn (): string => $this->describeAppleKey(AppleKey::server()));

        $this->check('App Store Connect key', function (): string {
            $connect = AppleKey::connect();
            $detail = $this->describeAppleKey($connect);

            // The config falls back to the Server key when no Connect key is
            // configured. That is usually right — one Team key often does both
            // — but it is also the commonest reason the catalogue read fails
            // while purchases verify, so it is said rather than assumed.
            try {
                if ($connect->path === AppleKey::server()->path) {
                    return $detail.' — the same file as above';
                }
            } catch (\Throwable) {
                // The Server key is reported on its own line; nothing to add.
            }

            return $detail;
        });
    }

    private function describeAppleKey(AppleKey $key): string
    {
        if ($key->isInsideWebRoot()) {
            throw new RuntimeException(
                basename($key->path).' is inside the web root — a private key that a browser can fetch. Move it out.',
            );
        }

        $detail = basename($key->path).', mode '.$key->mode().', key id '.$key->keyId;

        if ($key->isWorldReadable()) {
            throw new CheckCaution($detail.' — readable by every user on this box; `chmod 600`');
        }

        return $detail;
    }

    // ----------------------------------------------------------- Apple, online

    private function appleServerApi(): void
    {
        $this->check('signs a token', function (): string {
            // Local, but it belongs here: it separates "the key will not sign"
            // from "Apple refused what it signed", which are different jobs.
            AppStoreServerApi::forConfiguredEnvironment();
            AppleKey::server()->sign(['iss' => 'probe', 'iat' => time(), 'exp' => time() + 60]);

            return 'ES256, with the key id in the header';
        });

        $this->check('Apple answers', function (): string {
            $api = AppStoreServerApi::forConfiguredEnvironment();

            // Notification history: read-only, no transaction id needed, and
            // its failures are informative. A 24-hour window, ending a minute
            // ago so the end date is never in the future.
            $end = (int) (microtime(true) * 1000) - 60_000;
            $start = $end - 24 * 60 * 60 * 1000;

            return $this->diagnoseAppleServer($api->notificationHistory($start, $end), $api->environment());
        });
    }

    private function diagnoseAppleServer(Response $response, string $environment): string
    {
        if ($response->successful()) {
            $count = count((array) $response->json('notificationHistory', []));

            return "the key works ({$environment}) — Apple has sent "
                .($count === 0 ? 'nothing in the last 24 hours' : $count.' notifications in the last 24 hours');
        }

        $code = (int) ($response->json('errorCode') ?? 0);
        $described = AppStoreServerApi::describeError($response);

        // 4040007: the credentials are GOOD and the notification URL is not set
        // up. A pass for authentication, and a job for the go-live list.
        if ($code === 4040007) {
            throw new CheckCaution(
                "the key works ({$environment}) — but Apple has no App Store Server Notifications V2 URL for this "
                .'app in that environment. App Store Connect → your app → App Information → App Store Server '
                .'Notifications → /api/v2/webhooks/apple. See docs/STORE_SETUP.md',
            );
        }

        if ($code === 4040003 || $code === 4040004) {
            throw new RuntimeException(
                'Apple does not recognise the app: '.$described
                .'. Either APPLE_BUNDLE_ID (`'.config('billing.apple.bundle_id').'`) is wrong, or the key belongs '
                .'to a different team.',
            );
        }

        if ($code === 4000002) {
            throw new RuntimeException(
                'Apple rejected the bundle id in the token: '.$described
                .'. APPLE_BUNDLE_ID is `'.config('billing.apple.bundle_id').'`.',
            );
        }

        if ($response->status() === 401) {
            throw new RuntimeException(
                'Apple rejected the token, and a 401 here carries no body saying why. Three things cause it: '
                .'APPLE_KEY_ID or APPLE_ISSUER_ID does not match the .p8; the .p8 is an App Store Connect key '
                .'rather than an In-App Purchase key (App Store Connect → Users and Access → Integrations → '
                .'In-App Purchase); or the key has been revoked.',
            );
        }

        if ($code === 4290000 || $response->status() === 429) {
            throw new CheckCaution('rate-limited, so the credentials are fine: '.$described);
        }

        if ($response->status() >= 500) {
            throw new CheckCaution("Apple's side, not ours: ".$described);
        }

        throw new RuntimeException($described);
    }

    /**
     * The catalogue key, and the one section here that never fails the command.
     *
     * Nothing about verifying a purchase depends on it: it reads product names
     * and prices for the console's plans page. An In-App Purchase key cannot
     * read this API at all — that needs a Team key — so a server that verifies
     * purchases perfectly well can fail every check in here, and failing the
     * exit code on that would make this command useless as a monitor.
     */
    private function appleConnectApi(): void
    {
        $this->check('Apple answers', function (): string {
            $bundleId = (string) config('billing.apple.bundle_id');
            $response = AppStoreConnectApi::make()->appByBundleId($bundleId);
            $described = AppStoreConnectApi::describeError($response);

            if ($response->status() === 401 || $response->status() === 403) {
                throw new CheckCaution(
                    'this key cannot read the App Store Connect API ('.$described.'). That needs a Team key — '
                    .'App Store Connect → Users and Access → Integrations → App Store Connect API — set as '
                    .'APPLE_CONNECT_KEY_PATH / APPLE_CONNECT_KEY_ID / APPLE_CONNECT_ISSUER_ID. Only the console’s '
                    .'plans page depends on it; purchases do not.',
                );
            }

            if (! $response->successful()) {
                throw new CheckCaution($described);
            }

            $app = $response->json('data.0');

            if (! is_array($app)) {
                throw new CheckCaution(
                    "the key works, but no app with bundle id `{$bundleId}` is visible to it — "
                    .'either the bundle id is wrong or the key is scoped to other apps',
                );
            }

            $name = (string) ($app['attributes']['name'] ?? 'unnamed');
            $appleId = (string) ($app['id'] ?? '');
            $configured = (int) config('billing.apple.app_apple_id');

            if ($configured === 0) {
                throw new CheckCaution(
                    "the key works — the app is “{$name}”, Apple id {$appleId}. Put APPLE_APP_APPLE_ID={$appleId} in .env",
                );
            }

            if ((string) $configured !== $appleId) {
                throw new RuntimeException(
                    "APPLE_APP_APPLE_ID is {$configured}, but Apple says `{$bundleId}` is {$appleId} (“{$name}”)",
                );
            }

            return "“{$name}”, Apple id {$appleId}";
        });
    }

    // ---------------------------------------------------------- Google, local

    private function googleKeyFile(): void
    {
        $this->check('service account', function (): string {
            $key = $this->playKey = ServiceAccountKey::fromConfig();

            if ($key->isInsideWebRoot()) {
                throw new RuntimeException(
                    basename($key->path).' is inside the web root — a private key that a browser can fetch. Move it out.',
                );
            }

            $detail = basename($key->path).', mode '.$key->mode().', '.$key->clientEmail
                .', Cloud project `'.$key->projectId.'`';

            if ($key->isWorldReadable()) {
                throw new CheckCaution($detail.' — readable by every user on this box; `chmod 600`');
            }

            return $detail;
        });
    }

    // --------------------------------------------------------- Google, online

    private function googlePlayApi(): void
    {
        $this->check('token exchange', function (): string {
            $api = PlayDeveloperApi::make($this->playKey);
            $response = $api->requestAccessToken();
            $token = (string) $response->json('access_token');

            if (! $response->successful() || $token === '') {
                $described = PlayDeveloperApi::describeTokenError($response);

                if ((string) $response->json('error') === 'invalid_grant') {
                    throw new RuntimeException(
                        'Google refused the key itself: '.$described
                        .'. That is `invalid_grant`, which means one of: the key has been deleted or revoked, the '
                        .'service account is disabled, or this server\'s clock is more than a few minutes out. '
                        .'Nothing to do with Play Console permissions.',
                    );
                }

                throw new RuntimeException($described);
            }

            $this->playToken = $token;

            // Never the token itself.
            return 'Google issued an access token for '.($this->playKey?->clientEmail ?? 'the service account');
        });

        $this->check('one-time products', function (): string {
            $response = $this->withPlayToken(
                fn (PlayDeveloperApi $api, string $token): Response => $api->inAppProducts(100, $token),
            );

            $skus = array_values(array_filter(array_map(
                fn ($product) => is_array($product) ? (string) ($product['sku'] ?? '') : '',
                (array) $response->json('inappproduct', []),
            )));

            $partial = $response->json('tokenPagination.nextPageToken') !== null;

            return $this->compareWithGoogleConfig(
                'one-time product',
                $skus,
                fn (array $mapping): bool => ($mapping['cycle'] ?? null) === 'lifetime'
                    || ($mapping['grants'] ?? null) === 'sponsee_gift'
                    || ($mapping['grants'] ?? null) === 'none',
                $partial,
            );
        });

        $this->check('subscriptions', function (): string {
            $response = $this->withPlayToken(
                fn (PlayDeveloperApi $api, string $token): Response => $api->subscriptions(100, $token),
            );

            $rows = (array) $response->json('subscriptions', []);
            $ids = [];
            $basePlanMismatches = [];
            $products = (array) config('billing.google.products', []);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id = (string) ($row['productId'] ?? '');

                if ($id === '') {
                    continue;
                }

                $ids[] = $id;
                $configured = $products[$id]['base_plan'] ?? null;

                if ($configured === null) {
                    continue;
                }

                $live = array_map(
                    fn ($plan) => is_array($plan) ? (string) ($plan['basePlanId'] ?? '') : '',
                    (array) ($row['basePlans'] ?? []),
                );

                if (! in_array((string) $configured, $live, true)) {
                    $basePlanMismatches[] = "`{$id}` is recorded as base plan `{$configured}`, Play says "
                        .($live === [] ? 'it has none' : implode('/', $live));
                }
            }

            $summary = $this->compareWithGoogleConfig(
                'subscription',
                $ids,
                fn (array $mapping): bool => in_array($mapping['cycle'] ?? null, self::RECURRING, true)
                    && ($mapping['grants'] ?? null) === 'subscription',
                $response->json('nextPageToken') !== null,
                $basePlanMismatches,
            );

            return $summary;
        });
    }

    /** Run a Play call with the token the exchange above proved. */
    private function withPlayToken(callable $call): Response
    {
        if ($this->playToken === null) {
            throw new CheckCaution('not asked — the token exchange above did not succeed');
        }

        $api = PlayDeveloperApi::make($this->playKey);
        $response = $call($api, $this->playToken);

        if (! $response->successful()) {
            $this->refusePlay($response, $api);
        }

        return $response;
    }

    /**
     * The three-way split the whole Google half of this command exists for.
     *
     * Google took the token — the key is fine — and Play still said no. The
     * reason is one of three things, and they are fixed in three different
     * consoles by three different people.
     */
    private function refusePlay(Response $response, PlayDeveloperApi $api): never
    {
        $reason = PlayDeveloperApi::reason($response);
        $message = (string) ($response->json('error.message') ?? '');
        $described = PlayDeveloperApi::describeError($response);
        $project = $this->playKey?->projectId ?? '';

        $notEnabled = $reason === 'accessNotConfigured'
            || str_contains($message, 'has not been used in project')
            || str_contains($message, 'SERVICE_DISABLED')
            || str_contains($message, 'is disabled');

        if ($notEnabled) {
            $number = PlayDeveloperApi::projectNumberFromMessage($response);

            throw new RuntimeException(
                'the Google Play Android Developer API is not enabled in the Cloud project this key came from'
                .($number === null ? '' : " (project {$number})")
                .'. Enable it at https://console.cloud.google.com/apis/library/androidpublisher.googleapis.com'
                .($project === '' ? '' : "?project={$project}")
                .'. This is a Cloud Console setting and has nothing to do with Play Console permissions — '
                .'it must be enabled in the project the KEY belongs to, which is not necessarily the one '
                .'another integration uses. ('.$described.')',
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(
                'Google accepted the key and Play refused the call: '.$described
                .'. The service account is not granted access to this app in the Play Console — '
                .'Users and permissions → invite '.($this->playKey?->clientEmail ?? 'the service account')
                .' → add this app → grant "View financial data, orders, and cancellation survey responses". '
                .'That is a separate system from Cloud IAM, and a fresh grant takes a few minutes to propagate.',
            );
        }

        if ($response->status() === 404) {
            throw new RuntimeException(
                'Play has no app with package name `'.$api->packageName().'`: '.$described
                .'. Check GOOGLE_PACKAGE_NAME.',
            );
        }

        if ($response->status() >= 500) {
            throw new CheckCaution("Google's side, not ours: ".$described);
        }

        throw new RuntimeException($described);
    }

    /**
     * What the store holds against what this config maps.
     *
     * The useful direction is store → config: an id the store knows and this
     * config does not is a product somebody can buy that would grant them
     * nothing. The other direction is expected and only noted — a retired
     * product disappears from a store console while purchases of it still have
     * to be honoured for ever.
     *
     * @param  array<int, string>  $live
     * @param  callable(array): bool  $belongsHere
     * @param  array<int, string>  $extraCautions
     */
    private function compareWithGoogleConfig(
        string $noun,
        array $live,
        callable $belongsHere,
        bool $partial,
        array $extraCautions = [],
    ): string {
        $products = (array) config('billing.google.products', []);
        $live = array_values(array_unique(array_filter($live)));

        $unmapped = array_values(array_diff($live, array_keys($products)));
        $onlyHere = array_values(array_filter(
            array_diff(array_keys($products), $live),
            fn (string $id): bool => $belongsHere((array) $products[$id]),
        ));

        $cautions = $extraCautions;

        if ($unmapped !== []) {
            $cautions[] = 'Play has '.count($unmapped).' '.$noun.' id'.(count($unmapped) === 1 ? '' : 's')
                .' this config does not map: '.implode(', ', $unmapped)
                .' — a purchase of one would grant nothing';
        }

        if ($partial) {
            $cautions[] = 'the list was truncated by paging, so this is not the whole catalogue';
        }

        $summary = 'Play reports '.count($live).' '.$noun.(count($live) === 1 ? '' : 's');

        if ($onlyHere !== []) {
            $summary .= '; '.count($onlyHere).' mapped here that Play no longer lists ('
                .implode(', ', $onlyHere).'), which is normal for retired products';
        }

        if ($cautions !== []) {
            throw new CheckCaution($summary.'. '.implode('. ', $cautions));
        }

        return $summary.', all mapped';
    }
}
