<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // The console (staff back office) is never framed and tells other sites nothing.
        $console = $request->is('console', 'console/*');
        $response->headers->set('X-Frame-Options', $request->is('api/*') || $console ? 'DENY' : 'SAMEORIGIN');
        // The website keeps the browser default so outbound links (App Store, Google Play)
        // still carry the origin; the API sends nothing.
        $response->headers->set('Referrer-Policy', match (true) {
            // The page behind the email's "Sign in" link: its address holds the link's secret.
            $request->is('api/*'), $request->is('sign-in/*') => 'no-referrer',
            $console => 'same-origin',
            default => 'strict-origin-when-cross-origin',
        });

        /*
         | Out of search results. Two reasons, and the console's does not
         | depend on a switch:
         |
         |  * **the console, always.** A staff login page has no business in
         |    an index, and `site.noindex` goes false the day this application
         |    serves the public website — which must not be the day
         |    `/console/login` becomes indexable.
         |  * **everything else, while `site.noindex` is on**, which it is
         |    while this application does not serve the website.
         |
         | Never the API: a crawler has no business there either way, and the
         | header would only raise the question of whether /api/v2 is a place
         | to look.
         */
        if ($console || (config('site.noindex') && ! $request->is('api/*'))) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($request->is('api/*')) {
            // API responses contain personal data and must never be cached by intermediaries.
            if (! $response->headers->has('Cache-Control') || $request->user()) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        }

        if (app()->isProduction()) {
            // Not includeSubDomains: other *.12steptoolkit.com hosts are not ours to force onto HTTPS.
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
