{{--
    The home page.

    Seven sections where the static site had twelve, and no carousel: a reader
    who has just decided to stop drinking is not browsing, and the register of
    "Rise & Thrive: Claim Your Free-for-Life Toolkit and Kickstart Your New
    Journey Today!" is wrong for them whatever it does to a conversion rate.
    The facts are the same facts.

    The store ratings are rendered here as well as marked up, which is not
    decoration: Google issues a structured-data manual action for an
    `aggregateRating` describing numbers a visitor cannot see.
--}}
<x-site-layout
    title="12 Step Toolkit — Free A.A. Sobriety App for Android & iOS"
    description="Free A.A. app with a sobriety calculator, the full Big Book, 12 Step worksheets, daily inventory reminders and 11,000+ online sponsors. Android, iOS and web."
    keywords="free aa app, sobriety app, sobriety calculator, big book app, 12 step app, aa sponsor app"
    :schema="$schema"
>
<x-slot:styles>
<style>
    /* The reading pages want a narrow measure; this one wants the width. */
    .home { --home-max: 70rem; }
    .home .wrap-wide { max-width: var(--home-max); margin: 0 auto; padding: 0 16px; }

    .hero { padding: 64px 0 0; }
    .hero .cols { display: grid; gap: 48px; grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr); align-items: center; }
    @media (max-width: 860px) { .hero { padding-top: 40px; } .hero .cols { grid-template-columns: 1fr; gap: 32px; } .hero .shot { display: none; } }

    .eyebrow {
        font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.08em;
        color: var(--ink-faint); margin: 0 0 16px;
    }
    .hero h1 {
        font-size: clamp(2rem, 4.2vw, 3.25rem); line-height: 1.08;
        letter-spacing: -0.03em; margin: 0 0 20px; max-width: 18ch;
    }
    .hero .sub { font-size: 1.125rem; line-height: 1.6; color: var(--ink-soft); max-width: 44ch; margin: 0 0 32px; }

    .hero .shot img {
        width: 100%; max-width: 300px; height: auto; display: block; margin-left: auto;
        border-radius: 22px; border: 1px solid var(--rule);
        box-shadow: 0 24px 60px -28px rgba(0,0,0,0.35);
    }

    .badges { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 0 0 20px; }
    .badges img { height: 48px; width: auto; display: block; }
    .badges .web {
        font-size: 0.9375rem; color: var(--ink-soft); text-decoration: none;
        border-bottom: 1px solid var(--rule); padding-bottom: 1px;
    }
    .badges .web:hover { color: var(--link); border-color: var(--link); }

    .rated { font-size: 0.875rem; color: var(--ink-faint); margin: 0; line-height: 1.6; }
    .rated strong { color: var(--ink); font-weight: 600; }
    .rated .store::before { content: '·'; margin: 0 7px; opacity: 0.6; }
    .stars { color: #d99100; letter-spacing: 0.05em; }

    /* Stat tiles: no plot, so no hover layer. Values in the page sans with
       proportional figures — tabular-nums would make 11,000 look loose here. */
    .stats {
        display: grid; gap: 1px; grid-template-columns: repeat(3, 1fr);
        background: var(--rule); border: 1px solid var(--rule); border-radius: 14px;
        overflow: hidden; margin: 56px 0 0;
    }
    @media (max-width: 620px) { .stats { grid-template-columns: 1fr; } }
    .stats > div { background: var(--card); padding: 24px 22px; }
    .stats .value { font-size: 2rem; font-weight: 600; letter-spacing: -0.035em; line-height: 1; }
    .stats .label { font-size: 0.9375rem; color: var(--ink); margin-top: 8px; }
    .stats .note { font-size: 0.8125rem; color: var(--ink-faint); margin-top: 3px; }

    section.band { padding: 72px 0 0; }
    section.band + section.band { border-top: 1px solid var(--rule); margin-top: 72px; }
    section.band > h2 { font-size: 1.625rem; letter-spacing: -0.025em; margin: 0 0 10px; }
    section.band > .intro { color: var(--ink-soft); max-width: 52ch; margin: 0 0 36px; font-size: 1.0625rem; }

    .features { display: grid; gap: 20px; grid-template-columns: repeat(2, 1fr); }
    @media (max-width: 760px) { .features { grid-template-columns: 1fr; } }
    .features article {
        border: 1px solid var(--rule); border-radius: 14px; background: var(--card); padding: 26px;
    }
    .features h3 { font-size: 1.125rem; margin: 0 0 14px; letter-spacing: -0.015em; }
    .features ul { margin: 0; padding-left: 1.15em; color: var(--ink-soft); font-size: 0.9375rem; }
    .features li { margin-bottom: 8px; }
    .features li:last-child { margin-bottom: 0; }
    .features p { margin: 0; color: var(--ink-soft); font-size: 0.9375rem; }
    .features .more { margin-top: 14px; font-size: 0.9375rem; }

    /* A scroll strip, not a carousel: no JavaScript, and the fade at the right
       edge is the only thing needed to say there is more. */
    .screens-rail { position: relative; }
    .screens { display: flex; gap: 16px; overflow-x: auto; padding: 4px 0 20px; scroll-snap-type: x proximity; }
    .screens img {
        height: 460px; width: auto; border-radius: 18px; border: 1px solid var(--rule);
        scroll-snap-align: start; flex: 0 0 auto; background: var(--card);
    }
    .screens-rail::after {
        content: ''; position: absolute; top: 0; right: 0; bottom: 20px; width: 72px;
        pointer-events: none; background: linear-gradient(to right, transparent, var(--page));
    }
    @media (max-width: 640px) { .screens img { height: 360px; } }

    .cost { display: grid; gap: 28px; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; }
    @media (max-width: 760px) { .cost { grid-template-columns: 1fr; gap: 18px; } }
    .cost p { margin: 0 0 14px; color: var(--ink-soft); font-size: 1.0625rem; }
    .cost p:last-child { margin-bottom: 0; }
    .cost strong { color: var(--ink); }

    .faqs { max-width: 52rem; }
    details.faq { border-bottom: 1px solid var(--rule); }
    details.faq:first-of-type { border-top: 1px solid var(--rule); }
    details.faq summary {
        cursor: pointer; padding: 18px 2px; font-weight: 600; font-size: 1.0625rem;
        list-style: none; display: flex; gap: 14px; align-items: baseline;
    }
    details.faq summary::-webkit-details-marker { display: none; }
    details.faq summary::before { content: '+'; color: var(--link); font-weight: 400; font-size: 1.125rem; }
    details.faq[open] summary::before { content: '\2013'; }
    details.faq .answer { padding: 0 2px 20px 30px; max-width: 64ch; }
    details.faq .answer p { margin: 0 0 0.9em; color: var(--ink-soft); }
    details.faq .answer p:last-child { margin-bottom: 0; }

    .closing { padding-bottom: 8px; }
    .closing h2 { font-size: 1.625rem; margin: 0 0 10px; letter-spacing: -0.025em; }
    .closing p.line { color: var(--ink-soft); max-width: 44ch; margin: 0 0 28px; font-size: 1.0625rem; }
</style>
</x-slot:styles>

<div class="home">
<div class="wrap-wide">

<section class="hero">
    <div class="cols">
        <div>
            <p class="eyebrow">Free · Made by A.A. members</p>
            <h1>A free A.A. app for counting days and working the Steps.</h1>
            <p class="sub">Count your sobriety to the second. Work each of the Twelve
               Steps with a sponsor who sees your inventories as you write them. Read
               the whole Big Book, the prayers and 69 personal stories — anonymously,
               on any device.</p>

            <p class="badges">
                <a href="{{ $identity['appStore'] }}" aria-label="Download on the App Store">
                    <img src="/images/store_badges/appstore.webp" alt="Download on the App Store" height="48" width="162" loading="eager">
                </a>
                <a href="{{ $identity['playStore'] }}" aria-label="Get it on Google Play">
                    <img src="/images/store_badges/googleplay.webp" alt="Get it on Google Play" height="48" width="162" loading="eager">
                </a>
                <a class="web" href="{{ $identity['webApp'] }}">or use it in your browser</a>
            </p>

            {{-- Rendered because it is marked up. See the controller. --}}
            <p class="rated">
                <span class="stars" aria-hidden="true">★★★★★</span>
                <strong>{{ $rating['value'] }}</strong> from {{ number_format($rating['count']) }} ratings
                @foreach($rating['stores'] as $store)
                    <span class="store">{{ number_format($store['value'], 1) }} {{ $store['name'] }}</span>
                @endforeach
            </p>
        </div>

        <div class="shot">
            <img src="/images/screens/Screen1.webp"
                 srcset="/images/screens/Screen1.webp 1x, /images/screens/Screen1@2x.webp 2x"
                 alt="The Twelve Steps, listed in the 12 Step Toolkit app" width="300" height="650" loading="eager">
        </div>
    </div>

    <div class="stats">
        <div>
            <div class="value">500K</div>
            <div class="label">Downloads</div>
            <div class="note">Since 2015</div>
        </div>
        <div>
            <div class="value">{{ number_format($rating['count']) }}</div>
            <div class="label">Ratings across both stores</div>
            <div class="note">As of {{ \Illuminate\Support\Carbon::parse($rating['asOf'])->format('F Y') }}</div>
        </div>
        <div>
            <div class="value">11,000</div>
            <div class="label">Sponsors inside the app</div>
            <div class="note">Members with sober time, worldwide</div>
        </div>
    </div>
</section>

<section class="band">
    <h2>What is in it</h2>
    <p class="intro">Everything needed to work the programme, and nothing that gets
       in the way of it.</p>

    <div class="features">
        <article>
            <h3>A sobriety counter</h3>
            <ul>
                <li>The exact date and time your sobriety began</li>
                <li>Counted to the second, not the day</li>
                <li>Tokens as each milestone comes round</li>
                <li>Statistics you can look back over</li>
            </ul>
        </article>
        <article>
            <h3>The Twelve Steps, with a sponsor</h3>
            <ul>
                <li>Inventories, amends lists, notes and gratitude lists</li>
                <li>Your sponsor sees your work as you write it</li>
                <li>Morning and nightly inventory reminders</li>
                <li>Anonymous chat with your sponsor</li>
            </ul>
        </article>
        <article>
            <h3>The whole Big Book</h3>
            <ul>
                <li>All 164 pages, plus the Doctor's Opinion</li>
                <li>69 personal stories from both editions</li>
                <li>The prayers — Serenity, St Francis, Third Step, Seventh Step</li>
                <li>The readings — How It Works, the Twelve Traditions, Just For Today</li>
            </ul>
            <p class="more"><a href="/aa-literature">Read all of it here on the site →</a></p>
        </article>
        <article>
            <h3>On every device you use</h3>
            <p>Android, iPhone, iPad, Mac and the web, with your work in step across
               all of them. And once you have the sober time and the confidence, you
               can sponsor somebody yourself.</p>
            <p class="more"><a href="/blog">Reading on sponsorship and the Steps →</a></p>
        </article>
    </div>
</section>

<section class="band">
    <h2>What it looks like</h2>
    <p class="intro">Eleven screens from the app.</p>
    <div class="screens-rail">
        <div class="screens">
            @for($i = 1; $i <= 11; $i++)
                <img src="/images/screens/Screen{{ $i }}.webp"
                     srcset="/images/screens/Screen{{ $i }}.webp 1x, /images/screens/Screen{{ $i }}@2x.webp 2x"
                     alt="12 Step Toolkit, screen {{ $i }} of 11" height="460" width="212" loading="lazy">
            @endfor
        </div>
    </div>
</section>

<section class="band">
    <h2>What it costs</h2>
    <div class="cost">
        <div>
            <p><strong>Free to download, and free to use.</strong> The app is
               ad-supported, which is what keeps it going. Everything needed to count
               your days, work all Twelve Steps, chat with a sponsor and read the 164
               pages is in the free version.</p>
        </div>
        <div>
            <p>There are quarterly, annual and lifetime subscriptions for members who
               can support it, and they remove the ads. Nobody is kept from the Steps
               by not buying one.</p>
        </div>
    </div>
</section>

<section class="band">
    <h2>Questions people ask</h2>
    <p class="intro">And the answers, from the members who built it.</p>

    <div class="faqs">
        @foreach($faqs as $faq)
            <details class="faq" @if($loop->first) open @endif>
                <summary>{{ $faq['question'] }}</summary>
                <div class="answer">
                    @foreach((array) $faq['answer'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
</section>

<section class="band closing">
    <h2>One day at a time</h2>
    <p class="line">Download it and set your sobriety date. That is the whole of the
       first step on here.</p>
    <p class="badges">
        <a href="{{ $identity['appStore'] }}" aria-label="Download on the App Store">
            <img src="/images/store_badges/appstore.webp" alt="Download on the App Store" height="48" width="162" loading="lazy">
        </a>
        <a href="{{ $identity['playStore'] }}" aria-label="Get it on Google Play">
            <img src="/images/store_badges/googleplay.webp" alt="Get it on Google Play" height="48" width="162" loading="lazy">
        </a>
        <a class="web" href="{{ $identity['webApp'] }}">or use it in your browser</a>
    </p>
</section>

</div>
</div>
</x-site-layout>
