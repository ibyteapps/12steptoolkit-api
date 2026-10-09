<?php

namespace App\Http\Controllers\Site;

use App\Services\Site\HtmlDocument;
use App\Services\Site\LiteratureLibrary;
use App\Services\Site\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The A.A. literature pages — 180 of this domain's 205 indexed URLs.
 *
 * Every address this serves is one a search engine already knows, so the
 * routes are written to the addresses rather than the addresses to the routes.
 * `routes/site.php` has the ordering that keeps them apart.
 */
class LiteratureController extends Controller
{
    /**
     * The book each section's text comes from, for `isBasedOn`, named exactly
     * as the production site named it.
     *
     * The readings belong here: How It Works, the Promises, the Twelve
     * Traditions and Just For Today are Big Book text, and the live pages all
     * carried the attribution — leaving it off dropped the `Book` node from
     * eight indexed pages. The prayers are gathered from across the
     * literature and across the fellowship, and named no book live either.
     */
    private const PART_OF = [
        'big-book' => 'Alcoholics Anonymous (The Big Book)',
        'readings' => 'Alcoholics Anonymous (The Big Book)',
        'stories-edition-1' => 'Alcoholics Anonymous — Personal Stories (Edition 1)',
        'stories-edition-2' => 'Alcoholics Anonymous — Personal Stories (Edition 2)',
    ];

    public function __construct(
        private readonly LiteratureLibrary $library,
        private readonly Schema $schema,
    ) {}

    /** `/aa-literature` — the hub. */
    public function hub(): View
    {
        return view('site.literature.hub', [
            'sections' => LiteratureLibrary::SECTIONS,
            'counts' => $this->library->counts(),
            'schema' => [$this->schema->collection(
                'A.A. Literature',
                'The Big Book, the personal stories from both editions, the prayers and the readings.',
                '/aa-literature',
                array_map(
                    fn (string $slug, string $name): array => ['name' => $name, 'path' => '/aa-literature/'.$slug],
                    array_keys(LiteratureLibrary::SECTIONS),
                    array_values(LiteratureLibrary::SECTIONS),
                ),
            )],
        ]);
    }

    /** `/aa-literature/{section}` — one section's contents. */
    public function section(string $section): View
    {
        if (! array_key_exists($section, LiteratureLibrary::SECTIONS)) {
            throw new NotFoundHttpException;
        }

        $documents = $this->library->section($section);

        return view('site.literature.section', [
            'section' => $section,
            'heading' => LiteratureLibrary::SECTIONS[$section],
            'documents' => $documents,
            'schema' => [$this->schema->collection(
                LiteratureLibrary::SECTIONS[$section],
                LiteratureLibrary::SECTIONS[$section].' from the literature of Alcoholics Anonymous.',
                '/aa-literature/'.$section,
                $documents->map(fn (array $d): array => ['name' => $d['label'], 'path' => $d['path']])->all(),
            )],
        ]);
    }

    /** `/aa-literature/big-book/{slug}` and `/aa-literature/{section}/{slug}`. */
    public function document(string $slug): View
    {
        $meta = $this->library->find($slug);

        if ($meta === null) {
            throw new NotFoundHttpException;
        }

        $file = resource_path('site/literature/'.$meta['fileName']);

        if (! is_file($file)) {
            throw new NotFoundHttpException;
        }

        $section = $this->library->sectionOf($slug);

        return view('site.literature.document', [
            'meta' => $meta,
            'section' => $section,
            'sectionHeading' => LiteratureLibrary::SECTIONS[$section] ?? '',
            'body' => HtmlDocument::readable((string) file_get_contents($file)),
            'schema' => [$this->schema->literature(
                $meta['title'],
                $meta['desc'] ?? '',
                $this->library->pathFor($slug),
                self::PART_OF[$section] ?? null,
            )],
        ]);
    }

    /**
     * The 69 addresses that should never have existed, sent where they belong.
     *
     * The static export wrote every personal story out **twice**: once at its
     * own address, and once with the section folded into the filename under
     * `/aa-literature/big-book/` — because the Next.js `[slug]` route there
     * matched registry keys that contain a slash, and the export encoded the
     * slash into the filename. All 138 went into `sitemap.xml`.
     *
     * The live server already 301s these, so this keeps a redirect that
     * Google has seen and consolidated rather than introducing a 404 where
     * there is currently a redirect. It is two lines; removing it would undo
     * work already done.
     */
    public function storyUnderBigBook(string $section, string $slug): RedirectResponse
    {
        return redirect('/aa-literature/'.$section.'/'.$slug, 301);
    }
}
