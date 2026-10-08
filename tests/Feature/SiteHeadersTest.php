<?php

use Illuminate\Support\Facades\File;

/**
 * The `X-Robots-Tag` switch, and the one thing about it that is easy to get
 * wrong in both directions.
 *
 * `SecurityHeaders` has read `config('site.noindex')` since it was written, but
 * `config/site.php` did not exist until 2026-10-08 — so the header was never
 * sent and the switch was dead code that looked alive. These tests are what
 * stops that happening again: they assert the behaviour through a real request,
 * not the presence of a config key.
 */
it('keeps this host out of search results by default', function () {
    // The default, deliberately: today nginx routes only /console, /api/v2
    // and /up here, and the static export still serves the website.
    expect(config('site.noindex'))->toBeTrue();

    $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('stops sending the header once this host becomes the website', function () {
    config(['site.noindex' => false]);

    $response = $this->get('/')->assertOk();

    expect($response->headers->has('X-Robots-Tag'))->toBeFalse();
});

/*
 | The API is not a page, so it never carries the tag either way — a crawler
 | has no business there and the header would only invite the question of
 | whether /api/v2 is a place to look.
 */
it('never tags the API, whichever way the switch is set', function () {
    foreach ([true, false] as $noindex) {
        config(['site.noindex' => $noindex]);
        $response = $this->getJson('/api/v2/health')->assertOk();
        expect($response->headers->has('X-Robots-Tag'))->toBeFalse();
    }
});

/*
 | The console's header must not depend on `site.noindex`, because that flag
 | goes false the day this application serves the public website — and that
 | must not be the day the staff login becomes indexable.
 */
it('keeps the console out of search results even once the website is indexable', function () {
    config(['site.noindex' => false]);

    $this->get('/console/login')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('does not claim to serve the website by default', function () {
    // The arrangement: 12steptoolkit.com's document root stays on the static
    // export and nginx routes three prefixes here. `deploy.sh` reads this to
    // decide whether `/` is its business to judge.
    expect(config('site.serves_website'))->toBeFalse();
});

it('names the one host where being indexable is correct', function () {
    // deploy.sh reads this out of .env to decide whether `noindex` on this
    // deploy is correct or a mistake about to cost the site its rankings.
    expect(config('site.website_host'))->toBe('12steptoolkit.com');
});

/*
 | `artisan down --render` renders this view before the code changes, so it has
 | to stand up with no layout, no route, no asset and no database. A view that
 | extends a layout works in a test and fails during the one minute it exists
 | for.
 */
it('has a maintenance page that depends on nothing', function () {
    $path = resource_path('views/errors/503.blade.php');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);

    expect($source)
        ->toContain('<!DOCTYPE html>')
        ->not->toContain('@extends')
        ->not->toContain('@include')
        ->not->toContain('asset(')
        ->not->toContain('route(');

    // It renders, and it says 503 rather than pretending to be a page.
    expect(view('errors.503')->render())->toContain('Back in a minute');
});
