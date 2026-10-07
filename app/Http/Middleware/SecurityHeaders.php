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

        // Staging copy of the website: keep it out of search results without changing the pages.
        if (config('site.noindex') && ! $request->is('api/*')) {
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
