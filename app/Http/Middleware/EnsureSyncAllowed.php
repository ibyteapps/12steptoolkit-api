<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may keep a copy of their writing on the server.
 *
 * `DECISIONS.md` D-001 says everybody, free, and `config/toolkit.php` defaults
 * `sync.subscription_required` to false. The switch exists so that turning
 * backup into a paid feature would be one visible, deliberate act rather than
 * an `if` somebody adds to a controller — which is how the Android app came to
 * drop every free user's inventories on the floor.
 */
class EnsureSyncAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('toolkit.sync.subscription_required')) {
            return $next($request);
        }

        $account = $request->user();
        if ($account !== null && $account->entitlement?->is_active) {
            return $next($request);
        }

        return ApiResponse::error('A subscription is needed to back up.', 402, ['code' => ['sync_requires_subscription']]);
    }
}
