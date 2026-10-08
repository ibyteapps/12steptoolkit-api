<?php

namespace App\Services\Billing\Apple;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The App Store **Connect** API: the catalogue rather than the purchases.
 *
 * Used for the console's plans page — product names, prices, periods and
 * introductory offers, so that the catalogue shown to staff is the one Apple
 * actually holds rather than a copy of it in this repo.
 *
 * Same `aud` as the Server API and the same ES256 signing, but **no `bid`
 * claim**: this API is scoped to the team, not to one app. A key issued for
 * In-App Purchase is refused here with a bare 401, and a Team key is refused by
 * the Server API the same way, which is why `billing:check` asks both.
 */
final class AppStoreConnectApi
{
    public const BASE_URL = 'https://api.appstoreconnect.apple.com';

    public function __construct(private readonly AppleKey $key) {}

    public static function make(?AppleKey $key = null): self
    {
        return new self($key ?? AppleKey::connect());
    }

    /**
     * The app record for a bundle id.
     *
     * Doubles as this API's health probe, and earns its place twice over: a 200
     * carries the app's numeric Apple id, which is the value
     * `APPLE_APP_APPLE_ID` wants and which is otherwise copied by hand off a
     * web page.
     */
    public function appByBundleId(string $bundleId): Response
    {
        return $this->get('/v1/apps', [
            'filter[bundleId]' => $bundleId,
            'limit' => 1,
        ]);
    }

    public function get(string $path, array $query = []): Response
    {
        return Http::withToken($this->token())
            ->timeout((int) config('billing.apple.timeout', 15))
            ->acceptJson()
            ->get(self::BASE_URL.$path, $query);
    }

    private function token(): string
    {
        $now = time();

        return $this->key->sign([
            'iss' => $this->key->issuerId,
            'iat' => $now,
            'exp' => $now + 600,
            'aud' => 'appstoreconnect-v1',
        ]);
    }

    /**
     * The first of Apple's JSON:API errors, in one line.
     *
     * Shape is `{"errors":[{"status","code","title","detail"}]}` — different
     * from the Server API's flat `errorCode`, which is why this is its own
     * method rather than a shared one.
     */
    public static function describeError(Response $response): string
    {
        $first = $response->json('errors.0');

        if (! is_array($first)) {
            $raw = trim((string) $response->body());

            return $raw === ''
                ? 'HTTP '.$response->status().', empty body'
                : 'HTTP '.$response->status().': '.Str::limit($raw, 200);
        }

        return 'HTTP '.$response->status().' '.($first['code'] ?? '')
            .' '.($first['detail'] ?? $first['title'] ?? '');
    }
}
