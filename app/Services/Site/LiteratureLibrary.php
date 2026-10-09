<?php

namespace App\Services\Site;

use Illuminate\Support\Collection;

/**
 * The A.A. literature, as the public website serves it.
 *
 * 105 documents that between them are **180 of the 205 URLs** in
 * `sitemap.xml` — the bulk of what this domain ranks for. The index is
 * `resources/site/literature.php`, converted from the three registries the
 * Next.js site used rather than retyped, so every title and description is
 * byte-for-byte what Google already holds.
 *
 * Nothing here is clever. It is a lookup table and a file read, and the only
 * rule worth stating is the one about slugs below, because getting it wrong
 * silently changes 180 addresses.
 */
class LiteratureLibrary
{
    /**
     * The five sections, in the order the hub lists them.
     *
     * Keyed by the URL segment, because the segment is the identity: these
     * strings appear in indexed addresses and are not free to tidy.
     */
    public const SECTIONS = [
        'big-book' => 'The Big Book',
        'stories-edition-1' => 'Personal Stories, First Edition',
        'stories-edition-2' => 'Personal Stories, Second Edition',
        'prayers' => 'Prayers',
        'readings' => 'Readings',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $index = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->index ??= require resource_path('site/literature.php');
    }

    /**
     * One document's metadata, or null.
     *
     * The key is the registry slug, which is not the same as the URL — see
     * [pathFor].
     */
    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * The URL for a slug.
     *
     * **The one rule.** A slug with no slash is a Big Book section and sits
     * under `/aa-literature/big-book/`; a slug with one already names its
     * section, so it is used as it stands. `preface` →
     * `/aa-literature/big-book/preface`; `prayers/serenity-prayer` →
     * `/aa-literature/prayers/serenity-prayer`.
     *
     * Written once, here, because it is the difference between keeping 180
     * indexed addresses and inventing 180 new ones.
     */
    public function pathFor(string $slug): string
    {
        return str_contains($slug, '/')
            ? '/aa-literature/'.$slug
            : '/aa-literature/big-book/'.$slug;
    }

    /** Which section a slug belongs to. */
    public function sectionOf(string $slug): string
    {
        return str_contains($slug, '/') ? explode('/', $slug, 2)[0] : 'big-book';
    }

    /**
     * Every document in a section, in reading order.
     *
     * The Big Book sections carry an explicit `order`; the stories, prayers
     * and readings are listed in the order the registry holds them, which is
     * the order the book prints them in.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function section(string $section): Collection
    {
        $documents = collect($this->all())
            ->filter(fn (array $meta, string $slug): bool => $this->sectionOf($slug) === $section)
            ->map(fn (array $meta, string $slug): array => $meta + [
                'slug' => $slug,
                'path' => $this->pathFor($slug),
                'label' => $meta['menuLabel'] ?? $this->labelFrom($meta),
            ]);

        return $documents->contains(fn (array $m): bool => isset($m['order']))
            ? $documents->sortBy('order')->values()
            : $documents->values();
    }

    /** How many documents each section holds, for the hub. */
    public function counts(): array
    {
        return collect(array_keys(self::SECTIONS))
            ->mapWithKeys(fn (string $s): array => [$s => $this->section($s)->count()])
            ->all();
    }

    /**
     * A link label for a document that has no `menuLabel`.
     *
     * The registry titles are written for search results — "The Doctor's
     * Nightmare | 1st Edition Personal Stories | AA" — so the part before the
     * first pipe is the name of the thing, which is what a list wants.
     */
    private function labelFrom(array $meta): string
    {
        if (isset($meta['breadcrumb']) && is_array($meta['breadcrumb']) && $meta['breadcrumb'] !== []) {
            return (string) end($meta['breadcrumb']);
        }

        return trim(explode('|', (string) ($meta['title'] ?? ''))[0]);
    }
}
