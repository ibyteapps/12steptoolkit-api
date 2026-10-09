<?php

namespace App\Services\Site;

use Illuminate\Support\Collection;

/**
 * The JSON-LD the public pages carry.
 *
 * Ported from the static site's `components/seo/schema.js`, node for node,
 * because this is what earns the rich results: the app result with its star
 * rating, the FAQ accordion in the search listing, the breadcrumb trail above
 * every literature page.
 *
 * **It had to be ported rather than skipped.** The pages this application
 * took over were already emitting all of it; shipping them without would have
 * been a silent regression — the pages would still answer 200 with the right
 * titles and quietly lose their rich results over the following weeks, which
 * is the hardest kind of mistake to notice.
 *
 * Everything goes into one `@graph` so Google reads a single connected
 * document rather than a pile of unrelated nodes, and the `@id`s are what
 * connect them: the organisation is declared once and referenced everywhere
 * else.
 */
class Schema
{
    public function __construct(private readonly BlogLibrary $blog) {}

    private function url(string $path = '/'): string
    {
        return rtrim(url($path), '/') ?: url('/');
    }

    private function organisationId(): string
    {
        return $this->url().'/#organization';
    }

    private function websiteId(): string
    {
        return $this->url().'/#website';
    }

    /** Wraps nodes into one document. Null when there is nothing to say. */
    public function graph(array $nodes): ?string
    {
        $graph = array_values(array_filter($nodes));

        if ($graph === []) {
            return null;
        }

        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** On every page: who publishes it, and what the site is. */
    public function base(): array
    {
        $identity = config('site.identity');

        return [
            [
                '@type' => 'Organization',
                '@id' => $this->organisationId(),
                'name' => $identity['name'],
                'legalName' => $identity['legalName'],
                'url' => $this->url(),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $this->url('/images/logo.png'),
                    'width' => 240,
                    'height' => 60,
                ],
                'sameAs' => $identity['social'],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $this->websiteId(),
                'url' => $this->url(),
                'name' => $identity['name'],
                'description' => $identity['description'],
                'inLanguage' => $identity['lang'],
                'publisher' => ['@id' => $this->organisationId()],
            ],
        ];
    }

    /**
     * The app, with its star rating.
     *
     * The rating is the weighted average of the two stores, derived here so it
     * cannot disagree with the figures rendered beside it. Marked-up ratings a
     * visitor cannot see are what a structured-data manual action is for.
     */
    public function app(): array
    {
        $identity = config('site.identity');
        $rating = self::combinedRating();

        $schema = [
            '@type' => 'MobileApplication',
            '@id' => $this->url().'/#app',
            'name' => $identity['name'],
            'description' => $identity['description'],
            'applicationCategory' => 'HealthApplication',
            'applicationSubCategory' => 'Addiction Recovery',
            'operatingSystem' => 'iOS, Android, macOS, Web',
            'url' => $this->url(),
            'installUrl' => [$identity['appStore'], $identity['playStore']],
            'screenshot' => $this->url('/images/image-12.webp'),
            'publisher' => ['@id' => $this->organisationId()],
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'GBP',
                'availability' => 'https://schema.org/InStock',
            ],
        ];

        if ($rating['count'] > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $rating['value'],
                'ratingCount' => (string) $rating['count'],
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return $schema;
    }

    /**
     * The two store ratings as one, weighted by how many ratings each has.
     *
     * Derived rather than stored so the number in the markup is always the
     * number the parts add up to.
     *
     * @return array{value: float, count: int, stores: array, asOf: string}
     */
    public static function combinedRating(): array
    {
        $ratings = config('site.identity.ratings');
        $stores = $ratings['stores'] ?? [];
        $count = array_sum(array_column($stores, 'count'));

        $weighted = 0.0;
        foreach ($stores as $store) {
            $weighted += $store['value'] * $store['count'];
        }

        return [
            'value' => $count > 0 ? round($weighted / $count, 1) : 0.0,
            'count' => (int) $count,
            'stores' => $stores,
            'asOf' => $ratings['asOf'] ?? '',
        ];
    }

