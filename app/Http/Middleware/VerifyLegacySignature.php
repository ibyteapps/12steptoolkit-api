<?php

namespace App\Http\Middleware;

use App\Services\Legacy\HmacVerifier;
use App\Services\Legacy\LegacyEnvelope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Both halves: the token, then the signature.
 *
 * `19/db.php:44-50` refuses outright if HMAC is on and JWT is off —
 * *"Server misconfig: HMAC requires JWT auth"* — and that ordering is kept:
 * the signature is only meaningful once the request has named an account.
 *
 * The reason a signature failed goes to the log and never into the response.
 * A client that is told "clock skew" is being helped; an attacker that is told
 * "no secret for that device" is being helped too.
 */
class VerifyLegacySignature
{
    public function __construct(private readonly VerifyLegacyJwt $jwt, private readonly HmacVerifier $hmac) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->jwt->handle($request, function (Request $request) use ($next) {
            $account = $request->attributes->get('legacy_account');
            $reason = $this->hmac->verify($request, (int) $account->id);

            if ($reason !== HmacVerifier::OK) {
                Log::info('legacy signature refused', [
                    'reason' => $reason,
                    'account_id' => $account->id,
                    'path' => $request->path(),
                ]);

                return LegacyEnvelope::fail('Unauthorized', 401);
            }

            return $next($request);
        });
    }
}
