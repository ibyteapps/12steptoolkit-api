<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The handful of pages a static site cannot serve
|--------------------------------------------------------------------------
|
| 12steptoolkit.com is a Next.js static export and stays that way. This file
| holds only the pages that need a server: the ones the stores require to be
| reachable without the app, and anything that has to look something up.
*/

Route::view('/', 'site.placeholder')->name('site.home');
