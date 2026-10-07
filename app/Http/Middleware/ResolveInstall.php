<?php

namespace App\Http\Middleware;

use App\Models\Install;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves `X-Install-Token` to an install, for the v2 endpoints.
 *
 * An install is not an account. It is the phone: what to push to, which app
 * version is running, which time zone it is in. The old system kept that on
 * `accounts.fcm_token` — one token per *person* — so a second phone silently
 * took the first one's notifications away.
 *
 * Only the SHA-256 of the token is stored.
 */
class ResolveInstall
{
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $token = (string) $request->header('X-Install-Token', '');

        $install = $token === '' ? null : Install::query()
            ->where('token_hash', Install::hashToken($token))
            ->first();

        if ($install === null && $mode === 'required') {
            return ApiResponse::error('This install is not registered.', 401, ['code' => ['install_unknown']]);
        }

        if ($install !== null) {
            $install->touchSeen(
                appVersion: $request->header('X-App-Version'),
                accountId: $request->user()?->getAuthIdentifier(),
            );
            $request->attributes->set('install', $install);
        }

        return $next($request);
    }
}
