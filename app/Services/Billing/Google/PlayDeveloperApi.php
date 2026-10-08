<?php

namespace App\Services\Billing\Google;

use App\Services\Billing\StoreCredentialException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The Google Play Android Developer API: purchases, orders and the catalogue.
 *
 * ## Authentication is two hops, and they fail differently
 *
 * 1. **The token exchange** — a signed assertion to Google's OAuth endpoint.
 *    This hop knows nothing about Play. It fails when the *key* is wrong:
 *    revoked, deleted, from a disabled service account, or signed by a clock
 *    that disagrees with Google's by more than a few minutes. Google's answer
 *    is `invalid_grant`, and it is the same answer for all of those.
 *
 * 2. **The API call** — the token against androidpublisher. This hop fails for
 *    reasons that have nothing to do with the key: the API not enabled in the
 *    key's Cloud project (403, `accessNotConfigured`), or the service account
 *    not granted access in the **Play Console**, which is a separate system
 *    from Cloud IAM and takes minutes to propagate.
 *
 * Collapsing the two into "auth failed" is why this integration has a
 * reputation for being hard to set up. `billing:check` reports them apart.
 *
 * Nothing here retries, and nothing here logs the assertion or the token.
 */
final class PlayDeveloperApi
{
    public const BASE_URL = 'https://androidpublisher.googleapis.com/androidpublisher/v3';

    public function __construct(
        private readonly ServiceAccountKey $key,
        private readonly string $packageName,
    ) {}

    public static function make(?ServiceAccountKey $key = null): self
    {
        return new self(
            $key ?? ServiceAccountKey::fromConfig(),
            (string) config('billing.google.package_name'),
        );
    }

    public function packageName(): string
    {
        return $this->packageName;
    }

    // --------------------------------------------------------------- hop one

    /** The raw token exchange, so a caller can read Google's own refusal. */
    public function requestAccessToken(): Response
    {
        return Http::timeout((int) config('billing.google.timeout', 15))
            ->asForm()
            ->acceptJson()
            ->post($this->key->tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->key->assertion(),
            ]);
    }

    /**
     * An access token, cached for fifty minutes of its sixty.
     *
     * [$fresh] skips the cache — which `billing:check` always does, because a
     * cached token proves nothing about whether the key still works.
     */
    public function accessToken(bool $fresh = false): string
    {
        $cacheKey = 'play:token:'.sha1($this->key->clientEmail);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(50), function (): string {
            $response = $this->requestAccessToken();
            $token = (string) $response->json('access_token');

            if (! $response->successful() || $token === '') {
                throw new StoreCredentialException(self::describeTokenError($response));
            }

            return $token;
        });
    }

    // --------------------------------------------------------------- hop two

    public function get(string $path, array $query = [], ?string $token = null): Response
    {
        return Http::withToken($token ?? $this->accessToken())
            ->timeout((int) config('billing.google.timeout', 15))
            ->acceptJson()
            ->get(self::BASE_URL.$path, $query);
    }

    /** One-time products. Read-only, and needs nothing but the package name. */
    public function inAppProducts(int $max = 1, ?string $token = null): Response
    {
        return $this->get(
            '/applications/'.$this->packageName.'/inappproducts',
            ['maxResults' => $max],
            $token,
        );
    }

    /** Subscriptions, with their base plans. Read-only. */
    public function subscriptions(int $pageSize = 1, ?string $token = null): Response
    {
        return $this->get(
            '/applications/'.$this->packageName.'/subscriptions',
            ['pageSize' => $pageSize],
            $token,
        );
    }

    // ------------------------------------------------------------ the errors

    public static function describeTokenError(Response $response): string
    {
        $error = (string) ($response->json('error') ?? '');
        $description = (string) ($response->json('error_description') ?? '');

        if ($error === '' && $description === '') {
            return 'HTTP '.$response->status().': '.Str::limit(trim((string) $response->body()), 200);
        }

        return 'HTTP '.$response->status().' '.$error.($description === '' ? '' : ' — '.$description);
    }

    public static function describeError(Response $response): string
    {
        $message = $response->json('error.message');

        if (! is_string($message) || $message === '') {
            return 'HTTP '.$response->status().': '.Str::limit(trim((string) $response->body()), 200);
        }

        $status = (string) ($response->json('error.status') ?? '');

        return 'HTTP '.$response->status().($status === '' ? '' : ' '.$status).' — '.$message;
    }

    /** Google's machine-readable `reason`, e.g. `accessNotConfigured`. */
    public static function reason(Response $response): string
    {
        return (string) ($response->json('error.errors.0.reason') ?? '');
    }

    /**
     * The project **number** out of a `SERVICE_DISABLED` message.
     *
     * Google names the project by number there, while the key file names it by
     * id. Reporting both is what lets somebody confirm they are the same
     * project rather than assuming it.
     */
    public static function projectNumberFromMessage(Response $response): ?string
    {
        $message = (string) ($response->json('error.message') ?? '');

        return preg_match('/project\s+(\d{4,})/i', $message, $m) === 1 ? $m[1] : null;
    }
}
