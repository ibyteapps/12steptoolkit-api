<?php

use App\Services\Site\HtmlDocument;
use App\Services\Site\LiteratureLibrary;

/**
 * Every indexed literature address, walked.
 *
 * This is the test that makes moving the website safe rather than hopeful.
 * `docs/WEBSITE_TAKEOVER.md` counts 205 URLs in `sitemap.xml`, 180 of them
 * under `/aa-literature`, and the failure that costs real traffic is a handful
 * of them quietly 404ing after a change nobody thought touched them. So the
 * list is not sampled: all 105 documents and all 5 section indexes are
 * requested, and each one has to answer with the title Google already holds.
 *
 * It takes a couple of seconds. A couple of seconds is cheap next to finding
 * out from the traffic graph.
 */
beforeEach(function () {
    $this->library = app(LiteratureLibrary::class);
});

it('has the whole library indexed', function () {
    expect($this->library->all())->toHaveCount(105);

    expect($this->library->counts())->toBe([
        'big-book' => 17,
        'stories-edition-1' => 29,
        'stories-edition-2' => 40,
        'prayers' => 11,
        'readings' => 8,
    ]);
});

it('keeps the slug-to-URL rule that 180 addresses depend on', function () {
    expect($this->library->pathFor('preface'))->toBe('/aa-literature/big-book/preface')
        ->and($this->library->pathFor('prayers/serenity-prayer'))->toBe('/aa-literature/prayers/serenity-prayer')
        ->and($this->library->pathFor('stories-edition-2/jims-story'))->toBe('/aa-literature/stories-edition-2/jims-story');
});

it('serves every single literature page with the title it is indexed under', function () {
    $failures = [];

    foreach ($this->library->all() as $slug => $meta) {
        $path = $this->library->pathFor($slug);
        $response = $this->get($path);

        if ($response->getStatusCode() !== 200) {
            $failures[] = "{$path} answered {$response->getStatusCode()}";

            continue;
        }

        if (! str_contains($response->getContent(), '<title>'.e($meta['title']).'</title>')) {
            $failures[] = "{$path} does not carry its indexed title";
        }
    }

    expect($failures)->toBe([]);
});

it('serves every section index', function () {
    foreach (array_keys(LiteratureLibrary::SECTIONS) as $section) {
        $this->get("/aa-literature/{$section}")->assertOk();
    }

    $this->get('/aa-literature')->assertOk();
});

it('puts the description and keywords on the page, not just the title', function () {
    $meta = $this->library->find('readings/just-for-today');

    $this->get('/aa-literature/readings/just-for-today')
        ->assertOk()
        ->assertSee('<meta name="description" content="'.e($meta['desc']).'">', false)
        ->assertSee('<meta name="keywords" content="'.e($meta['keywords']).'">', false)
        ->assertSee('<link rel="canonical" href="'.url('/aa-literature/readings/just-for-today').'">', false);
});

it('actually renders the text of the document, not just its frame', function () {
    // A line from the middle of the chapter's own HTML file, so this fails if
    // the body extraction ever starts returning the <head> or nothing.
    $this->get('/aa-literature/big-book/there-is-a-solution')
        ->assertOk()
        ->assertSee('we have discovered a common solution', false);
});

/*
 | The 69 duplicate addresses the static export wrote. The live server already
 | 301s them and Google has consolidated that, so answering 404 here would
 | undo work already done.
 */
it('redirects a story addressed under big-book to its own address', function () {
    $this->get('/aa-literature/big-book/stories-edition-1/a-close-shave')
        ->assertStatus(301)
        ->assertRedirect('/aa-literature/stories-edition-1/a-close-shave');
});

it('does not invent pages for addresses that were never indexed', function () {
    $this->get('/aa-literature/big-book/no-such-chapter')->assertNotFound();
    $this->get('/aa-literature/no-such-section')->assertNotFound();
    $this->get('/aa-literature/prayers/no-such-prayer')->assertNotFound();
});

/*
 | The extractor reproduces what the Next.js site did — inject the whole file
 | into an article, where the parser drops the document wrapper and keeps the
 | <style>. These assert that, rather than a tidier result nobody asked for.
 */
it('keeps a style block the file carries and drops the document wrapper', function () {
    $html = HtmlDocument::readable(
        '<!DOCTYPE html><html><head><style>a{color:#BF0210}</style></head><body><p>Hello</p></body></html>',
    );

    expect($html)->toContain('a{color:#BF0210}')
        ->toContain('<p>Hello</p>')
        ->not->toContain('DOCTYPE')
        ->not->toContain('<head>')
        ->not->toContain('<body>');
});

it('copes with a file that is a fragment rather than a document', function () {
    expect(HtmlDocument::readable('<p>Just a paragraph</p>'))->toBe('<p>Just a paragraph</p>');
});
