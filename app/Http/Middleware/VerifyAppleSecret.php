<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Apple API's whole authentication: one static secret, posted as
 * `serversecret`.
 *
 * `8/db.php` compares it with `!=` — loose, and not constant time — and calls
 * `exit(0)` on a mismatch, so the client sees an empty 200. That last detail is
 * reproduced, because an old client that gets a 401 shows a different error
 * than one that gets an empty body, and the point of this layer is that nothing
 * about it looks new to an app from 2021.
 *
 * What is not reproduced: `!=`. `hash_equals` instead, and an empty configured
 * secret refuses everything rather than matching an empty POST field.
 */
class VerifyAppleSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('legacy.apple.enabled')) {
            return response('', 410);
        }

        $expected = (string) config('legacy.apple.server_secret');
        $given = (string) $request->input('serversecret', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            // `exit(0)`: an empty 200, which is what every build in the field
            // already knows how to interpret.
            return response('', 200);
        }

        return $next($request);
    }
}
