<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Console sign-in.
 *
 * There is no registration page. Accounts are made with
 * `php artisan console:user you@example.com`, which emails a set-password link,
 * and a new account has no usable password until that link is used. Nobody
 * becomes staff by signing in to the app.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('console.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('console')->attempt($credentials, remember: false)) {
            // One message for a wrong address and a wrong password alike.
            throw ValidationException::withMessages(['email' => 'Those details were not recognised.']);
        }

        $request->session()->regenerate();
        Auth::guard('console')->user()?->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('console.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('console')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('console.login');
    }
}
