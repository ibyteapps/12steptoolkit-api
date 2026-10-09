<x-site-layout
    title="Recovery Blog | Sobriety, Sponsorship and the Twelve Steps | 12 Step Toolkit"
    description="Practical writing on early sobriety, finding and working with a sponsor, the Twelve Steps and the Twelve Traditions of Alcoholics Anonymous."
    keywords="aa blog, recovery blog, sobriety advice, aa sponsorship, twelve steps"
    :crumbs="['Blog' => null]"
    :schema="$schema"
>
    <h1>Blog</h1>
    <p class="lede">{{ $posts->count() }} articles on early sobriety, sponsorship and
       working the Steps.</p>

    <ul class="index">
        @foreach($posts as $post)
            <li>
                <a href="{{ $post['path'] }}">
                    {{ $post['title'] }}
                    <span class="sub">
                        {{ $post['description'] }}
                    </span>
                    <span class="sub">
                        {{ $post['category'] }}
                        @isset($post['date']) &middot; {{ $post['date']->format('j F Y') }} @endisset
                        &middot; {{ $post['minutes'] }} min read
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
</x-site-layout>
