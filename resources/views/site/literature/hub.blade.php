<x-site-layout
    title="A.A. Literature | The Big Book, Prayers and Readings | 12 Step Toolkit"
    description="Read the Big Book of Alcoholics Anonymous, the personal stories from both editions, the A.A. prayers and the readings — free, and without an account."
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
                <span class="count">{{ $counts[$slug] }} {{ \Illuminate\Support\Str::plural('page', $counts[$slug]) }}</span>
            </a>
        @endforeach
    </div>
</x-site-layout>
