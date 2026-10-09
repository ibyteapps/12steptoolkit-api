<?php

use App\Http\Controllers\My\HomeController;
use App\Http\Controllers\My\RecordController;
use App\Http\Controllers\My\SignInController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The member area — /my
|------------------------------------------------------------------------------
|
| The web version of the app, replacing web.12steptoolkit.com. Registered in
| bootstrap/app.php behind `member.session` and `web`, so every route here has
| a session, a CSRF token and the `member` guard as its default.
|
| Nothing in this file takes an account id from the request. There is no route
| that could.
|
*/

Route::middleware('guest:member')->group(function (): void {
    Route::get('sign-in', [SignInController::class, 'show'])->name('sign-in');
    Route::post('sign-in', [SignInController::class, 'request'])
        ->middleware('throttle:6,1')->name('sign-in.request');
    Route::post('verify', [SignInController::class, 'verify'])
        ->middleware('throttle:10,1')->name('sign-in.verify');
});

Route::middleware('auth:member')->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::post('sign-out', [SignInController::class, 'destroy'])->name('sign-out');

    Route::get('{slug}', [RecordController::class, 'index'])->name('records.index');
    Route::get('{slug}/{id}', [RecordController::class, 'show'])
        ->whereNumber('id')->name('records.show');
    Route::delete('{slug}/{id}', [RecordController::class, 'destroy'])
        ->whereNumber('id')->name('records.destroy');
});
