<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\PersonalAccessToken;
use App\Services\Legacy\LegacyEnvelope;
use App\Services\Legacy\LegacyJwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer token half of the Android authentication.
 *
 * `19/db.php:42` is `if (REQUIRE_AUTH) { $ACCOUNT_ID = require_valid_jwt(...); }`
 * and that is most of what this does. The additions:
 *
 *  * the whole legacy surface can be switched off (`LEGACY_V19_ENABLED`);
 *  * the account is put on the request as `legacy_account`, so nothing
 *    downstream has to trust a field in the body;
 *  * **where the token came from is recorded**, which is the subject of the
 *    next paragraph and is the reason this file is not six lines long.
 *
 * ## Why provenance matters
 *
 * The live server's `19/jwt_verify.php:11` is:
 *
 *     const JWT_SECRET = 'CHANGE_ME_TO_A_…'; // move to env/config
 *
 * It is the library's own template placeholder, `db.php` requires that file on
 * every endpoint, and `require_valid_jwt()` validates bearer tokens with it. So
 * anybody can sign `{"sub": <any account id>}` and hold a valid token for that
 * account.
 *
 * This application has to accept tokens signed with that secret, because every
 * Android install in the field holds one and refusing them means refusing the
 * field. The owner's decision (2026-10-07) is to leave the old server alone and
 * handle this properly here. So:
 *
 *  * a token signed with the legacy secret is marked **`legacy`**;
 *  * a Sanctum token this server issued is marked **`server`**;
 *  * and `bootstrap_secret.php` — the one endpoint that hands out a signing key
 *    to token-only auth, and therefore the one that turns a forged token into
 *    full access — refuses to mint or rotate for a `legacy` token.
 *
 * What a forged token can do, with that rule in place: retrieve the signing key
 * for a `(account_id, device_id)` pair **that already exists**, which means
 * guessing a real install's device id, which it cannot do. It cannot register a
 * new device, so it cannot obtain a key of its own, so it cannot sign anything.
 * Every endpoint that touches somebody's step work needs a signature.
 *
 * Sealing is deliberately **not** applied here. This path is properly
 * authenticated at the signature layer and it is the path both the Android app
 * and the new Flutter app use. What sealing closes is the Apple path, whose
 * whole authentication is a secret printed in a web page.
 */
class VerifyLegacyJwt
{
    /** The token was issued by this server, through a sign-in it verified. */
    public const FROM_SERVER = 'server';

    /** The token was signed with the old server's secret. Trusted to identify, not to authorise. */
    public const FROM_LEGACY = 'legacy';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('legacy.android.enabled')) {
            return LegacyEnvelope::fail('Gone', 410);
        }

        $token = LegacyJwt::tokenFromRequest($request);

        if ($token === '') {
            return LegacyEnvelope::fail('Unauthorized', 401);
        }

        [$accountId, $provenance] = $this->identify($token);

        if ($accountId === null) {
            return LegacyEnvelope::fail('Unauthorized', 401);
        }

        $account = Account::query()->find($accountId);

        if ($account === null) {
            return LegacyEnvelope::fail('Unauthorized', 401);
        }

        $request->attributes->set('legacy_account', $account);
        $request->attributes->set('legacy_token_provenance', $provenance);

        return $next($request);
    }

    /**
     * Sanctum first, then the legacy JWT.
     *
     * The two are not confusable: a Sanctum token is `<id>|<plain>` and a JWT is
     * three base64url segments separated by dots. Trying Sanctum first costs
     * one indexed lookup and means a properly-issued token is never mistaken
     * for a legacy one.
     *
     * @return array{0: int|null, 1: string}
     */
    private function identify(string $token): array
    {
        if (str_contains($token, '|')) {
            $personal = PersonalAccessToken::findToken($token);

            if ($personal !== null && ! $personal->isExpired()) {
                $personal->forceFill(['last_used_at' => now()])->save();

                return [(int) $personal->tokenable_id, self::FROM_SERVER];
            }

            // A malformed or expired Sanctum token is not then tried as a JWT.
            return [null, self::FROM_SERVER];
        }

        return [LegacyJwt::make()->accountId($token), self::FROM_LEGACY];
    }
}
