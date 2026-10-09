<?php

namespace App\View\Components;

use App\Services\Site\Schema;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The public website's layout.
 *
 * Every page states its own `<title>`, description and keywords as attributes
 * rather than inheriting them from a `@section`, because those three are the
 * whole SEO surface of a page and they are per-page data. A shared section is
 * how 40 story pages end up with one description between them.
 *
 * ## The structured data is assembled here, not in the pages
 *
 * `base()` (who publishes this, what the site is) and the breadcrumb trail go
 * on **every** page, and the breadcrumbs are derived from the same `$crumbs`
 * the layout already renders — so the markup cannot describe a trail that is
 * not on the screen, and no page can forget to include them. A page adds only
 * its own node: an `Article` for a chapter, a `BlogPosting` for a post, the
 * app and the FAQs for the home page.
 */
class SiteLayout extends Component
{
    /** @param  array<int, array<string, mixed>>|null  $schema  This page's own JSON-LD nodes. */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $keywords = null,
        public ?array $crumbs = null,
        public string $ogType = 'website',
        public ?array $schema = null,
        public ?string $image = null,
        public bool $hideCta = false,
        public bool $bare = false,
    ) {}

    /** The whole `@graph` for this page, encoded, or null if there is none. */
    public function schemaJson(): ?string
    {
        $schema = app(Schema::class);

        return $schema->graph(array_merge(
            $schema->base(),
            [$schema->breadcrumbs($this->crumbs ?? [], request()->getPathInfo())],
            $this->schema ?? [],
        ));
    }

    public function render(): View
    {
        return view('site.layout');
    }
}
