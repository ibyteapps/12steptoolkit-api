<?php

use App\Http\Controllers\Console\HomeController;
use App\Http\Controllers\Console\LoginController;
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
});

Route::middleware('console.auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', HomeController::class)->name('home');
});
