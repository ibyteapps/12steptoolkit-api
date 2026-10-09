<x-site-layout
    title="A.A. Literature Library — Big Book, Stories, Readings & Prayers"
    description="Read 105 pieces of A.A. literature free online: the Big Book, all 69 personal stories from the first and second editions, daily readings and prayers."
    keywords="aa literature, big book online, aa prayers, aa readings, personal stories"
    :crumbs="['A.A. Literature' => null]"
    :schema="$schema"
>
    <h1>A.A. Literature</h1>
    <p class="lede">The basic text, the personal stories from the first and second
       editions, the prayers and the readings most often asked for — all of it
       free to read here.</p>

    <div class="cards">
        @foreach($sections as $slug => $heading)
            <a href="/aa-literature/{{ $slug }}">
                {{ $heading }}
                <span class="count">{{ \App\Services\Site\LiteratureLibrary::countLabel($slug, $counts[$slug]) }}</span>
            </a>
        @endforeach
    </div>
</x-site-layout>
