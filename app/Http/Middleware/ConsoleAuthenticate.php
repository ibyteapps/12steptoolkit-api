<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Only a signed-in, still-active console account gets past this. */
class ConsoleAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('console')->user();
        if ($user === null) {
            $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : route('console.home'));

            return redirect()->route('console.login');
        }
        if (! $user->active) {
            Auth::guard('console')->logout();
            $request->session()->invalidate();

            return redirect()->route('console.login')->withErrors(['email' => 'This console account is no longer active.']);
        }

        return $next($request);
    }
}
