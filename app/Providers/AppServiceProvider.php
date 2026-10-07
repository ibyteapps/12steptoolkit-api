<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\PersonalAccessToken;
use App\Services\Auth\JwksIdentityTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('identity.verifier.google', fn () => new JwksIdentityTokenVerifier(
            jwksUrl: (string) config('services.google.jwks_url'),
            issuers: (array) config('services.google.issuers'),
            audiences: (array) config('services.google.client_ids'),
        ));

        $this->app->bind('identity.verifier.apple', fn () => new JwksIdentityTokenVerifier(
            jwksUrl: (string) config('services.apple.jwks_url'),
            issuers: [(string) config('services.apple.issuer')],
            audiences: (array) config('services.apple.client_ids'),
        ));
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
         | Console passwords: long rather than complicated, because length is
         | what actually helps and a rule nobody can satisfy gets written on a
         | sticky note. `uncompromised()` checks the password against the
         | breach corpus, which is a live HTTP call — so it is on in production
         | and off everywhere else, where it would make the test suite depend on
         | the network.
         */
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));

        $this->configureRateLimiting();
    }

    /**
     * The old API had no limits anywhere.
     *
     * A verification code could be requested in a loop, `login_email.php` could
     * be tried for ever, and `ai_chat.php` was an unauthenticated OpenAI proxy
     * that billed the owner's key for whoever found the URL.
     *
     * The identity a limit is keyed on, in order: the signed-in account, then
     * the install's device id, then the address. Keying on the address alone
     * punishes everybody behind one mobile carrier NAT.
     */
    private function configureRateLimiting(): void
    {
        $identity = static function (Request $request): string {
            $account = $request->attributes->get('legacy_account') ?? $request->user();
            if ($account instanceof Account) {
                return 'a:'.$account->id;
            }
            $device = (string) $request->header('X-Device-Id', '');
            if ($device !== '') {
                return 'd:'.sha1($device);
            }

            return 'i:'.$request->ip();
        };

        RateLimiter::for('api', fn (Request $r) => [
            Limit::perMinute(120)->by($identity($r)),
            Limit::perMinute(1200)->by('ip:'.$r->ip()),
        ]);

        // Signing in is the expensive one to get wrong: `login_upgrade.php`
        // hands a token to anybody who guesses an account id, which is why the
        // security patch set added a ledger for it.
        RateLimiter::for('legacy-login', fn (Request $r) => [
            Limit::perMinute(10)->by('ip:'.$r->ip()),
            Limit::perHour(60)->by('ip:'.$r->ip()),
        ]);

        // A bearer token can ask for the signing key that goes with it, so this
        // is the one place a stolen token becomes a signed client.
        RateLimiter::for('legacy-bootstrap', fn (Request $r) => [
            Limit::perMinute(10)->by($identity($r)),
            Limit::perDay(50)->by($identity($r)),
        ]);

        // The Apple path authenticates with a secret that is printed in a web
        // page, so it gets the tightest address limit in the application.
        RateLimiter::for('legacy-apple', fn (Request $r) => [
            Limit::perMinute(60)->by('ip:'.$r->ip()),
            Limit::perHour(600)->by('ip:'.$r->ip()),
        ]);

        // Uploads authenticate by token alone, because a multipart body cannot
        // be signed, so they get the limit that a token-only endpoint needs.
        RateLimiter::for('legacy-upload', fn (Request $r) => [
            Limit::perMinute(6)->by($identity($r)),
            Limit::perDay(60)->by($identity($r)),
        ]);

        RateLimiter::for('console-login', fn (Request $r) => [
            Limit::perMinutes(10, 8)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinutes(10, 30)->by('ip:'.$r->ip()),
        ]);
    }
}
