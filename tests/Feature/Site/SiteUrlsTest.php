<?php

use App\Http\Middleware\CanonicalUrl;
use App\Services\Site\BlogLibrary;
use App\Services\Site\LiteratureLibrary;
use Illuminate\Http\Request;

/**
 * The addresses, as opposed to the pages: the sitemap, `robots.txt`, the
 * canonical form of a URL, and the redirects carried over from the years
 * before the static site.
 *
 * Every one of these was working in production before this port started, so
 * each test here is a statement that it still is.
 */
it('generates a sitemap holding every page the application serves', function () {
    $xml = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    $literature = app(LiteratureLibrary::class);
    $blog = app(BlogLibrary::class);

    $missing = [];

    foreach (array_keys($literature->all()) as $slug) {
        $loc = url($literature->pathFor($slug));
        if (! str_contains($xml, "<loc>{$loc}</loc>")) {
            $missing[] = $loc;
        }
    }

    foreach ($blog->posts() as $post) {
        $loc = url($post['path']);
        if (! str_contains($xml, "<loc>{$loc}</loc>")) {
            $missing[] = $loc;
        }
    }

    expect($missing)->toBe([]);

    // Generated from the same source as the pages, so the count is the count.
    expect(substr_count($xml, '<loc>'))
        ->toBe(7 + count(LiteratureLibrary::SECTIONS) + count($literature->all()) + $blog->posts()->count());
});

it('is valid XML', function () {
    $xml = $this->get('/sitemap.xml')->getContent();

    expect(simplexml_load_string($xml))->not->toBeFalse();
});

/*
 | The sitemap is generated, which the static site's was not: `next-sitemap`
 | wrote it at build time, so an article the generator published did not
 | appear until somebody rebuilt. This is the test that it cannot drift.
 */
it('includes a post the moment its file exists, with no rebuild', function () {
    $path = resource_path('site/blog/zz-brand-new-for-the-test.mdx');
    file_put_contents($path, <<<'MDX'
        ---
        title: "Brand new"
        slug: "zz-brand-new-for-the-test"
        description: "Published a second ago."
        date: "2026-10-09"
        published: true
        ---

        ## Hello
        MDX);

    try {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/blog/zz-brand-new-for-the-test'), false);
    } finally {
        unlink($path);
    }
});

it('keeps this host out of robots.txt while it is not the website', function () {
    expect(config('site.noindex'))->toBeTrue();

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /', false)
        ->assertDontSee('Sitemap:', false);
});

it('points robots.txt at the sitemap once it is the website', function () {
    config(['site.noindex' => false]);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Allow: /', false)
        ->assertSee('Disallow: /app/', false)
        ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
});

// ───────────────────────────────────────────── the canonical form of a URL

it('strips .html, which the static export left reachable', function () {
    $this->get('/aa-literature/big-book/preface.html')
        ->assertStatus(301)
        ->assertRedirect('/aa-literature/big-book/preface');
});

/*
 | Not through `$this->get('/aa-literature/')`: Laravel's test client runs the
 | URL through `prepareUrlForRequest`, which rtrims the trailing slash before
 | the request object exists — so a feature test can never see the case this
 | middleware is for. The middleware is driven directly instead, with a request
 | built the way the web server builds one.
 */
it('strips a trailing slash', function () {
    $redirect = app(CanonicalUrl::class)->handle(
        Request::create('/aa-literature/', 'GET'),
        fn () => response('should not be reached'),
    );

    expect($redirect->getStatusCode())->toBe(301)
        ->and($redirect->headers->get('Location'))->toBe('/aa-literature');
});

it('does not redirect a path that is already canonical', function () {
    $response = app(CanonicalUrl::class)->handle(
        Request::create('/aa-literature', 'GET'),
        fn () => response('reached the route'),
    );

    expect($response->getContent())->toBe('reached the route');
});

it('leaves the root alone', function () {
    $this->get('/')->assertOk();
});

/*
 | The regression this test exists for: stripping `.html` globally sent
 | `/app/reset/reset_password.html` — an address in password-reset emails
 | already in people's inboxes — to a 404. The live site serves the `.html`
 | form as a 200 and 404s the extensionless one, which is the opposite of
 | every other page here, and its nginx config gives `/app/` its own location
 | blocks for exactly that reason.
 */
