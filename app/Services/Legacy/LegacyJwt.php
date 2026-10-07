<?php

namespace App\Services\Legacy;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

/**
 * The HS256 token the v19 scripts issue and check.
 *
 * Kept because it is what every Android install and every Flutter build already
 * holds. What is not kept is its lifetime: `issue_jwt()` is called with
 * `$ttlSeconds = 296000000` — nine years and five months — on every login path,
 * with no revocation list anywhere. A token that leaked was an account, for
 * ever. New tokens get `toolkit.auth.token_ttl_days`, and `install_secrets`
 * gives a second factor that *can* be revoked.
 *
 * Claims, as `19/jwt_verify.php` mints them: `iss`, `iat`, `nbf = now - 5`,
 * `exp`, `sub = accountId`. The 60-second leeway is theirs too.
 */
final class LegacyJwt
{
    public const LEEWAY_SECONDS = 60;

    public function __construct(private readonly string $secret, private readonly string $issuer) {}

    public static function make(): self
    {
        return new self(
            (string) config('legacy.android.jwt_secret'),
            (string) config('legacy.android.jwt_issuer'),
        );
    }

    public function configured(): bool
    {
        return $this->secret !== '';
    }

    /** @return int|null the account id, or null when the token is not usable */
    public function accountId(string $token): ?int
    {
        if (! $this->configured() || $token === '') {
            return null;
        }

        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        $sub = $decoded->sub ?? null;

        return is_numeric($sub) && (int) $sub > 0 ? (int) $sub : null;
    }

    public function issue(int $accountId, ?int $ttlSeconds = null): array
    {
        $now = time();
        $ttl = $ttlSeconds ?? (int) config('toolkit.auth.token_ttl_days', 60) * 86400;
        $expires = $now + $ttl;

        $token = JWT::encode([
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $expires,
            'sub' => $accountId,
        ], $this->secret, 'HS256');

        return ['access_token' => $token, 'expires_at' => $expires];
    }

    /**
     * The token, from wherever the old scripts look for it.
     *
     * `jwt_verify.php:get_bearer_token()` reads `Authorization`, then
     * `REDIRECT_HTTP_AUTHORIZATION`, then `getallheaders()`, and then — as
     * deliberate fallbacks — `$_POST['token']`, `$_GET['token']` and a `token`
     * key in the JSON body. The fallbacks are kept because clients in the field
     * use them, and they are the reason a token can end up in a web server's
     * access log.
     */
    public static function tokenFromRequest(Request $request): string
    {
        $header = (string) ($request->header('Authorization') ?? $request->server->get('REDIRECT_HTTP_AUTHORIZATION', ''));
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m) === 1) {
            return $m[1];
        }

        $fromInput = $request->input('token');

        return is_string($fromInput) ? $fromInput : '';
    }
}
