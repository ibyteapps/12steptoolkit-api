{{--
    One literature page.

    `$body` is the readable part of the original HTML file, which brings its
    own markup and its own <style> — see `App\Services\Site\HtmlDocument`.
    Rendered unescaped because it *is* the content: 105 files scanned and
    cleaned years ago, in this repository, with no user input anywhere near
    them.
--}}
<x-site-layout
    :title="$meta['title']"
    :description="$meta['desc'] ?? null"
    :keywords="$meta['keywords'] ?? null"
    og-type="article"
    :crumbs="[
        'A.A. Literature' => '/aa-literature',
        $sectionHeading => '/aa-literature/'.$section,
        ($meta['menuLabel'] ?? trim(explode('|', $meta['title'])[0])) => null,
    ]"
    :schema="$schema"
>
    <h1>{{ $meta['menuLabel'] ?? trim(explode('|', $meta['title'])[0]) }}</h1>

    <article class="reading">
        {!! $body !!}
    </article>
</x-site-layout>
