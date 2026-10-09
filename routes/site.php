<?php

use App\Http\Controllers\Site\BlogController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LiteratureController;
use App\Http\Controllers\Site\SitemapController;
use App\Services\Site\LiteratureLibrary;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public website
|--------------------------------------------------------------------------
|
| Every address in this file is one a search engine already holds, so the
| routes are written to fit the addresses and not the other way round.
| `docs/WEBSITE_TAKEOVER.md` has the full inventory; `sitemap.xml` is the
| contract.
|
| ## The ordering matters
|
| `/aa-literature/big-book/{slug}` and `/aa-literature/{section}/{slug}` are
| the same shape, and `/aa-literature/{section}` is a prefix of both. Laravel
| matches in definition order, so the more specific route is written first and
| `{section}` is constrained to the five real names — otherwise
| `/aa-literature/anything` would answer 200 with an empty page, which is the
| one failure mode a sitemap cannot survive.
*/

$sections = implode('|', array_map(
    fn (string $s): string => preg_quote($s, '/'),
    array_keys(LiteratureLibrary::SECTIONS),
));

$others = implode('|', array_map(
    fn (string $s): string => preg_quote($s, '/'),
    array_diff(array_keys(LiteratureLibrary::SECTIONS), ['big-book']),
));

Route::get('/', HomeController::class)->name('site.home');

/*
| The pages whose copy is the copy. Converted from the static site's JSX with
| the wrapper markup dropped and nothing rewritten — the legal ones were then
| diffed against the live pages block by block, which is the only way to be
| sure a privacy policy still says what it said.
*/
Route::view('privacy', 'site.pages.privacy')->name('site.privacy');
Route::view('terms', 'site.pages.terms')->name('site.terms');
Route::view('get-app', 'site.pages.get-app')->name('site.get-app');
Route::view('contacts', 'site.pages.contacts', ['email' => config('toolkit.support.email', 'support@12steptoolkit.com')])
    ->name('site.contacts');

Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('site.sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('site.robots');

Route::prefix('blog')->name('site.blog.')->group(function (): void {
    Route::get('/', [BlogController::class, 'index'])->name('index');
    Route::get('{slug}', [BlogController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('show');
});

/*
| The addresses this site used to have.
|
| `resources/site/redirects.php` was extracted from the static site's
| generated `.htaccess` rather than retyped — 27 moves and one `410` from the
| WordPress and OpenCart years. They are routes rather than middleware so that
| `php artisan route:list` shows them: a redirect nobody can find is a
| redirect somebody deletes.
*/
$legacy = require resource_path('site/redirects.php');

foreach ($legacy['moved'] as $from => $to) {
    Route::permanentRedirect($from, $to);
}

foreach ($legacy['elsewhere'] as $from => $to) {
    Route::redirect($from, $to, 302);
}

foreach ($legacy['gone'] as $from) {
    // 410, not 404: this one is deliberately gone and a crawler should stop
    // asking rather than keep checking back.
    Route::get($from, fn () => abort(410));
}

Route::prefix('aa-literature')->name('site.literature.')->group(function () use ($sections, $others): void {
    Route::get('/', [LiteratureController::class, 'hub'])->name('hub');

    /*
     | Before the document routes: four segments, and the only thing that
     | produces it is one of the 69 duplicate addresses the static export
     | wrote. 301, because that is what the live server already answers and
     | what Google has already consolidated.
     */
    Route::get('big-book/{section}/{slug}', [LiteratureController::class, 'storyUnderBigBook'])
        ->where(['section' => $others, 'slug' => '[A-Za-z0-9._-]+'])
        ->name('story-redirect');

    Route::get('big-book/{slug}', [LiteratureController::class, 'document'])
        ->where('slug', '[A-Za-z0-9._-]+')
        ->name('big-book');

    Route::get('{section}', [LiteratureController::class, 'section'])
        ->where('section', $sections)
        ->name('section');

    /*
     | The slug reaching the controller is `prayers/serenity-prayer`, not
     | `serenity-prayer`, because that is the registry key — the section and
     | the slug are rejoined here rather than the registry being reshaped.
     */
    Route::get('{section}/{slug}', fn (string $section, string $slug) => app(LiteratureController::class)
        ->document($section.'/'.$slug))
        ->where(['section' => $others, 'slug' => '[A-Za-z0-9._-]+'])
        ->name('document');
});
