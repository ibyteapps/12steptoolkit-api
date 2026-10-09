<?php

namespace App\Services\Site;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * The blog — 52 posts, and growing by itself.
 *
 * Each post is a file in `resources/site/blog/`: a YAML block between `---`
 * fences, then Markdown. That is the shape the static site used, and keeping
 * it is the point rather than an accident: **`app_publish_article.sh` writes
 * these files on a cron**, taking the next topic off a list, generating the
 * post and its images. Changing the format would mean rewriting the generator
 * too, for no gain. It writes a file here instead, and nothing else changes.
 *
 * The bodies are plain Markdown throughout — headings, lists, blockquotes and
 * a handful of tables, with no JSX anywhere, which was worth checking before
 * promising `.mdx` could be read by anything other than a JavaScript
 * toolchain.
 *
 * ## Why the cache key is a fingerprint of the directory
 *
 * A generator that publishes while nobody is deploying means the cache cannot
 * be tied to a deploy: a post appearing has to make the old entry unreachable
 * by itself, with no cron and no `cache:clear`.
 *
 * The key is a hash of every file's path and modification time. The obvious
 * cheaper version — the number of files and the newest timestamp — **is not
 * unique**, and the test suite caught it: two different posts written in the
 * same second with the same file count produce the same key, and the second
 * request is served the first one's index. Seconds are a coarse clock and an
 * article generator writes in bursts, so that is a real collision rather than
 * a theoretical one. Hashing the list costs the same `stat` calls that finding
 * the newest timestamp cost anyway.
 *
 * ## Why it is the file store and not the default one
 *
 * This application's default cache is the database, which is right for rate
 * limits and queue locks. It is wrong here. Every one of these pages is
 * derived from files sitting on the same disk as the code, so a database
 * outage has nothing to do with whether the blog can be rendered — and
 * putting the content cache there would mean the marketing site, 53 indexed
 * URLs of it, returning 500 whenever MySQL hiccups. The static export it
 * replaces had no database dependency at all, and neither should this.
 */
class BlogLibrary
{
    /** Words a minute, for the reading estimate. The static site used the same. */
    private const WORDS_PER_MINUTE = 200;

    /** @return Collection<int, array<string, mixed>> Newest first. */
    public function posts(): Collection
    {
        return collect($this->index())
            ->filter(fn (array $p): bool => $p['published'])
            ->sortByDesc('date')
            ->values();
    }

    public function find(string $slug): ?array
    {
        $post = $this->index()[$slug] ?? null;

        return $post !== null && $post['published'] ? $post : null;
    }

    /** The post's body as HTML. Rendered on demand; the index holds no bodies. */
    public function render(string $slug): ?string
    {
        $post = $this->find($slug);

        if ($post === null) {
            return null;
        }

        return $this->cache()->remember(
            "site.blog.body.{$slug}.{$post['mtime']}",
            now()->addDay(),
            fn (): string => (string) $this->converter()->convert($post['markdown'])->getContent(),
        );
    }

    /** The posts either side of this one, for the footer links. */
    public function neighbours(string $slug): array
    {
        $posts = $this->posts();
        $at = $posts->search(fn (array $p): bool => $p['slug'] === $slug);

        return $at === false
            ? ['newer' => null, 'older' => null]
            : ['newer' => $posts->get($at - 1), 'older' => $posts->get($at + 1)];
    }

    /** @return array<string, array<string, mixed>> */
    private function index(): array
    {
        $files = glob(resource_path('site/blog/*.mdx')) ?: [];
        $fingerprint = [];

        foreach ($files as $file) {
            $fingerprint[] = $file.':'.filemtime($file);
        }

        return $this->cache()->remember(
            'site.blog.index.'.md5(implode('|', $fingerprint)),
            now()->addDay(),
            function () use ($files): array {
                $index = [];

                foreach ($files as $file) {
                    $post = $this->parse($file);

                    if ($post !== null) {
                        $index[$post['slug']] = $post;
                    }
                }

                return $index;
            },
        );
    }

    private function parse(string $file): ?array
    {
        $raw = (string) file_get_contents($file);

        // The fence has to be the first thing in the file. A post without one
        // is a draft somebody saved oddly, not a page: skipped rather than
        // published with an empty title.
        if (! preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $raw, $m)) {
            return null;
        }

        $front = (array) (Yaml::parse($m[1]) ?: []);
        $slug = (string) ($front['slug'] ?? basename($file, '.mdx'));

        return [
            'slug' => $slug,
            'title' => (string) ($front['title'] ?? ''),
            'description' => (string) ($front['description'] ?? ''),
            'date' => isset($front['date']) ? Carbon::parse($front['date']) : null,
            'updated' => Carbon::parse($front['updated'] ?? $front['date'] ?? 'now'),
            'author' => (string) ($front['author'] ?? '12 Step Toolkit'),
            'category' => (string) ($front['category'] ?? 'Recovery'),
            'image' => $front['image'] ?? null,
            'cardImage' => $front['cardImage'] ?? $front['image'] ?? null,
            'imageAlt' => (string) ($front['imageAlt'] ?? ''),
            'featured' => (bool) ($front['featured'] ?? false),
            // Absent means published: the generator writes `published: true`,
            // and a file nobody has thought about should still be a page
            // rather than silently missing from the sitemap.
            'published' => (bool) ($front['published'] ?? true),
            'keywords' => $this->keywords($front['keywords'] ?? null),
            'minutes' => max(1, (int) ceil(str_word_count(strip_tags($m[2])) / self::WORDS_PER_MINUTE)),
            'markdown' => $m[2],
            'mtime' => (int) filemtime($file),
            'path' => '/blog/'.$slug,
        ];
    }

    /** The frontmatter gives a YAML list; a meta tag wants one string. */
    private function keywords(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return trim((string) $value);
    }

    /**
     * The content cache: on disk, beside the content. See the class docblock.
     *
     * Named through config rather than hard-coded, because `Cache::store()`
     * ignores `CACHE_STORE` — so a hard-coded `'file'` writes real files into
     * `storage/framework/cache/data` during a test run and leaves them there
     * for the next one. `phpunit.xml` points this at the array store.
     */
    private function cache(): CacheRepository
    {
        return Cache::store(config('site.cache_store'));
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "\n"],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        // Tables, strikethrough, autolinks and task lists — the handful of
        // GitHub-flavoured things the posts actually use.
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return new MarkdownConverter($environment);
    }

    /** A short excerpt for a card, when the description is missing. */
    public static function excerpt(array $post, int $words = 28): string
    {
        return $post['description'] !== ''
            ? $post['description']
            : Str::words(strip_tags((string) $post['markdown']), $words);
    }
}
