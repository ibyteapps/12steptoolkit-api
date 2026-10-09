<?php

namespace App\Http\Controllers\Site;

use App\Services\Site\BlogLibrary;
use App\Services\Site\LiteratureLibrary;
use Illuminate\Http\Response;

/**
 * `sitemap.xml` and `robots.txt`, generated.
 *
 * Generated rather than copied, which is the whole reason this is worth a
 * controller: the static site's sitemap was written by `next-sitemap` at build
 * time, so a post published by the article generator did not appear in it
 * until somebody rebuilt. Here the list comes from the same places the pages
 * do, so it cannot disagree with them.
 *
 * `SITE_NOINDEX` is honoured: a `robots.txt` that allows crawling while every
 * page carries `noindex` is a mixed signal, and the one that matters is the
 * one served by whichever host is not the public website.
 */
class SitemapController extends Controller
{
    public function __construct(
        private readonly LiteratureLibrary $literature,
        private readonly BlogLibrary $blog,
    ) {}

    public function sitemap(): Response
    {
        $urls = [];

        // The order is the order a reader would meet them, which is also the
        // order the old sitemap used: the pages, then the literature, then the
        // blog.
        foreach (['/', '/aa-literature', '/blog', '/get-app', '/contacts', '/privacy', '/terms'] as $path) {
            $urls[] = ['loc' => $path, 'changefreq' => 'monthly', 'priority' => $path === '/' ? '1.0' : '0.7'];
        }

        foreach (array_keys(LiteratureLibrary::SECTIONS) as $section) {
            $urls[] = ['loc' => '/aa-literature/'.$section, 'changefreq' => 'monthly', 'priority' => '0.7'];
        }

        foreach (array_keys($this->literature->all()) as $slug) {
            $urls[] = ['loc' => $this->literature->pathFor($slug), 'changefreq' => 'yearly', 'priority' => '0.6'];
        }

        foreach ($this->blog->posts() as $post) {
            $urls[] = [
                'loc' => $post['path'],
                'lastmod' => $post['updated']->toDateString(),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        return response()
            ->view('site.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $lines = config('site.noindex')
            ? ['User-agent: *', 'Disallow: /']
            : [
                'User-agent: *',
                'Allow: /',
                // The embedded PHP app's landing pages: reachable because
                // emails link to them, and no business in an index.
                'Disallow: /app/',
                'Disallow: /console',
                '',
                'Sitemap: '.url('/sitemap.xml'),
            ];

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