it('leaves the PHP app\'s own addresses alone', function () {
    foreach ([
        '/app/reset/reset_password.html',
        '/app/validate2/verified.html',
        '/app/reset/index.php',
    ] as $path) {
        $response = app(CanonicalUrl::class)->handle(
            Request::create($path, 'GET'),
            fn () => response('passed through'),
        );

        expect($response->getContent())->toBe('passed through');
    }
});

it('still canonicalises everything that is not the PHP app', function () {
    $redirect = app(CanonicalUrl::class)->handle(
        Request::create('/blog/something.html', 'GET'),
        fn () => response('should not be reached'),
    );

    expect($redirect->getStatusCode())->toBe(301);
});

it('points the daily reflection at aa.org, with a 302', function () {
    // A pointer at somebody else's page, not a page that moved: a 301 would
    // tell a crawler this address now belongs to aa.org.
    $this->get('/reflections.php')
        ->assertStatus(302)
        ->assertRedirect('https://www.aa.org/pages/en_US/daily-reflection');
});

it('keeps the query string when it redirects', function () {
    $this->get('/blog.html?utm_source=newsletter')
        ->assertStatus(301)
        ->assertRedirect('/blog?utm_source=newsletter');
});

// ───────────────────────────────────────────────── the old addresses

it('still honours the redirects carried over from WordPress', function () {
    $map = require resource_path('site/redirects.php');

    expect($map['moved'])->not->toBeEmpty();

    $failures = [];

    foreach ($map['moved'] as $from => $to) {
        $response = $this->get('/'.$from);

        if ($response->getStatusCode() !== 301) {
            $failures[] = "/{$from} answered {$response->getStatusCode()}, expected 301";

            continue;
        }

        if ($response->headers->get('Location') !== $to) {
            $failures[] = "/{$from} went to {$response->headers->get('Location')}, expected {$to}";
        }
    }

    expect($failures)->toBe([]);
});

it('sends every redirect somewhere that actually answers', function () {
    // A 301 to a 404 is worse than a 404: it spends the crawler's budget and
    // loses the page anyway.
    $map = require resource_path('site/redirects.php');
    $broken = [];

    foreach (array_unique(array_values($map['moved'])) as $target) {
        if ($this->get($target)->getStatusCode() !== 200) {
            $broken[] = $target;
        }
    }

    expect($broken)->toBe([]);
});

it('answers 410 for the page that is deliberately gone', function () {
    $map = require resource_path('site/redirects.php');

    foreach ($map['gone'] as $path) {
        $this->get('/'.$path)->assertStatus(410);
    }
});

/*
 | The metadata Google already has.
 |
 | Every indexed URL's <title> and description are pinned to what the static
 | site served, captured from the production build rather than retyped. The
 | port quietly rewrote eight of them and dropped the brand suffix from all 52
 | blog titles, which is a rewrite of 59 search results — so this is asserted
 | per page rather than trusted.
 */
it('serves the title and description each indexed URL is ranked under', function () {
    $expected = require __DIR__.'/fixtures/indexed-meta.php';

    foreach ($expected as $path => $meta) {
        $html = $this->get($path)->assertOk()->getContent();

        preg_match('#<title[^>]*>(.*?)</title>#s', $html, $t);
        preg_match('#<meta[^>]+name="description"[^>]+content="(.*?)"#s', $html, $d);

        expect(html_entity_decode($t[1] ?? ''))->toBe($meta['title'], "title for {$path}")
            ->and(html_entity_decode($d[1] ?? ''))->toBe($meta['description'], "description for {$path}");
    }
})->group('seo');

/*
 | `{{ $title }}` escapes, so a title attribute written with `&amp;` reaches
 | the browser tab — and the search result — as `&amp;amp;`. It has happened
 | twice: on the home page and on the literature hub.
 */
it('never double-escapes an entity in the head', function () {
    foreach (array_keys(require __DIR__.'/fixtures/indexed-meta.php') as $path) {
        expect($this->get($path)->getContent())->not->toContain('&amp;amp;', "on {$path}");
    }
})->group('seo');
