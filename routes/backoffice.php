<?php

use App\Http\Controllers\Console\AccountController;
use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\CutoverController;
use App\Http\Controllers\Console\GrantController;
use App\Http\Controllers\Console\HomeController;
use App\Http\Controllers\Console\LoginController;
use App\Http\Controllers\Console\SetPasswordController;
use App\Http\Controllers\Console\SubscriptionController;
use App\Http\Controllers\Console\SupportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The console
|--------------------------------------------------------------------------
|
| Registered under /console with a session and a cookie; the API and the site
| have neither.
|
| The surface is the AA Big Book back office plus what this product needs that
| that one does not have: sponsorship, chat moderation, reported users, gifted
| subscriptions and the legacy cutover's own numbers.
|
| The data boundary is the owner's decision of 2026-10-07: **counts, dates and
| sync state, never content.** No inventory, no amend, no journal line, no chat
| message and no note is readable from here, by anybody, at any permission
| level. A support person can see that somebody has 42 inventories and when the
| last one was written, and that is all they need to answer "is my backup
| working".
*/

Route::middleware('guest:console')->group(function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:console-login');

    // The link `php artisan console:user` prints. There is no "forgot password"
    // form: a reset needs shell access, which is what stops the reset path
    // being walkable by anyone who knows a staff address.
    Route::get('set-password/{token}', [SetPasswordController::class, 'show'])->name('set-password');
    Route::post('set-password/{token}', [SetPasswordController::class, 'store'])
        ->middleware('throttle:console-login')->name('set-password.store');
});

Route::middleware('console.auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', HomeController::class)->name('home');

    // Accounts. `{account}` is the legacy `accounts.id` because that is what a
    // support email says ("my id is 41882") and what the old scripts logged.
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show')->whereNumber('account');
    Route::post('accounts/{account}/grants', [GrantController::class, 'store'])->name('grants.store')->whereNumber('account');
    Route::delete('accounts/{account}/grants/{grant}', [GrantController::class, 'destroy'])->name('grants.destroy')->whereNumber('account');

    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions');
    Route::post('subscriptions/{subscription}/refresh', [SubscriptionController::class, 'refresh'])->name('subscriptions.refresh');

    // Support. Threads are addressed by uuid, not by id: a ticket link gets
    // pasted into emails and a sequential id in one tells you how many there
    // are.
    Route::get('support', [SupportController::class, 'index'])->name('support');
    Route::get('support/{ticket}', [SupportController::class, 'show'])->name('support.show');
    Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.reply');
    Route::post('support/{ticket}/state', [SupportController::class, 'setState'])->name('support.state');

    // The page `docs/CUTOVER.md` step 4 is watched on.
    Route::get('cutover', CutoverController::class)->name('cutover');

    Route::get('audit', AuditController::class)->name('audit');
});
