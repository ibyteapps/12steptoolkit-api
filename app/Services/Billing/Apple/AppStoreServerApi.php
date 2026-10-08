<?php

namespace App\Services\Billing\Apple;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The App Store Server API: what Apple will tell us about a purchase.
 *
 * Thin on purpose. It mints the token, sends the request and hands back the
 * response untouched — including the 4xx ones, because for a diagnostic the
 * exact status and Apple's own `errorCode` *are* the answer. Nothing here
 * retries, and nothing here logs a transaction id or a token.
 *
 * Signing follows Apple's "Generating JSON Web Tokens for API requests": ES256,
 * `kid` in the header, and a payload of `iss` / `iat` / `exp` / `aud` plus the
 * **`bid`** claim naming the app. `bid` is what separates this from the App
 * Store Connect API, which uses the same `aud` and rejects the claim.
 */
final class AppStoreServerApi
{
    public const PRODUCTION = 'https://api.storekit.itunes.apple.com';

    public const SANDBOX = 'https://api.storekit-sandbox.itunes.apple.com';

    public function __construct(
        private readonly AppleKey $key,
        private readonly string $bundleId,
        private readonly string $baseUrl,
    ) {}

    public static function forConfiguredEnvironment(?AppleKey $key = null): self
    {
        $sandbox = config('billing.apple.environment') === 'sandbox';

        return new self(
            $key ?? AppleKey::server(),
            (string) config('billing.apple.bundle_id'),
            $sandbox ? self::SANDBOX : self::PRODUCTION,
        );
    }

    public function environment(): string
    {
        return $this->baseUrl === self::SANDBOX ? 'sandbox' : 'production';
    }

    /**
     * Notification history for a window.
     *
     * Chosen as this API's health probe because it is read-only, needs no
     * transaction id, and its failure modes are informative: a `4040007` means
     * the credentials are **good** and it is the notification URL that is not
     * set up yet, which is a different sentence from a 401.
     */
    public function notificationHistory(int $startMs, int $endMs): Response
    {
        return $this->send('POST', '/inApps/v1/notifications/history', [
            'startDate' => $startMs,
            'endDate' => $endMs,
        ]);
    }

    public function get(string $path): Response
    {
        return $this->send('GET', $path);
    }

    private function send(string $method, string $path, array $body = []): Response
    {
        $request = Http::withToken($this->token())
            ->timeout((int) config('billing.apple.timeout', 15))
            ->acceptJson();

        return $method === 'GET'
            ? $request->get($this->baseUrl.$path)
            : $request->asJson()->post($this->baseUrl.$path, $body);
    }

    private function token(): string
    {
        $now = time();

        return $this->key->sign([
            'iss' => $this->key->issuerId,
            'iat' => $now,
            // Apple's ceiling is 60 minutes. Ten is plenty for one request and
            // limits what a leaked token could do.
            'exp' => $now + 600,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->bundleId,
            'nonce' => (string) Str::uuid(),
        ]);
    }

    /**
     * Apple's own words for a failed request, in one line.
     *
     * A 401 carries **no body at all**, which is itself worth saying out loud:
     * it is the one response that tells you nothing about why.
     */
    public static function describeError(Response $response): string
    {
        $code = $response->json('errorCode');
        $message = $response->json('errorMessage');

        if ($code === null && $message === null) {
            $raw = trim((string) $response->body());

            return $raw === ''
                ? 'HTTP '.$response->status().', empty body'
                : 'HTTP '.$response->status().': '.Str::limit($raw, 200);
        }

        return 'HTTP '.$response->status().' '.$code.' '.$message;
    }
}
