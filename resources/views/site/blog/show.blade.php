{{--
    One post. `$body` is Markdown the application rendered itself — see
    `App\Services\Site\BlogLibrary` — from a file in this repository, written
    either by hand or by the article generator. No user input is involved,
    which is why it is rendered unescaped.

    The header, hero image and related rail follow what the production site
    served: a centred header over a 16:8.5 hero, a standfirst, and three more
    articles underneath. The first Laravel cut had none of them.
--}}
<x-site-layout
    :title="$post['title']"
    :description="$post['description']"
    :keywords="$post['keywords']"
    og-type="article"
    :image="$post['image']"
    :crumbs="['Blog' => '/blog', $post['title'] => null]"
    :schema="$schema"
>
<x-slot:styles>
<style>
    .ahead { max-width: 48rem; margin: 0 auto clamp(28px, 4vw, 40px); text-align: center; }
    .ahead .cat { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: var(--accent); }
    .ahead h1 { margin: 14px 0 18px; font-size: clamp(1.875rem, 1.3rem + 2.4vw, 2.875rem); }
    .ahead .stand { font-size: 1.1875rem; line-height: 1.65; color: var(--ink-soft); margin: 0 auto 18px; max-width: 44rem; }
    .ahead .meta { justify-content: center; display: flex; gap: 8px 18px; flex-wrap: wrap; color: var(--ink-faint); font-size: .9375rem; margin: 0; }

    .hero-img {
        width: 100%; max-width: 62rem; margin: 0 auto clamp(32px, 4.5vw, 52px);
        aspect-ratio: 16 / 8.5; object-fit: cover; display: block;
        border-radius: var(--r-lg); box-shadow: var(--shadow-lg); background: var(--page-alt);
    }

    .related { max-width: var(--shell); margin: clamp(56px, 7vw, 80px) auto 0; }
    .related h2 { font-size: 1.375rem; margin: 0 0 22px; }
    .related .grid { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
    .related a {
        display: flex; flex-direction: column; background: var(--card); border: 1px solid var(--rule);
        border-radius: var(--r-lg); overflow: hidden; text-decoration: none; color: var(--ink);
        box-shadow: var(--shadow-sm); transition: transform .2s var(--ease), box-shadow .2s var(--ease);
    }
    .related a:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .related img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; display: block; }
    .related .body { padding: 18px 20px 22px; }
    .related h3 { font-size: 1rem; line-height: 1.35; margin: 0 0 6px; }
    .related span { font-size: .8125rem; color: var(--ink-faint); }
</style>
</x-slot:styles>

    <header class="ahead">
        <span class="cat">{{ $post['category'] }}</span>
        <h1>{{ $post['title'] }}</h1>
        @if($post['description'])
            <p class="stand">{{ $post['description'] }}</p>
        @endif
        <p class="meta">
            @isset($post['date'])
                <time datetime="{{ $post['date']->toDateString() }}">{{ $post['date']->format('j F Y') }}</time>
            @endisset
            <span>{{ $post['minutes'] }} min read</span>
        </p>
    </header>

    @if($post['image'])
        <img class="hero-img" src="{{ $post['image'] }}" alt="" width="992" height="527" loading="eager">
    @endif

    <article class="prose">
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

    @if($related->isNotEmpty())
        <section class="related">
            <h2>More from the blog</h2>
            <div class="grid">
                @foreach($related as $other)
                    <a href="{{ $other['path'] }}">
                        @if($other['cardImage'])
                            <img src="{{ $other['cardImage'] }}" alt="" width="400" height="225" loading="lazy">
                        @endif
                        <div class="body">
                            <h3>{{ $other['title'] }}</h3>
                            <span>{{ $other['category'] }} &middot; {{ $other['minutes'] }} min read</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-site-layout>
