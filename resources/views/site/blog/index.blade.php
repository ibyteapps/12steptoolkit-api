{{--
    The blog index.

    Cards with the article images, as the production site had them — the
    first Laravel cut rendered a bare text list and left 208 images in
    `public/images/blog` unused.
--}}
<x-site-layout
    title="Recovery Blog | Sobriety, Sponsorship and the Twelve Steps | 12 Step Toolkit"
    description="Practical writing on early sobriety, finding and working with a sponsor, the Twelve Steps and the Twelve Traditions of Alcoholics Anonymous."
    keywords="aa blog, recovery blog, sobriety advice, aa sponsorship, twelve steps"
    :crumbs="['Blog' => null]"
    :schema="$schema"
>
<x-slot:styles>
<style>
    .postgrid { display: grid; gap: 26px; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); }
    .pcard {
        display: flex; flex-direction: column; background: var(--card);
        border: 1px solid var(--rule); border-radius: var(--r-lg); overflow: hidden;
        text-decoration: none; color: var(--ink); box-shadow: var(--shadow-sm);
        transition: transform .2s var(--ease), box-shadow .2s var(--ease);
    }
    .pcard:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .pcard img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; display: block; }
    .pcard .body { padding: 20px 22px 24px; display: flex; flex-direction: column; flex: 1; }
    .pcard .cat { font-size: .6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: var(--accent); }
    .pcard h2 { font-size: 1.125rem; line-height: 1.33; margin: 9px 0 9px; }
    .pcard p { margin: 0 0 16px; color: var(--ink-soft); font-size: .9375rem; line-height: 1.55; }
    .pcard .foot { margin-top: auto; font-size: .8125rem; color: var(--ink-faint); }
</style>
</x-slot:styles>

    <h1>Blog</h1>
    <p class="lede">{{ $posts->count() }} articles on early sobriety, sponsorship and working the Steps.</p>

    <div class="postgrid">
        @foreach($posts as $post)
            <a class="pcard" href="{{ $post['path'] }}">
                @if($post['cardImage'])
                    <img src="{{ $post['cardImage'] }}" alt="" width="400" height="225" loading="lazy">
                @endif
                <div class="body">
                    <span class="cat">{{ $post['category'] }}</span>
                    <h2>{{ $post['title'] }}</h2>
                    <p>{{ \Illuminate\Support\Str::limit($post['description'], 125) }}</p>
                    <span class="foot">
                        @isset($post['date'])<time datetime="{{ $post['date']->toDateString() }}">{{ $post['date']->format('j F Y') }}</time> &middot; @endisset
                        {{ $post['minutes'] }} min read
                    </span>
                </div>
            </a>
        @endforeach
    </div>
</x-site-layout>
