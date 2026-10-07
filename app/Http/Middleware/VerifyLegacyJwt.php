<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Legacy\LegacyEnvelope;
use App\Services\Legacy\LegacyJwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer token half of the Android authentication.
 *
 * `19/db.php:42` is `if (REQUIRE_AUTH) { $ACCOUNT_ID = require_valid_jwt(...); }`
 * and that is all this does — with two additions:
 *
 *  * the whole legacy surface can be switched off (`LEGACY_V19_ENABLED`);
 *  * the account is put on the request as `legacy_account`, so nothing
 *    downstream has to trust a field in the body.
 *
 * Sealing is deliberately **not** applied here. This path is properly
 * authenticated — a bearer token plus an install-keyed signature on every
 * endpoint that matters — and it is the path both the Android app and the new
 * Flutter app use. What sealing closes is the Apple path, whose whole
 * authentication is a secret printed in a web page.
 */
class VerifyLegacyJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('legacy.android.enabled')) {
            return LegacyEnvelope::fail('Gone', 410);
        }

        $accountId = LegacyJwt::make()->accountId(LegacyJwt::tokenFromRequest($request));
        if ($accountId === null) {
            return LegacyEnvelope::fail('Unauthorized', 401);
        }

        $account = Account::query()->find($accountId);
        if ($account === null) {
            return LegacyEnvelope::fail('Unauthorized', 401);
        }

        $request->attributes->set('legacy_account', $account);

        return $next($request);
    }
}
