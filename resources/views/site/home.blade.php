{{--
    The home page.

    It takes the production site's identity — the navy-to-violet brand
    gradient, the angled device mockups, the statistics band, the feature grid,
    the FAQ — and rebuilds it: a fluid type scale instead of eleven fixed
    heading sizes, CSS grid instead of a Bootstrap row/col cascade, a real dark
    mode, `<details>` for the FAQ instead of a JavaScript accordion, and no
    carousels. Nothing that was on the old page has been dropped; the three
    blocks it never had are the blog teasers, the honest pricing section and a
    literature section that links into the 105 pages the site already serves.

    The store ratings are rendered here as well as marked up, which is not
    decoration: Google issues a structured-data manual action for an
    `aggregateRating` describing numbers a visitor cannot see.
--}}
<x-site-layout
    title="12 Step Toolkit — Free A.A. Sobriety App for Android & iOS"
    description="Free A.A. app with a sobriety calculator, the full Big Book, 12 Step worksheets, daily inventory reminders and 11,000+ online sponsors. Android, iOS and web."
    keywords="free aa app, sobriety app, sobriety calculator, big book app, 12 step app, aa sponsor app"
    :schema="$schema"
    bare
>
<x-slot:styles>
<style>
    .band { padding: clamp(56px, 7vw, 96px) 0; }
    .band-alt { background: var(--page-alt); }
    .band-wash { background: var(--wash); }
    .band-brand { background: var(--grad); color: #fff; position: relative; overflow: hidden; }
    .band-brand::after {
        content: ''; position: absolute; inset: 0;
        background: radial-gradient(70% 110% at 15% 0%, rgb(255 255 255 / .16), transparent 62%);
        pointer-events: none;
    }
    .band-brand > * { position: relative; }
    .band-brand h2, .band-brand h3 { color: #fff; }
    .band-brand p { color: rgb(255 255 255 / .82); }

    .section-head { max-width: 44rem; margin: 0 0 clamp(32px, 4vw, 52px); }
    .section-head p { color: var(--ink-soft); font-size: 1.125rem; margin: 0; }
    .band-brand .section-head p { color: rgb(255 255 255 / .8); }

    /* ---- Hero ---------------------------------------------------- */
    .hero {
        background: var(--wash); color: var(--ink);
        position: relative; overflow: hidden;
        padding: clamp(48px, 6vw, 84px) 0 clamp(56px, 7vw, 96px);
        border-bottom: 1px solid var(--rule-soft);
    }
    /* A soft bloom behind the phones rather than a dark overlay. */
    .hero::after {
        content: ''; position: absolute; inset: 0; pointer-events: none;
        background: radial-gradient(44% 70% at 78% 38%, rgb(41 128 185 / .16), transparent 68%);
    }
    .hero .wrap { position: relative; }
    .hero .cols {
        display: grid; gap: clamp(32px, 5vw, 64px);
        grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr); align-items: center;
    }
    .hero .eyebrow { color: var(--accent); }
    .hero h1 {
        font-size: clamp(2.125rem, 1.2rem + 3.4vw, 3.5rem);
        line-height: 1.06; letter-spacing: -.03em; margin: 0 0 20px; color: var(--ink); max-width: 15ch;
    }
    .hero .sub { font-size: clamp(1.0625rem, 1rem + .4vw, 1.25rem); line-height: 1.6; color: var(--ink-soft); max-width: 46ch; margin: 0 0 30px; }
    .hero .badges { margin: 0 0 20px; }
    .hero .web {
        display: inline-block; margin-top: 18px; color: var(--accent); font-size: .9375rem;
        font-weight: 500; text-decoration: none;
        border-bottom: 1px solid color-mix(in srgb, var(--accent) 40%, transparent); padding-bottom: 2px;
    }
    .hero .web:hover { border-color: var(--accent); }

    /* Two phones, the rear one angled behind — the production site's
       signature, drawn with transforms rather than baked into a JPEG. */
    .phones { position: relative; display: flex; justify-content: center; align-items: center; min-height: 420px; }
    .phones img {
        display: block; border-radius: 30px; background: #000;
        box-shadow: 0 30px 70px -22px rgb(20 34 56 / .38), 0 0 0 1px rgb(20 34 56 / .08);
    }
    .phones .back {
        position: absolute; width: 46%; max-width: 240px; right: 6%; top: 2%;
        transform: rotate(7deg); opacity: .96;
    }
    .phones .front { position: relative; width: 52%; max-width: 268px; transform: rotate(-4deg); }
    @media (max-width: 880px) {
        .hero .cols { grid-template-columns: 1fr; }
        .phones { min-height: 0; margin-top: 8px; }
        .phones .back { display: none; }
        .phones .front { width: 62%; max-width: 230px; transform: none; }
    }

    /* ---- Statistics ---------------------------------------------- */
    .stats { display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
    .stat {
        background: var(--card); border: 1px solid var(--rule); border-radius: var(--r-lg);
        padding: 28px 26px; box-shadow: var(--shadow-sm);
    }
    .stat .n {
        display: block; font-size: clamp(2rem, 1.4rem + 2.2vw, 2.875rem); font-weight: 800;
        letter-spacing: -.03em; line-height: 1; margin-bottom: 10px;
        background: linear-gradient(120deg, var(--brand-blue), var(--brand-violet));
        -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .stat .k { font-weight: 600; display: block; margin-bottom: 3px; }
    .stat .s { color: var(--ink-faint); font-size: .875rem; }

    /* ---- Feature grid -------------------------------------------- */
    .features { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(16.5rem, 1fr)); }
    .feature {
        background: var(--card); border: 1px solid var(--rule); border-radius: var(--r-lg);
        padding: 26px; box-shadow: var(--shadow-sm);
        transition: transform .2s var(--ease), box-shadow .2s var(--ease);
    }
    .feature:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .feature .ico {
        width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center;
        background: var(--accent-soft); color: var(--brand-blue); margin-bottom: 16px;
    }
    .feature .ico svg { width: 21px; height: 21px; }
    .feature h3 { font-size: 1.0625rem; margin: 0 0 7px; }
    .feature p { margin: 0; color: var(--ink-soft); font-size: .9375rem; line-height: 1.6; }

    /* ---- Alternating detail rows --------------------------------- */
    .row {
        display: grid; gap: clamp(32px, 5vw, 64px); align-items: center;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
    .row + .row { margin-top: clamp(56px, 7vw, 96px); }
    .row .shot { display: flex; justify-content: center; }
    .row .shot img {
        width: 100%; max-width: 262px; border-radius: 28px; background: #000;
        box-shadow: var(--shadow-lg), 0 0 0 1px rgb(0 0 0 / .06);
        transform: rotate(-3deg);
    }
    .row .shot.card img {
        max-width: 420px; border-radius: var(--r-lg); background: var(--card);
        transform: none; box-shadow: var(--shadow-lg);
    }
    .row.flip .shot { order: -1; }
    .row.flip .shot img { transform: rotate(3deg); }
    .row ul { margin: 18px 0 0; padding-left: 1.2em; color: var(--ink-soft); }
    .row li { margin-bottom: .55em; }
    .row .more { display: inline-block; margin-top: 22px; font-weight: 600; text-decoration: none; }
    .row .more:hover { text-decoration: underline; }
    @media (max-width: 820px) {
        .row { grid-template-columns: 1fr; }
        .row.flip .shot { order: 0; }
        .row .shot img { max-width: 210px; }
    }

    /* ---- Screens strip ------------------------------------------- */
    /* The strip carries itself past, and stops when someone wants to look.
       The second pass of the eleven screens is what makes the wrap seamless:
       the track travels exactly one set and the loop is invisible. */
    .screens {
        overflow: hidden; padding: 6px 0 18px;
        -webkit-mask-image: linear-gradient(90deg, transparent, #000 5%, #000 95%, transparent);
        mask-image: linear-gradient(90deg, transparent, #000 5%, #000 95%, transparent);
    }
    .screens-track {
        display: flex; gap: 18px; width: max-content;
        animation: screens-marquee 70s linear infinite;
    }
    .screens:hover .screens-track,
    .screens:focus-within .screens-track { animation-play-state: paused; }
    .screens img {
        flex: 0 0 auto; width: 190px; border-radius: 22px; background: #000;
        box-shadow: 0 18px 38px -16px rgb(20 34 56 / .35), 0 0 0 1px rgb(20 34 56 / .08);
    }
    @keyframes screens-marquee {
        from { transform: translateX(0); }
        to   { transform: translateX(calc(-50% - 9px)); }
    }
    /* Reduced motion gets the strip it had before — still every screen, just
       scrolled by hand. The global reduce rule would otherwise collapse the
       animation to nothing and snap it to the end. */
    @media (prefers-reduced-motion: reduce) {
        .screens { overflow-x: auto; scroll-snap-type: x mandatory; }
        .screens-track { animation: none; width: auto; }
        .screens img { scroll-snap-align: start; }
    }

    /* ---- Pricing ------------------------------------------------- */
    .price { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); }
    .price > div {
        background: var(--card); border: 1px solid var(--rule); border-radius: var(--r-lg); padding: 28px;
    }
    .price h3 { margin: 0 0 10px; font-size: 1.125rem; }
    .price p { margin: 0; color: var(--ink-soft); }

    /* ---- FAQ ----------------------------------------------------- */
    .faq { max-width: 50rem; }
    .faq details { border-bottom: 1px solid var(--rule); }
    .faq summary {
        cursor: pointer; list-style: none; padding: 20px 36px 20px 0; position: relative;
        font-weight: 600; font-size: 1.0625rem;
    }
    .faq summary::-webkit-details-marker { display: none; }
    .faq summary::after {
        content: ''; position: absolute; right: 6px; top: 50%; width: 9px; height: 9px;
        border-right: 2px solid var(--ink-faint); border-bottom: 2px solid var(--ink-faint);
        transform: translateY(-70%) rotate(45deg); transition: transform .2s var(--ease);
    }
    .faq details[open] summary::after { transform: translateY(-30%) rotate(-135deg); }
    .faq summary:hover { color: var(--accent); }
    .faq .a { padding: 0 0 22px; color: var(--ink-soft); max-width: 46rem; }
    .faq .a p { margin: 0 0 .9em; }
    .faq .a p:last-child { margin-bottom: 0; }

    /* ---- Blog teasers -------------------------------------------- */
    .posts { display: grid; gap: 24px; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); }
    .post {
        background: var(--card); border: 1px solid var(--rule); border-radius: var(--r-lg);
        overflow: hidden; text-decoration: none; color: var(--ink); display: flex; flex-direction: column;
        box-shadow: var(--shadow-sm); transition: transform .2s var(--ease), box-shadow .2s var(--ease);
    }
    .post:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .post img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; display: block; }
    .post .body { padding: 20px 22px 24px; }
    .post .cat { font-size: .6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: var(--accent); }
    .post h3 { font-size: 1.0625rem; margin: 9px 0 8px; line-height: 1.35; }
    .post p { margin: 0; color: var(--ink-soft); font-size: .9375rem; line-height: 1.55; }

    /* ---- Closing CTA --------------------------------------------- */
    .close-cta { text-align: center; }
    .close-cta h2 { max-width: 20ch; margin-inline: auto; }
    .close-cta p { margin: 0 auto 28px; max-width: 48ch; }
    .close-cta .badges { justify-content: center; }
    .close-cta .rating { justify-content: center; margin-top: 20px; }

    .rating .store { color: inherit; opacity: .78; }
    .rating .store::before { content: '·'; margin-right: 9px; opacity: .6; }
</style>
</x-slot:styles>

{{-- ---- Hero ------------------------------------------------------ --}}
<section class="hero">
    <div class="wrap">
        <div class="cols">
            <div>
                <p class="eyebrow">Free &middot; made by A.A. members</p>
                <h1>A free A.A. app for counting days and working the Steps.</h1>
                <p class="sub">
                    Count your sobriety to the second. Work each of the Twelve Steps with
                    a sponsor who sees your inventories as you write them. Read the whole
                    Big Book, the prayers and 69 personal stories — anonymously, on any device.
                </p>

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
                    <b>{{ number_format($rating['value'], 1) }}</b>
                    <span>from {{ number_format($rating['count']) }} ratings</span>
                    @foreach($rating['stores'] as $store)
                        <span class="store">{{ number_format($store['value'], 1) }} {{ $store['name'] }}</span>
                    @endforeach
                </p>

                <a class="web" href="{{ $identity['webApp'] }}">Or use it in your browser &rarr;</a>
            </div>

            <div class="phones">
                <img class="back" src="/images/screens/Screen11@2x.webp" alt="" width="240" height="520" loading="eager">
                <img class="front" src="/images/screens/Screen1@2x.webp" alt="The Steps screen in 12 Step Toolkit" width="268" height="580" loading="eager">
            </div>
        </div>
    </div>
</section>

{{-- ---- Statistics ------------------------------------------------ --}}
<section class="band band-alt">
    <div class="wrap">
        <div class="stats">
            <div class="stat">
                <span class="n">500K</span>
                <span class="k">Downloads</span>
                <span class="s">Since 2015</span>
            </div>
            <div class="stat">
                <span class="n">{{ number_format($rating['count']) }}</span>
                <span class="k">Ratings across both stores</span>
                <span class="s">Averaging {{ number_format($rating['value'], 1) }} out of 5</span>
            </div>
            <div class="stat">
                <span class="n">11,000</span>
                <span class="k">Sponsors inside the app</span>
                <span class="s">Members with sober time, worldwide</span>
            </div>
        </div>
    </div>
</section>

{{-- ---- Feature grid ---------------------------------------------- --}}
<section class="band">
    <div class="wrap">
        <div class="section-head">
            <h2>What is in it</h2>
            <p>Everything needed to work the programme, and nothing that gets in the way of it.</p>
        </div>

        <div class="features">
            <div class="feature">
                <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                <h3>A sobriety counter</h3>
                <p>The exact date and time your sobriety began, counted to the second — with a token for each milestone and statistics to look back over.</p>
            </div>
            <div class="feature">
                <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h16M4 19h10"/></svg></div>
                <h3>The Twelve Steps</h3>
                <p>Guided Step work with inventories, amends lists, notes and gratitude lists, kept in order from Step One to Step Twelve.</p>
            </div>
            <div class="feature">
                <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                <h3>Sponsorship, in private</h3>
                <p>Your sponsor sees your work as you write it, and you can talk to them in the app. Once you have time, you can sponsor somebody yourself.</p>
            </div>
            <div class="feature">
                <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5V5a2 2 0 0 1 2-2h13v18H6.5A2.5 2.5 0 0 0 4 19.5z"/><path d="M8 7h7M8 11h7"/></svg></div>
                <h3>The whole Big Book</h3>
                <p>All 164 pages plus the Doctor's Opinion, 69 personal stories from both editions, the prayers and the readings.</p>
            </div>
            <div class="feature">
                <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4.5 8-11V5l-8-3-8 3v6c0 6.5 8 11 8 11z"/></svg></div>
                <h3>Anonymous by default</h3>
                <p>No real name needed. Your journals, inventories and sober date are yours, and nobody sees them unless you share them with a sponsor.</p>
            </div>
            <div class="feature">
                <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg></div>
                <h3>On every device</h3>
                <p>Android, iPhone, iPad, Mac and the web, with your work in step across all of them.</p>
            </div>
        </div>
    </div>
</section>

{{-- ---- Alternating detail rows ----------------------------------- --}}
<section class="band band-alt">
    <div class="wrap">
        <div class="row">
            <div>
                <p class="eyebrow">Count the days</p>
                <h2>A sobriety calculator, to the second</h2>
                <p>Record the exact date and time your sobriety began and watch it count — days, hours, minutes and seconds — with a token for every milestone as it comes round.</p>
                <ul>
                    <li>Earn a token to mark each milestone</li>
                    <li>Second-by-second precision, not a day counter</li>
                    <li>Detailed statistics to monitor your progress</li>
                </ul>
            </div>
            <div class="shot card">
                <img src="/images/image-02.webp" alt="The sobriety counter: 12 years, 3 months, 7 days, 11 hours, 5 minutes and 34 seconds" width="465" height="286" loading="lazy">
            </div>
        </div>

        <div class="row flip">
            <div>
                <p class="eyebrow">Work the Twelve Steps</p>
                <h2>Inventories, notes and gratitude lists</h2>
                <p>Step Four and Step Ten inventories, personalised reminders for your morning and night check-ins, journals for the things worth writing down, and a gratitude list you can keep adding to.</p>
                <ul>
                    <li>Step 4, Step 8 and Step 10 worksheets</li>
                    <li>Morning and nightly inventory reminders</li>
                    <li>Journals, notes and an amends list</li>
                </ul>
            </div>
            <div class="shot">
                <img src="/images/screens/Screen2@2x.webp" alt="A Step Four resentment inventory in 12 Step Toolkit" width="262" height="568" loading="lazy">
            </div>
        </div>

        <div class="row">
            <div>
                <p class="eyebrow">Includes all A.A. literature</p>
                <h2>The entire Big Book in your pocket</h2>
                <p>The full text of Alcoholics Anonymous, the prayers, the readings and the personal stories from both editions — in the app, and on this site.</p>
                <ul>
                    <li>All 164 pages and the Doctor's Opinion</li>
                    <li>Serenity, St Francis, Third Step and Seventh Step prayers</li>
                    <li>How It Works, the Twelve Traditions and Just For Today</li>
                    <li>69 personal stories from the back of the book</li>
                </ul>
                <a class="more" href="/aa-literature">Read all of it here on the site &rarr;</a>
            </div>
            <div class="shot">
                <img src="/images/screens/Screen4@2x.webp" alt="Chapter 5, How It Works, in 12 Step Toolkit" width="262" height="568" loading="lazy">
            </div>
        </div>
    </div>
</section>

{{-- ---- Screens strip --------------------------------------------- --}}
<section class="band band-wash">
    <div class="wrap">
        <div class="section-head">
            <h2>What it looks like</h2>
            <p>Eleven screens from the app.</p>
        </div>
    </div>
    {{-- Outside the wrap: the strip runs the full width and fades at both
         ends, so the phones arrive and leave rather than being cut off at
         the content column. --}}
    <div class="screens">
            <div class="screens-track">
                @foreach([false, true] as $duplicate)
                    @for($i = 1; $i <= 11; $i++)
                        <img src="/images/screens/Screen{{ $i }}@2x.webp"
                             @if($duplicate) alt="" aria-hidden="true" @else alt="12 Step Toolkit screen {{ $i }}" @endif
                             width="190" height="412" loading="lazy">
                    @endfor
                @endforeach
        </div>
    </div>
</section>

{{-- ---- Pricing ---------------------------------------------------- --}}
<section class="band">
    <div class="wrap">
        <div class="section-head">
            <h2>What it costs</h2>
            <p>The honest version.</p>
        </div>
        <div class="price">
            <div>
                <h3>Free to download, free to use</h3>
                <p>It is ad-supported, which is what keeps it going. Counting your days, working all Twelve Steps, chatting with a sponsor and reading the 164 pages are all in the free version.</p>
            </div>
            <div>
                <h3>Subscriptions, for those who can</h3>
                <p>There are quarterly, annual and lifetime subscriptions for members who want to support the app, and they remove the ads. Nobody is kept from the Steps by not buying one.</p>
            </div>
        </div>
    </div>
</section>

{{-- ---- FAQ -------------------------------------------------------- --}}
<section class="band band-alt">
    <div class="wrap">
        <div class="section-head">
            <h2>Questions people ask</h2>
            <p>And the answers, from the members who built it.</p>
        </div>
        <div class="faq">
            @foreach($faqs as $faq)
                <details @if($loop->first) open @endif>
                    <summary>{{ $faq['question'] }}</summary>
                    <div class="a">
                        @foreach((array) $faq['answer'] as $para)
                            <p>{{ $para }}</p>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ---- From the blog ---------------------------------------------- --}}
