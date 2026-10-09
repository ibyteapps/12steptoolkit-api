<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CanonicalUrl;
use App\Http\Middleware\ConsoleAuthenticate;
use App\Http\Middleware\ConsoleSession;
use App\Http\Middleware\EnsureSyncAllowed;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\MemberSession;
use App\Http\Middleware\ResolveInstall;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyAppleSecret;
use App\Http\Middleware\VerifyLegacyJwt;
use App\Http\Middleware\VerifyLegacySignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/**
 * Three applications in one checkout, and they share nothing but the database:
 *
 *  * `/api/v2/*`  — the Flutter app's API. Token auth, JSON envelope, no cookies.
 *  * `/api/v1/*`  — the two legacy PHP APIs, answered in their own words so the
 *                   apps in the field keep working through the cutover.
 *  * `/console/*` — the back office. The only part with a session and a cookie.
 *
 * The public website is the Next.js static export and stays where it is; this
 * application serves only the handful of pages a static site cannot
 * (`/sign-in/{token}`, account deletion), which is why `routes/site.php` is
 * short.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        // No automatic `api/` prefix: the legacy paths are fixed by what is
        // compiled into the apps in the field (`/12steptoolkit.com/aa/android/19/…`
        // and `/8/…`), so every prefix in routes/api.php is written out.
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Registered before the site routes so /console never falls through
            // to a page.
            Route::middleware(['console.session', 'web'])->prefix('console')->name('console.')
                ->group(__DIR__.'/../routes/backoffice.php');
            // The member area, before the site routes for the same reason as
            // the console: /my must never fall through to a page.
            Route::middleware(['member.session', 'web'])->prefix('my')->name('my.')
                ->group(__DIR__.'/../routes/my.php');
            Route::middleware(SubstituteBindings::class)->group(__DIR__.'/../routes/site.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API is a token API with no login page; only the console has one.
        $middleware->redirectGuestsTo(fn (Request $request) => match (true) {
            $request->is('my', 'my/*') => route('my.sign-in'),
            $request->is('console', 'console/*') => route('console.login'),
            default => null,
        });
        $middleware->redirectUsersTo(fn (Request $request) => route('console.home'));

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // One address per page, for the website's pages. Prepended so a
        // redirect costs nothing else: no session, no route resolution.
        $middleware->prepend(CanonicalUrl::class);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // Signed in first, then route-model binding: a stranger asking for
        // /console/accounts/7 is sent to the login page, not told whether
        // account 7 exists.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ConsoleAuthenticate::class);

        $middleware->alias([
            'install' => ResolveInstall::class,
            'legacy.jwt' => VerifyLegacyJwt::class,
            'legacy.signed' => VerifyLegacySignature::class,
            'legacy.apple' => VerifyAppleSecret::class,
            'console.session' => ConsoleSession::class,
            'member.session' => MemberSession::class,
            'console.auth' => ConsoleAuthenticate::class,
            'sync.allowed' => EnsureSyncAllowed::class,
        ]);

        // Trust the Plesk/NGINX reverse proxy so request()->ip() is the client's
        // address — rate limiting depends on it. Narrow with TRUSTED_PROXIES.
        //
        // `env()` is correct *here* and nowhere else in the application: this
        // file runs before the config is loaded, so there is no config to read.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash a credential or a user's own words into a log line.
        $exceptions->dontFlash([
            'code', 'token', 'access_token', 'id_token', 'identity_token', 'password',
            'serversecret', 'secret', 'comment', 'description', 'invdescription',
            'myfault', 'apologynotes', 'amendsnotes', 'q6_notes',
        ]);

        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiExceptionRenderer::render($e, $request);
        });
    })->create();
