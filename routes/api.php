<?php

use App\Http\Controllers\Api\V1\Android\AccountController;
use App\Http\Controllers\Api\V1\Android\AppSettingsController;
use App\Http\Controllers\Api\V1\Android\AuthController;
use App\Http\Controllers\Api\V1\Android\CommentController;
use App\Http\Controllers\Api\V1\Android\CountsController;
use App\Http\Controllers\Api\V1\Android\DeleteAccountController;
use App\Http\Controllers\Api\V1\Android\IconController;
use App\Http\Controllers\Api\V1\Android\InstallSecretController;
use App\Http\Controllers\Api\V1\Android\RecordController;
use App\Http\Controllers\Api\V1\Android\ReminderController;
use App\Http\Controllers\Api\V1\Android\ReviewController;
use App\Http\Controllers\Api\V1\Android\SponsorController;
use App\Http\Controllers\Api\V1\Android\ThreadBackfillController;
use App\Http\Controllers\Api\V1\Apple\AppleScriptController;
use App\Http\Controllers\Api\V2\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The API
|--------------------------------------------------------------------------
|
| ## Why the old contract is the main one
|
| The obvious plan for a rewrite is a clean v2 API and a thin shim for the old
| apps. That is the wrong way round here, and the reason is in the Flutter
| client: it already speaks the v19 contract. `lib/core/network/endpoints.dart`
| lists `login_new_account.php`, `inventory_add_update.php`, `get_counts.php`
| and the rest; `lib/core/network/signing.dart` builds the v19 HMAC byte for
| byte; the whole sync engine is written and tested against those shapes.
|
| So the v19 contract is not a legacy burden to be tolerated — it is the
| contract **two of the three clients speak**, and the third (iOS 1.6.6) speaks
| v8. Reimplementing it faithfully, over the same database, with the security
| holes closed, is the smallest cutover available and the one where the fewest
| people's step work can go missing.
|
| `/api/v2` is therefore additive: it holds only what the old contract cannot
| express — a subscription this server verified, an account deletion, a support
| thread, an install's push token.
|
| ## Paths
|
| The old apps have their base URL compiled in, so this application has to
| answer on the paths they already use:
|
|   * Android: `https://scripts.12steptoolkit.com/12steptoolkit.com/aa/android/19/<script>.php`
|   * Apple:   `https://apple.12stepapp.com/8/<script>.php`
|
| Both are matched by a prefix wildcard below, so the same application serves
| either hostname whatever the document root turns out to be. The canonical
| paths (`/api/v1/android/...`) exist as well, for anything new.
*/

Route::get('api/v2/health', HealthController::class)->withoutMiddleware('throttle:api');

