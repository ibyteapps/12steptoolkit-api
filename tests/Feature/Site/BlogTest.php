<?php

use App\Services\Site\BlogLibrary;

/**
 * The blog — 53 of the site's indexed URLs, and the half that keeps growing.
 *
 * `app_publish_article.sh` writes a new `.mdx` into `resources/site/blog/` on
 * a cron, so these tests are written against whatever is in that directory
 * rather than a fixed list: a post added next week is covered the day it
 * lands, and a post that fails to parse shows up as a failure rather than as
 * a page quietly missing from the index.
 */
beforeEach(function () {
    $this->blog = app(BlogLibrary::class);
});

it('reads every post in the directory', function () {
    $files = glob(resource_path('site/blog/*.mdx'));

    expect($this->blog->posts())->toHaveCount(count($files));
});

it('serves every post with its own title and description', function () {
    $failures = [];

    foreach ($this->blog->posts() as $post) {
        $response = $this->get($post['path']);

        if ($response->getStatusCode() !== 200) {
            $failures[] = "{$post['path']} answered {$response->getStatusCode()}";

            continue;
        }

        $html = $response->getContent();

        // The brand suffix is part of the indexed title on all 52 posts.
        if (! str_contains($html, '<title>'.e($post['title']).' | 12 Step Toolkit</title>')) {
            $failures[] = "{$post['path']} is missing its title";
        }

        if ($post['description'] !== '' && ! str_contains($html, e($post['description']))) {
            $failures[] = "{$post['path']} is missing its description";
        }
    }

    expect($failures)->toBe([]);
});

it('renders the markdown rather than printing it', function () {
    $post = $this->blog->posts()->first();

    $this->get($post['path'])
        ->assertOk()
        // Every post uses h2 headings and a list; if the converter stopped
        // working the page would still be 200 with the source in it.
        ->assertSee('<h2>', false)
        ->assertDontSee('## ', false);
});

it('lists every post on the index', function () {
    $response = $this->get('/blog')->assertOk();

    foreach ($this->blog->posts() as $post) {
        $response->assertSee($post['title'], false);
    }
});

it('does not serve a post that is marked unpublished', function () {
    $path = resource_path('site/blog/zz-a-draft-for-the-test.mdx');
    file_put_contents($path, <<<'MDX'
        ---
        title: "A draft"
        slug: "zz-a-draft-for-the-test"
        description: "Not for the public."
        date: "2026-10-09"
        published: false
        ---

        ## Not ready
        MDX);

    try {
        expect($this->blog->find('zz-a-draft-for-the-test'))->toBeNull();
        $this->get('/blog/zz-a-draft-for-the-test')->assertNotFound();
        $this->get('/blog')->assertDontSee('A draft');
    } finally {
        unlink($path);
    }
});

it('skips a file with no frontmatter instead of publishing an empty page', function () {
    $path = resource_path('site/blog/zz-broken-for-the-test.mdx');
    file_put_contents($path, "## No fence at the top\n");

    try {
        expect($this->blog->find('zz-broken-for-the-test'))->toBeNull();
    } finally {
        unlink($path);
    }
});

/*
 | Every post declares a hero and a card image. The paths are checked always;
 | the files only when they are present, because the 208 image files are 19MB
 | and a checkout without them is a normal state for a container that only
 | runs tests. On the server and on a full working copy this is the check that
 | a published post is not pointing at an image the generator failed to write.
 */
it('references images that are where it says they are', function () {
    $malformed = [];

    foreach ($this->blog->posts() as $post) {
        foreach (['image', 'cardImage'] as $key) {
            $path = $post[$key];

            if ($path === null) {
                continue;
            }

            if (! str_starts_with((string) $path, '/images/blog/')) {
                $malformed[] = "{$post['slug']}: {$key} is `{$path}`";
            }
        }
    }

    expect($malformed)->toBe([]);

    if (! is_dir(public_path('images/blog'))) {
        $this->markTestSkipped('public/images/blog is not in this checkout — paths checked, files not.');
    }

    $absent = [];

    foreach ($this->blog->posts() as $post) {
        foreach (['image', 'cardImage'] as $key) {
            if ($post[$key] !== null && ! is_file(public_path(ltrim((string) $post[$key], '/')))) {
                $absent[] = $post[$key];
            }
        }
    }

    expect(array_unique($absent))->toBe([]);
});

it('does not invent posts', function () {
    $this->get('/blog/no-such-post')->assertNotFound();
});
