<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2 clients always get JSON (including 401/422), even if they forget the Accept header.
 * Legacy v1 routes are excluded: they must answer in the historic text/JSON format.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/legacy/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
