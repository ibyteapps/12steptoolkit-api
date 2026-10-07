<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before the `web` middleware on /console only. The API and the website are
 * stateless (SESSION_DRIVER=array in .env); the console needs a real session, so it
 * gets its own here rather than changing the setting for everything else.
 */
class ConsoleSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.driver' => (string) config('console.session_driver', 'database'),
            'session.cookie' => 'tst_console',
            'session.path' => '/console',
            'session.lifetime' => (int) config('console.session_minutes', 720),
            'session.expire_on_close' => false,
            'session.secure' => $request->isSecure(),
            'session.http_only' => true,
            'session.same_site' => 'strict',
            'auth.defaults.guard' => 'console',
        ]);

        $response = $next($request);

        // Never cached, never indexed (SecurityHeaders adds: never framed).
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
