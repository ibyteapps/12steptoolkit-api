<?php

use App\Services\Site\BlogLibrary;
use App\Services\Site\LiteratureLibrary;
use App\Services\Site\Schema;

/**
 * The JSON-LD, which is the half of a page's SEO that nothing visible tells
 * you about.
 *
 * These exist because of a mistake made in this port: the literature and blog
 * pages were shipped answering 200 with the right titles and **no structured
 * data at all**, while the pages they replaced carried Organization, WebSite,
 * BreadcrumbList and an Article node each. Nothing would have failed. The rich
 * results would simply have drained away over the following weeks, and the
 * cause would have been three commits back by the time anybody noticed.
 */
function graphOf(string $html): array
{
    expect($html)->toContain('application/ld+json');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    $decoded = json_decode($m[1] ?? '', true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toHaveKey('@context')
        ->and($decoded)->toHaveKey('@graph');

    return $decoded['@graph'];
}

/** @return array<int, string> */
function typesIn(array $graph): array
{
    return array_column($graph, '@type');
}

it('puts the publisher and the site on every page', function () {
    foreach (['/aa-literature', '/aa-literature/big-book/preface', '/blog', '/privacy'] as $path) {
        $types = typesIn(graphOf($this->get($path)->assertOk()->getContent()));

        expect($types)->toContain('Organization')
            ->and($types)->toContain('WebSite');
    }
});

it('describes the breadcrumb trail that is actually rendered', function () {
    $graph = graphOf($this->get('/aa-literature/prayers/serenity-prayer')->assertOk()->getContent());
    $crumbs = collect($graph)->firstWhere('@type', 'BreadcrumbList');

    expect($crumbs)->not->toBeNull();

    $names = array_column($crumbs['itemListElement'], 'name');
    $positions = array_column($crumbs['itemListElement'], 'position');

    // Home is prepended because the rendered trail starts there.
    expect($names[0])->toBe('Home')
        ->and($names)->toContain('A.A. Literature')
        ->and($names)->toContain('Prayers')
        ->and($positions)->toBe([1, 2, 3, 4]);
});

it('marks a literature page as an article based on the book it comes from', function () {
    $graph = graphOf($this->get('/aa-literature/big-book/bills-story')->assertOk()->getContent());
    $article = collect($graph)->firstWhere('@type', 'Article');

    expect($article)->not->toBeNull()
        ->and($article['headline'])->toBe(app(LiteratureLibrary::class)->find('bills-story')['title'])
        ->and($article['isBasedOn']['name'])->toBe('Alcoholics Anonymous (The Big Book)')
        ->and($article['isBasedOn']['author']['name'])->toBe('Alcoholics Anonymous');
});

/*
 | How It Works, the Promises, the Twelve Traditions and Just For Today are
 | Big Book text, and all eight live reading pages carried the attribution.
 | The port left it off, which dropped the `Book` node from eight indexed
 | pages.
 */
it('attributes the readings to the Big Book, as the live pages do', function () {
    $graph = graphOf($this->get('/aa-literature/readings/how-it-works')->assertOk()->getContent());
    $article = collect($graph)->firstWhere('@type', 'Article');

    expect($article['isBasedOn']['@type'])->toBe('Book')
        ->and($article['isBasedOn']['name'])->toBe('Alcoholics Anonymous (The Big Book)');
});

it('does not claim a prayer is a chapter of a book', function () {
    $graph = graphOf($this->get('/aa-literature/prayers/lords-prayer')->assertOk()->getContent());
    $article = collect($graph)->firstWhere('@type', 'Article');

    expect($article)->not->toBeNull()
        ->and($article)->not->toHaveKey('isBasedOn');
});

it('marks the index pages as collections that list their contents', function () {
    $graph = graphOf($this->get('/aa-literature/stories-edition-2')->assertOk()->getContent());
    $collection = collect($graph)->firstWhere('@type', 'CollectionPage');

    expect($collection)->not->toBeNull()
        ->and($collection['mainEntity']['numberOfItems'])->toBe(40)
        ->and($collection['mainEntity']['itemListElement'][0]['position'])->toBe(1);
});

it('marks a blog post as a BlogPosting with its dates', function () {
    $post = app(BlogLibrary::class)->posts()->first();
    $graph = graphOf($this->get($post['path'])->assertOk()->getContent());
    $article = collect($graph)->firstWhere('@type', 'BlogPosting');

    expect($article)->not->toBeNull()
        ->and($article['headline'])->toBe($post['title'])
        ->and($article['datePublished'])->toBe($post['date']->toDateString())
        ->and($article['articleSection'])->toBe($post['category']);
});

it('marks the blog hub as a Blog listing its posts', function () {
    $graph = graphOf($this->get('/blog')->assertOk()->getContent());
    $blog = collect($graph)->firstWhere('@type', 'Blog');

    expect($blog)->not->toBeNull()
        // Twenty at most, as the static site did — a hub node is a summary.
        ->and(count($blog['blogPost']))->toBeLessThanOrEqual(20)
        ->and(count($blog['blogPost']))->toBeGreaterThan(0);
});

/*
 | The rating is derived from the two stores by weight rather than stored, so
 | the number in the markup cannot drift from the parts shown beside it.
 | Google issues a structured-data manual action for an aggregateRating a
 | visitor cannot see, which makes this arithmetic a compliance matter rather
 | than a detail.
 */
it('derives the combined rating from the store figures', function () {
    $rating = Schema::combinedRating();

    $stores = config('site.identity.ratings.stores');
    $count = array_sum(array_column($stores, 'count'));
    $weighted = 0.0;
    foreach ($stores as $store) {
        $weighted += $store['value'] * $store['count'];
    }

    expect($rating['count'])->toBe((int) $count)
        ->and($rating['value'])->toBe(round($weighted / $count, 1));
});

it('states the app rating in the markup as the stores report it', function () {
    $schema = app(Schema::class);
    $app = $schema->app();
    $rating = Schema::combinedRating();

    expect($app['aggregateRating']['ratingValue'])->toBe((string) $rating['value'])
        ->and($app['aggregateRating']['ratingCount'])->toBe((string) $rating['count'])
        ->and($app['installUrl'])->toHaveCount(2);
});

it('turns the five home-page questions into FAQPage markup', function () {
    $faqs = require resource_path('site/faqs.php');
    $node = app(Schema::class)->faqs($faqs);

    expect($node['@type'])->toBe('FAQPage')
        ->and($node['mainEntity'])->toHaveCount(count($faqs));

    // The answer is the page's paragraphs joined — the same words a visitor
    // reads, which is what Google requires of a marked-up answer.
    expect($node['mainEntity'][0]['acceptedAnswer']['text'])
        ->toBe(implode(' ', $faqs[0]['answer']));
});

it('says nothing rather than emitting an empty graph', function () {
    expect(app(Schema::class)->graph([]))->toBeNull()
        ->and(app(Schema::class)->graph([null, null]))->toBeNull();
});
