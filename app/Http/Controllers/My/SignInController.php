<?php

namespace App\Http\Controllers\My;

use App\Mail\SignInCode;
use App\Models\Account;
use App\Models\SignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Signing in to the member area: an email address, then a four-digit code.
 *
 * The same shape the old web app had, and none of its mechanics.
 *
 *  * **The code is stored hashed**, in the cache, under a key derived from the
 *    email — not written to `accounts.verificationcode`, which the apps use
 *    for their own sign-ins and which this must not disturb.
 *  * **The reply never says whether the address exists.** The old page told
 *    you, which turns a login form into a tool for confirming that somebody
 *    is in A.A.
 *  * **Attempts are counted twice**: per address, so a code cannot be ground
 *    down, and per IP, so a stranger cannot work through addresses.
 */
class SignInController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('my.home');
        }

        return view('my.sign-in', [
            'sent' => $request->session()->get('code_sent_to') !== null,
            'email' => $request->session()->get('code_sent_to'),
        ]);
    }

    /** Step one: take an address and send it a code. */
    public function request(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:190']]);

        $email = Account::normaliseEmail($request->string('email')->toString());

        if (RateLimiter::tooManyAttempts($perIp = 'my-code-ip:'.$request->ip(), 10)) {
            return back()->withErrors(['email' => 'Too many attempts. Try again in a few minutes.']);
        }
        RateLimiter::hit($perIp, 900);

        $account = Account::findByEmail($email);

        // A code is only ever generated for an address that has an account,
        // but the answer below is the same either way.
        if ($account !== null) {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            Cache::put($this->key($email), [
                'hash' => Hash::make($code),
                'account_id' => (int) $account->getKey(),
                'tries' => 0,
            ], now()->addMinutes((int) config('my.code_minutes')));

            Mail::to($email)->send(new SignInCode($code));
        }

        $request->session()->put('code_sent_to', $email);

        return redirect()->route('my.sign-in')
            ->with('status', 'If that address has an account, a code is on its way. It expires in '.config('my.code_minutes').' minutes.');
    }

    /** Step two: take the code and start a session. */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:4']]);

        $email = (string) $request->session()->get('code_sent_to', '');
        $entry = $email === '' ? null : Cache::get($this->key($email));

        if ($entry === null) {
            return redirect()->route('my.sign-in')
                ->withErrors(['code' => 'That code has expired. Ask for another.']);
        }

        if ($entry['tries'] >= (int) config('my.code_attempts')) {
            Cache::forget($this->key($email));

            return redirect()->route('my.sign-in')
                ->withErrors(['code' => 'Too many attempts. Ask for another code.']);
        }

        if (! Hash::check($request->string('code')->toString(), $entry['hash'])) {
            $entry['tries']++;
            Cache::put($this->key($email), $entry, now()->addMinutes((int) config('my.code_minutes')));

            return back()->withErrors(['code' => 'That code is not right.']);
        }

        $account = Account::find($entry['account_id']);

        if ($account === null) {
            Cache::forget($this->key($email));

            return redirect()->route('my.sign-in')->withErrors(['code' => 'That account is no longer available.']);
        }

        Cache::forget($this->key($email));
        $request->session()->forget('code_sent_to');

        // No "remember me": the legacy `accounts` table has no remember_token
        // column, and adding one to a live table shared with both apps is not
        // worth a convenience the 14-day session already provides.
        Auth::guard('member')->login($account);

        // A new session id, so a fixated one cannot survive the sign-in.
        $request->session()->regenerate();

        SignIn::create([
            'account_id' => (int) $account->getKey(),
            'method' => 'web-code',
            'platform' => 'web',
            'created_at' => now(),
        ]);

        return redirect()->intended(route('my.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('my.sign-in')->with('status', 'Signed out.');
    }

    private function key(string $email): string
    {
        return 'my:code:'.hash('sha256', $email);
    }
}
