{{--
    The store call to action.

    The production site carried store links on all 170 of its pages and the
    rated CTA block on 166 of them; the first Laravel cut carried them on one.
    This component exists so that is structurally impossible to repeat: the
    layout renders it after the slot on every page unless the page opts out
    with `hide-cta`, which only the home page and /get-app do, because they
    make the same offer themselves.

    The rating is rendered, not just marked up. Google issues a structured-data
    manual action for an `aggregateRating` describing numbers a visitor cannot
    see on the page.
--}}
@props(['title' => null])
@php
    $identity = config('site.identity');
    $stores = $identity['ratings']['stores'] ?? [];
    $count = array_sum(array_column($stores, 'count'));
    $mean = $count > 0
        ? array_sum(array_map(fn ($s) => $s['value'] * $s['count'], $stores)) / $count
        : null;
@endphp
<aside class="appcta" aria-label="Get the app">
    <div>
        <h2>{{ $title ?? 'Work the Steps on your phone' }}</h2>
        <p>{{ $slot->isNotEmpty() ? $slot : 'Count your sobriety to the second, write your inventories where a sponsor can see them, and carry the whole Big Book with you. Free, on Android and iOS.' }}</p>
        @if($mean !== null)
            <p class="rating">
                <span class="stars" aria-hidden="true">★★★★★</span>
                <b>{{ number_format($mean, 1) }}</b>
                <span>from {{ number_format($count) }} ratings across both stores</span>
            </p>
        @endif
    </div>
    <div class="badges">
        <a href="{{ $identity['appStore'] }}" rel="noopener">
            <img src="/images/store_badges/appstore-tra-white.webp" alt="Download on the App Store" width="155" height="48" loading="lazy">
        </a>
        <a href="{{ $identity['playStore'] }}" rel="noopener">
            <img src="/images/store_badges/googleplay-tra-white.webp" alt="Get it on Google Play" width="162" height="48" loading="lazy">
        </a>
    </div>
</aside>
