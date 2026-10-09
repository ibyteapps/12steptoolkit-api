{{--
    One post. `$body` is Markdown the application rendered itself — see
    `App\Services\Site\BlogLibrary` — from a file in this repository, written
    either by hand or by the article generator. No user input is involved,
    which is why it is rendered unescaped.
--}}
<x-site-layout
    :title="$post['title']"
    :description="$post['description']"
    :keywords="$post['keywords']"
    og-type="article"
    :crumbs="['Blog' => '/blog', $post['title'] => null]"
    :schema="$schema"
>
    <h1>{{ $post['title'] }}</h1>
    <p class="meta">
        {{ $post['category'] }}
        @isset($post['date']) &middot; <time datetime="{{ $post['date']->toDateString() }}">{{ $post['date']->format('j F Y') }}</time> @endisset
        &middot; {{ $post['minutes'] }} min read
    </p>

    <article class="reading prose">
        {!! $body !!}
    </article>

    @if($neighbours['newer'] || $neighbours['older'])
        <nav class="nextprev">
            @isset($neighbours['older'])
                <a href="{{ $neighbours['older']['path'] }}">
                    <span>Previous</span>{{ $neighbours['older']['title'] }}
                </a>
            @endisset
            @isset($neighbours['newer'])
                <a href="{{ $neighbours['newer']['path'] }}">
                    <span>Next</span>{{ $neighbours['newer']['title'] }}
                </a>
            @endisset
        </nav>
    @endif
</x-site-layout>
