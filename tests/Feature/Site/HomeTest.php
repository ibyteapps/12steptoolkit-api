<?php

use App\Services\Site\Schema;

/**
 * The home page — the one page in the port that was rebuilt rather than
 * converted, which is exactly why it needs tests the others do not.
 *
 * What may not change is the part search engines already have: the title and
 * description, the five FAQ answers, the store links and the ratings. What
 * did change is the layout, the tone, and one claim — see the controller.
 */
it('keeps the title and description the site is indexed under', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<title>12 Step Toolkit — Free A.A. Sobriety App for Android &amp; iOS</title>', false)
        ->assertSee('Free A.A. app with a sobriety calculator, the full Big Book, 12 Step worksheets', false);
});

/*
 | `{{ $title }}` escapes, so a title written with `&amp;` in the attribute
 | reached the page as `&amp;amp;` and showed up as "Android &amp; iOS" in the
 | browser tab and in a search result. Caught by looking at a rendered page.
 */
it('does not double-escape the ampersand in the title', function () {
    $html = $this->get('/')->getContent();

    expect($html)->not->toContain('&amp;amp;');
});

/*
 | Google issues a structured-data manual action for an `aggregateRating`
 | describing numbers a visitor cannot see. This asserts the two agree, which
 | is the only form of that rule a machine can check.
 */
it('shows the rating it marks up', function () {
    $rating = Schema::combinedRating();
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<strong>'.$rating['value'].'</strong>')
        ->toContain(number_format($rating['count']).' ratings');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $graph = json_decode($m[1], true)['@graph'];
    $app = collect($graph)->firstWhere('@type', 'MobileApplication');

    expect($app['aggregateRating']['ratingValue'])->toBe((string) $rating['value'])
        ->and($app['aggregateRating']['ratingCount'])->toBe((string) $rating['count']);
});

it('names each store with its own rating, to one decimal place', function () {
    $html = $this->get('/')->getContent();

    foreach (Schema::combinedRating()['stores'] as $store) {
        expect($html)->toContain(number_format($store['value'], 1).' '.$store['name']);
    }

    // 4.523 is a weighted average, not how a store rating is written.
    expect($html)->not->toContain('4.523');
});

it('renders every FAQ question and every paragraph of its answer', function () {
    $faqs = require resource_path('site/faqs.php');
    $response = $this->get('/')->assertOk();

    foreach ($faqs as $faq) {
        $response->assertSee($faq['question'], false);

        foreach ((array) $faq['answer'] as $paragraph) {
            $response->assertSee(e($paragraph), false);
        }
    }
});

it('carries the app and FAQ markup that earn the rich results', function () {
    $html = $this->get('/')->getContent();
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $types = array_column(json_decode($m[1], true)['@graph'], '@type');

    expect($types)->toContain('MobileApplication')
        ->and($types)->toContain('FAQPage')
        ->and($types)->toContain('Organization');
});

it('links to both stores and to the web app', function () {
    $identity = config('site.identity');

    $this->get('/')
        ->assertSee($identity['appStore'], false)
        ->assertSee($identity['playStore'], false)
        ->assertSee($identity['webApp'], false);
});

/*
 | The claim that changed, pinned so it cannot come back.
 |
 | The old page promised "Free For Life — unlimited access to all premium
 | features completely free for life" three sections above an FAQ answer
 | saying the app is ad-supported with restrictions and asking members to
 | subscribe. `config/billing.php` maps thirty paid products. A promise of
 | free premium above a paywall is how an app collects refunds and one-star
 | reviews.
 */
it('does not promise premium features free for life above a paywall', function () {
    $html = strtolower($this->get('/')->getContent());

    expect($html)->not->toContain('free for life')
        ->and($html)->not->toContain('all premium features');

    // It says what is true instead.
    expect($html)->toContain('ad-supported')
        ->and($html)->toContain('subscriptions');
});

it('sends people to the literature it is advertising', function () {
    $this->get('/')->assertSee('href="/aa-literature"', false);
});
