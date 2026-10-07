<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ConsoleUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The other half of `php artisan console:user`: the link it prints.
 *
 * A staff account is created with a null password and cannot sign in until
 * somebody follows this link, which is good for `console.password_link_minutes`
 * and works once. There is no "forgot password" form, on purpose — a console
 * password is reset by someone with shell access running `console:user --reset`,
 * which means the reset path cannot be walked by anyone who merely knows a
 * staff member's email address.
 *
 * The token is stored hashed, so the `console_password_reset_tokens` table is
 * not itself a way in.
 */
class SetPasswordController extends Controller
{
    public function show(Request $request, string $token): View
    {
        // The form is shown without validating the token, and the token is
        // validated on submit. Telling a stranger at this point whether a token
        // is good is free information about who has a pending invitation.
        return view('console.set-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            // `Password::defaults()` is set in AppServiceProvider: twelve
            // characters, and checked against the breach corpus in production.
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $email = strtolower(trim($data['email']));
        $record = DB::table('console_password_reset_tokens')->where('email', $email)->first();
        $user = ConsoleUser::query()->where('email', $email)->first();

        $expired = $record !== null
            && now()->diffInMinutes($record->created_at, absolute: true) > (int) config('console.password_link_minutes');

        if ($record === null || $user === null || ! $user->active || $expired || ! Hash::check($token, $record->token)) {
            // One message for a wrong token, an expired one, a switched-off
            // account and an address with no invitation.
            throw ValidationException::withMessages([
                'password' => 'That link is no longer valid. Ask for a new one.',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Single use, and every other pending link for this address goes too.
        DB::table('console_password_reset_tokens')->where('email', $email)->delete();

        // Signed in straight away: they have just proved they hold the link and
        // chosen a password, and sending them to a login form to type it again
        // is the kind of thing that gets a password written down.
        Auth::guard('console')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('console.home');
    }
}
