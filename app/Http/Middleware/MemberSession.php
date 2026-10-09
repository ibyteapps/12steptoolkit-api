<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member area's session, kept apart from the console's.
 *
 * Its own cookie and its own path, so a member signing in cannot touch a
 * staff session and a staff session cannot be mistaken for a member's. The
 * default guard is switched here rather than in every controller, which is
 * what stops `Auth::id()` in this area ever meaning a console user.
 *
 * Never cached and never indexed: these pages are somebody's Step work.
 */
class MemberSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.driver' => (string) config('my.session_driver', 'database'),
            'session.cookie' => 'tst_my',
            'session.path' => '/my',
            'session.lifetime' => (int) config('my.session_minutes', 20160),
            'session.expire_on_close' => false,
            'session.secure' => $request->isSecure(),
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'auth.defaults.guard' => 'member',
        ]);

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