/*
|--------------------------------------------------------------------------
| v19 — the Android API, and the API the new app speaks
|--------------------------------------------------------------------------
|
| Auth is per-endpoint, exactly as `19/db.php:5-6` makes it per-file: a bearer
| JWT, plus an HMAC-SHA256 signature over the canonical request keyed with the
| per-install secret. The difference from the live scripts is that the nonce is
| recorded, so a captured request is no longer replayable for five minutes.
*/
$android = function (): void {
    // No token yet: these are the ways to get one.
    Route::post('login_new_account.php', [AuthController::class, 'newAccount'])->middleware('throttle:legacy-login');
    Route::post('login_email.php', [AuthController::class, 'email'])->middleware('throttle:legacy-login');
    Route::post('login_google.php', [AuthController::class, 'google'])->middleware('throttle:legacy-login');
    Route::post('login_2.php', [AuthController::class, 'googleOrCreate'])->middleware('throttle:legacy-login');
    Route::post('login_upgrade.php', [AuthController::class, 'upgrade'])->middleware('throttle:legacy-login');

    // Public by design: one global row of limits and ad timings.
    Route::match(['get', 'post'], 'get_app_settings.php', AppSettingsController::class);

    // A token, but no signature yet — you cannot sign before you hold the key.
    Route::post('bootstrap_secret.php', InstallSecretController::class)
        ->middleware(['legacy.jwt', 'throttle:legacy-bootstrap']);

    /*
    | A token, and no signature — because a signature is impossible here.
    |
    | The client's `HmacInterceptor` hashes the request body, multipart
    | included. PHP, on the other side, cannot read the raw body of a
    | `multipart/form-data` request at all: it consumes it into `$_POST` and
    | `$_FILES` and leaves `php://input` empty. So the hash the client computes
    | and the hash the server can compute are never the same thing, and the
    | signature can never verify.
    |
    | That is why `upload_icon.php` and `upload_icon_group.php` are the only two
    | scripts in the whole v19 tree with `REQUIRE_HMAC false` — it was forced,
    | not chosen. The old scripts also set `REQUIRE_AUTH false`, which was not
    | forced: it made them an unauthenticated file write for any account id.
    | A token is required here.
    */
    Route::post('upload_icon.php', [IconController::class, 'upload'])
        ->middleware(['legacy.jwt', 'throttle:legacy-upload']);

    // Everything below is signed.
    Route::middleware(['legacy.signed'])->group(function (): void {
        Route::post('get_user_account.php', [AccountController::class, 'show']);
        Route::post('update_account.php', [AccountController::class, 'updateAccount']);
        Route::post('update_account_details.php', [AccountController::class, 'updateDetails']);
        Route::post('get_counts.php', CountsController::class);

        /*
         | A sponsor marking a sponsee's Step work reviewed. `18/reviewed.php`
         | updates by row id with no account and no sponsorship check, and
         | interpolates the new value into the UPDATE; this one requires an
         | accepted sponsorship in the right direction. See ReviewController.
         */
        Route::post('mark_reviewed.php', ReviewController::class);

        /*
         | Erasing one's own account. `18/deleteaccount.php` reads the id to
         | erase out of the request body and is unauthenticated, so it erases
         | whoever is named. This one has no such parameter.
         */
        Route::post('delete_account.php', DeleteAccountController::class)
            ->middleware('throttle:legacy-login');

        /*
         | The once-per-account iOS back-fill, called by s011. The live script
         | is REQUIRE_AUTH false and takes the account from the query string,
         | so anybody could run one against anybody.
         */
        Route::post('check_threads_connections_with_another_account.php', ThreadBackfillController::class);

        foreach (RecordController::ENDPOINTS as $script => $call) {
            Route::post($script, [RecordController::class, $call]);
        }

        // Comments, chat threads, receipts and blocking. These are the ones
        // `retrofit/ApiService.kt` actually calls; the live directory holds
        // twenty-odd more, several of them duplicates of each other.
        foreach (CommentController::ENDPOINTS as $script => $call) {
            Route::post($script, [CommentController::class, $call]);
        }

        // Sponsorship and chat relationships. One table, two request flows.
        foreach (SponsorController::ENDPOINTS as $script => $call) {
            Route::post($script, [SponsorController::class, $call]);
        }

        // Deleting and fetching are form-encoded, so they sign normally.
        // Uploading cannot — see the note above `upload_icon.php`.
        foreach (IconController::ENDPOINTS as $script => $call) {
            Route::post($script, [IconController::class, $call]);
        }

        foreach (ReminderController::ENDPOINTS as $script => $call) {
            Route::post($script, [ReminderController::class, $call]);
        }

        /*
         | Not a v19 script: the old apps build a URL to a static path under the
         | icons directory. This exists because the file lives outside the web
         | root — which is what makes "only people who may see this picture can
         | fetch it" enforceable at all, and the old arrangement could not.
         */
        Route::get('icon/{account}', [IconController::class, 'show'])->whereNumber('account');
    });
};

Route::prefix('api/v1/android/19')->name('v19.')->group($android);
// The live Android path, whatever sits in front of `/19/`.
Route::prefix('{legacyPrefix}/19')->where(['legacyPrefix' => '.*'])->group($android);

/*
|--------------------------------------------------------------------------
| v8 — the Apple API
|--------------------------------------------------------------------------
|
| One shared secret for the whole fleet, published in every App Store binary
| and in a `test.html` served over the web. It is accepted so that iOS 1.6.6
| keeps working through the cutover, and it is contained — see the long note in
| `config/legacy.php`. Every one of these endpoints is answered by one
| controller because the old API is four multiplexed scripts and a handful of
| others; splitting it further would invent a structure it never had.
*/
$apple = function (): void {
    Route::match(['get', 'post'], '{script}', AppleScriptController::class)
        ->where('script', '[a-z0-9_]+\.php');
};

// `legacy.apple` BEFORE the throttle, and it was missing entirely until
// 2026-10-07: these two groups carried `throttle:legacy-apple` and nothing
// else, so the shared-secret check never ran. It did not matter while the
// controller was a 503 — and it would have mattered enormously the moment
// somebody implemented it, because the gate was in the middleware list of
// `bootstrap/app.php` under an alias no route used. A 503 is a fine way to
// hide a missing authentication layer from every test you can think to write.
$appleMiddleware = ['legacy.apple', 'throttle:legacy-apple'];

Route::prefix('api/v1/apple/8')->name('v8.')->middleware($appleMiddleware)->group($apple);
Route::prefix('{legacyPrefix}/8')->where(['legacyPrefix' => '.*'])->middleware($appleMiddleware)->group($apple);