    /**
     * The breadcrumb trail, as rendered.
     *
     * Home is prepended because the rendered trail starts there, and the
     * markup has to describe what is on the page.
     *
     * @param  array<string, ?string>  $crumbs  label => href, href null for the current page
     */
    public function breadcrumbs(array $crumbs, string $currentPath): ?array
    {
        if ($crumbs === []) {
            return null;
        }

        $trail = ['Home' => '/'] + $crumbs;
        $items = [];
        $position = 1;
        $last = array_key_last($trail);

        foreach ($trail as $label => $href) {
            $href ??= $label === $last ? $currentPath : null;

            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $label,
                'item' => $href === null ? null : $this->url($href),
            ], fn ($v): bool => $v !== null);
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @param  array<int, array{question: string, answer: array<int, string>}>  $faqs */
    public function faqs(array $faqs): ?array
    {
        if ($faqs === []) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    // Joined with a space, as `faqsForSchema()` did: one
                    // answer per question, and the paragraphs on the page are
                    // the same words.
                    'text' => implode(' ', (array) $faq['answer']),
                ],
            ], $faqs),
        ];
    }

    /** One blog post. */
    public function article(array $post): array
    {
        $url = $this->url($post['path']);

        return [
            '@type' => 'BlogPosting',
            '@id' => $url.'#article',
            'headline' => $post['title'],
            'description' => $post['description'],
            'image' => $post['image'] === null ? [] : [$this->url($post['image'])],
            'datePublished' => $post['date']?->toDateString(),
            'dateModified' => $post['updated']->toDateString(),
            'author' => ['@id' => $this->organisationId()],
            'publisher' => ['@id' => $this->organisationId()],
            'isPartOf' => ['@id' => $this->websiteId()],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'articleSection' => $post['category'],
            'keywords' => $post['keywords'],
            'inLanguage' => config('site.identity.lang'),
        ];
    }

    /** The blog hub, listing its most recent posts. */
    public function blogList(Collection $posts): array
    {
        return [
            '@type' => 'Blog',
            '@id' => $this->url('/blog').'#blog',
            'name' => config('site.identity.name').' Recovery Blog',
            'description' => 'Practical articles about recovery, sobriety, the Twelve Steps, '
                .'personal growth and maintaining a healthy recovery routine.',
            'url' => $this->url('/blog'),
            'publisher' => ['@id' => $this->organisationId()],
            'inLanguage' => config('site.identity.lang'),
            'blogPost' => $posts->take(20)->map(fn (array $post): array => array_filter([
                '@type' => 'BlogPosting',
                '@id' => $this->url($post['path']).'#article',
                'headline' => $post['title'],
                'description' => $post['description'],
                'url' => $this->url($post['path']),
                'datePublished' => $post['date']?->toDateString(),
                'dateModified' => $post['updated']->toDateString(),
                'image' => $post['cardImage'] === null ? null : $this->url($post['cardImage']),
            ], fn ($v): bool => $v !== null))->values()->all(),
        ];
    }

    /**
     * One literature page.
     *
     * `isBasedOn` names the book it comes from, with Alcoholics Anonymous as
     * the author — this text is theirs, and the markup says so.
     */
    public function literature(string $title, string $description, string $path, ?string $partOf = null): array
    {
        $url = $this->url($path);

        return array_filter([
            '@type' => 'Article',
            '@id' => $url.'#article',
            'headline' => $title,
            'description' => $description,
            'url' => $url,
            'isPartOf' => ['@id' => $this->websiteId()],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'publisher' => ['@id' => $this->organisationId()],
            'inLanguage' => config('site.identity.lang'),
            'isBasedOn' => $partOf === null ? null : [
                '@type' => 'Book',
                'name' => $partOf,
                'author' => ['@type' => 'Organization', 'name' => 'Alcoholics Anonymous'],
            ],
        ], fn ($v): bool => $v !== null);
    }

    /** An index page that lists things. */
    public function collection(string $title, string $description, string $path, array $items = []): array
    {
        $url = $this->url($path);

        return array_filter([
            '@type' => 'CollectionPage',
            '@id' => $url.'#collection',
            'name' => $title,
            'description' => $description,
            'url' => $url,
            'isPartOf' => ['@id' => $this->websiteId()],
            'inLanguage' => config('site.identity.lang'),
            'mainEntity' => $items === [] ? null : [
                '@type' => 'ItemList',
                'numberOfItems' => count($items),
                'itemListElement' => array_map(fn (int $i, array $item): array => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $item['name'],
                    'url' => $this->url($item['path']),
                ], array_keys($items), $items),
            ],
        ], fn ($v): bool => $v !== null);
    }
}
