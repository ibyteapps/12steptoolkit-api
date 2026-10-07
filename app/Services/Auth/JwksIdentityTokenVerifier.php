<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google or Apple identity token against the provider's published
 * keys: signature, issuer, audience and expiry.
 *
 * The old API verified none of those. `19/login_google.php` takes an email
 * address and issues a nine-year token for whatever account has it
 * (`SRV-010`); the client's word was the whole of the authentication.
 *
 * Keys are cached for an hour, with one refresh when a `kid` is not recognised
 * — which is what happens on the day a provider rotates.
 */
final class JwksIdentityTokenVerifier implements IdentityTokenVerifier
{
    public function __construct(
        private readonly string $jwksUrl,
        private readonly array $issuers,
        private readonly array $audiences,
    ) {}

    public function verify(string $token): ?VerifiedIdentity
    {
        if ($token === '' || $this->jwksUrl === '' || $this->audiences === []) {
            return null;
        }

        $claims = $this->decode($token, refresh: false) ?? $this->decode($token, refresh: true);
        if ($claims === null) {
            return null;
        }

        $issuer = (string) ($claims['iss'] ?? '');
        if (! in_array($issuer, $this->issuers, true)) {
            return null;
        }

        $audience = $claims['aud'] ?? '';
        $audience = is_array($audience) ? $audience : [$audience];
        if (array_intersect($audience, $this->audiences) === []) {
            return null;
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            return null;
        }

        $verified = ($claims['email_verified'] ?? false);
        $verified = $verified === true || $verified === 'true';

        return new VerifiedIdentity($subject, $claims['email'] ?? null, $verified);
    }

    private function decode(string $token, bool $refresh): ?array
    {
        $keys = $this->keys($refresh);
        if ($keys === []) {
            return null;
        }

        try {
            return (array) JWT::decode($token, JWK::parseKeySet(['keys' => $keys]));
        } catch (\Throwable) {
            return null;
        }
    }

    private function keys(bool $refresh): array
    {
        $cacheKey = 'jwks:'.sha1($this->jwksUrl);
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHour(), function (): array {
            try {
                $response = Http::timeout(10)->get($this->jwksUrl);
            } catch (\Throwable) {
                return [];
            }

            return $response->successful() ? (array) ($response->json('keys') ?? []) : [];
        });
    }
}
