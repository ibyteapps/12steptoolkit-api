{{--
    The QR landing page the store one-link points at. It makes the app offer
    itself, so it opts out of the shared call to action rather than making it
    twice.
--}}
@php($identity = config('site.identity'))
<x-site-layout
    title="Get the 12 Step Toolkit App | Android & iOS"
    description="Download 12 Step Toolkit free on iPhone, iPad, Android and the web. Scan the QR code or head straight to the App Store or Google Play."
    :crumbs="['Get the App' => null]"
    hide-cta
>
<x-slot:styles>
<style>
    .getapp { max-width: 44rem; margin: 0 auto; text-align: center; }
    .getapp .qr {
        width: 232px; height: 232px; padding: 16px; margin: 8px auto 30px;
        background: #fff; border: 1px solid var(--rule); border-radius: var(--r-lg);
        box-shadow: var(--shadow-md); display: block;
    }
    .getapp .badges { justify-content: center; margin-bottom: 22px; }
    .getapp .rating { justify-content: center; }
    .getapp .web { display: inline-block; margin-top: 26px; font-size: .9375rem; }
</style>
</x-slot:styles>

<div class="getapp">
    <h1>Get 12 Step Toolkit on your phone</h1>
    <p class="lede" style="margin-inline:auto">Scan this code with your camera, or go straight to your store.</p>

    <img class="qr" src="/images/onelinkto_kj5a35.png" alt="QR code linking to the 12 Step Toolkit app" width="232" height="232">

    <div class="badges">
        <a href="{{ $identity['appStore'] }}" rel="noopener">
    <img src="/images/store_badges/appstore.webp" alt="Download on the App Store" width="155" height="46">
        </a>
        <a href="{{ $identity['playStore'] }}" rel="noopener">
    <img src="/images/store_badges/googleplay.webp" alt="Get it on Google Play" width="162" height="46">
        </a>
    </div>

    <p class="rating">
        <span class="stars" aria-hidden="true">★★★★★</span>
        <b>{{ number_format(\App\Services\Site\Schema::combinedRating()['value'], 1) }}</b>
        <span>from {{ number_format(\App\Services\Site\Schema::combinedRating()['count']) }} ratings across both stores</span>
    </p>

    <a class="web" href="{{ $identity['webApp'] }}">Or use it in your browser &rarr;</a>
</div>
</x-site-layout>