@if($latest->isNotEmpty())
<section class="band">
    <div class="wrap">
        <div class="section-head">
            <h2>From the blog</h2>
            <p>Plain answers about sponsorship, the Steps and early sobriety.</p>
        </div>
        <div class="posts">
            @foreach($latest as $post)
                <a class="post" href="{{ $post['path'] }}">
                    @if($post['cardImage'])
                        <img src="{{ $post['cardImage'] }}" alt="" width="400" height="225" loading="lazy">
                    @endif
                    <div class="body">
                        <span class="cat">{{ $post['category'] }}</span>
                        <h3>{{ $post['title'] }}</h3>
                        <p>{{ \Illuminate\Support\Str::limit($post['description'], 110) }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ---- Closing CTA ------------------------------------------------ --}}
<section class="band band-brand close-cta">
    <div class="wrap">
        <h2>Day one can be today</h2>
        <p>Free on Android and iOS, and in your browser. No real name needed.</p>
        <div class="badges">
            <a href="{{ $identity['appStore'] }}" rel="noopener">
                <img src="/images/store_badges/appstore-tra-white.webp" alt="Download on the App Store" width="155" height="48" loading="lazy">
            </a>
            <a href="{{ $identity['playStore'] }}" rel="noopener">
                <img src="/images/store_badges/googleplay-tra-white.webp" alt="Get it on Google Play" width="162" height="48" loading="lazy">
            </a>
        </div>
        <p class="rating">
            <span class="stars" aria-hidden="true">★★★★★</span>
            <b>{{ number_format($rating['value'], 1) }}</b>
            <span>from {{ number_format($rating['count']) }} ratings</span>
            @foreach($rating['stores'] as $store)
                <span class="store">{{ number_format($store['value'], 1) }} {{ $store['name'] }}</span>
            @endforeach
        </p>
    </div>
</section>
</x-site-layout>
