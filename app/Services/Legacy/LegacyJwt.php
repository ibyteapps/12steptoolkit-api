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
 *
 * ## This class verifies. It does not issue, outside the tests.
 *
 * `19/jwt_verify.php:11` is `const JWT_SECRET = 'CHANGE_ME_TO_A_…'` — the
 * library's own template placeholder — and `db.php` requires that file on every
 * endpoint. So a valid signature here proves that *somebody* who knows a
 * published string said this account id, and nothing more.
 *
 * Tokens like that must still be accepted, because every install in the field
 * holds one. But this server issues **Sanctum** tokens instead
 * (`AuthController::signedIn`), which makes the distinction exact and needs
 * nothing looked up: a legacy-format token did not come from here.
 * `VerifyLegacyJwt` marks it, and `bootstrap_secret.php` refuses to hand it a
 * signing key.
 *
 * `issue()` therefore has one caller left — the test suite, where it plays the
 * part of the old server. That is worth keeping: it is how the provenance rule
 * is tested against a token indistinguishable from a real forged one.
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
        return $this->claims($token)['account_id'] ?? null;
    }

    /**
     * The account id and the `jti`, or an empty array.
     *
     * Both are needed together: the signature says which account the token
     * names, and the `jti` says whether this server is the one that said so.
     *
     * @return array{account_id?: int, jti?: string}
     */
    public function claims(string $token): array
    {
        if (! $this->configured() || $token === '') {
            return [];
        }

        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable) {
            return [];
        }

        $sub = $decoded->sub ?? null;

        if (! is_numeric($sub) || (int) $sub <= 0) {
            return [];
        }

        $jti = $decoded->jti ?? null;

        return [
            'account_id' => (int) $sub,
            'jti' => is_string($jti) ? $jti : '',
        ];
    }

    /**
     * Mint a legacy-format token.
     *
     * **Not used in production.** The tests use it to play the old server; see
     * the note at the top of this class.
     *
     * @return array{access_token: string, expires_at: int, jti: string}
     */
    public function issue(int $accountId, ?int $ttlSeconds = null): array
    {
        $now = time();
        $ttl = $ttlSeconds ?? (int) config('toolkit.auth.token_ttl_days', 60) * 86400;
        $expires = $now + $ttl;
        $jti = bin2hex(random_bytes(16));

        $token = JWT::encode([
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $expires,
            'sub' => $accountId,
            'jti' => $jti,
        ], $this->secret, 'HS256');

        return ['access_token' => $token, 'expires_at' => $expires, 'jti' => $jti];
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
