{{--
    The public website's layout.

    Self-contained on purpose: no build step, no Node, no external stylesheet
    and no webfont. The CSS is here, in one place, which is also what makes a
    restyle a change to one file rather than to 169 pages.

    The design tokens are the ones the production site shipped — the navy →
    blue → violet brand gradient, the #16a2e0 accent, the gold star — carried
    over rather than reinvented, so the site still looks like itself. What has
    changed is the execution: a fluid type scale, a real dark mode, layered
    shadows, a sticky translucent header and a store call-to-action that every
    page gets whether or not its author remembered it.

    The `<head>` is the part that must not drift. Every title, description and
    keyword list comes from `resources/site/literature.php`, which was
    converted from what the Next.js site served rather than retyped, so the
    pages keep exactly the metadata Google already has for them.
--}}
@php
    // One list, rendered twice: as the bar on wide screens and inside the
    // menu on narrow ones. Two markup blocks, never two sets of links.
    $nav = [
        ['label' => 'A.A. Literature', 'href' => '/aa-literature'],
        ['label' => 'Blog', 'href' => '/blog'],
        ['label' => 'Contact', 'href' => '/contacts'],
        ['label' => 'Web login', 'href' => config('site.identity.webApp')],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
    @isset($description)<meta name="description" content="{{ $description }}">@endisset
    @isset($keywords)<meta name="keywords" content="{{ $keywords }}">@endisset
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:title" content="{{ $title }}">
    @isset($description)<meta property="og:description" content="{{ $description }}">@endisset
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="12 Step Toolkit">
    <meta property="og:image" content="{{ url($image ?? '/images/screens/Screen1@2x.webp') }}">
    <meta name="twitter:card" content="summary_large_image">

    <meta name="theme-color" content="#eef4fb">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.png" type="image/png">

    {{-- One connected @graph per page: the organisation and the site, the
         breadcrumb trail as rendered, and whatever this page is. Assembled by
         the layout component so a page cannot ship without it. --}}
    @if($schemaJson = $schemaJson())
        <script type="application/ld+json">{!! $schemaJson !!}</script>
    @endif

    <style>
        /* ---- Tokens -------------------------------------------------- */
        :root {
            color-scheme: light;

            /* Brand, carried over from the production stylesheet. */
            --brand-deep: #2c3e50;
            --brand-blue: #2980b9;
            --brand-violet: #b06ab3;
            --accent: #1583c4;
            --accent-soft: #e8f3fb;
            --star: #e8a317;

            /* The loud one — used for the call to action, the brand tile and
               the statistics figures, and nowhere else. */
            --grad: linear-gradient(135deg, #2980b9 0%, #5f73c4 52%, #ab67b2 100%);
            /* The quiet one — the hero and the tinted bands. Recovery is not
               a nightclub: the reference set (Headspace, Calm, Hims) runs
               light with dark ink and saves saturation for one block. */
            --wash: linear-gradient(145deg, #eef4fb 0%, #f3f1fa 52%, #faf3f7 100%);
            --tint: #f1f5fa;

            --ink: #141a21;
            --ink-soft: #50606f;
            --ink-faint: #7b8794;
            --ink-on-brand: #ffffff;

            --page: #ffffff;
            --page-alt: #f5f8fb;
            --card: #ffffff;
            --rule: #e4e9ef;
            --rule-soft: #eef2f6;

            --shadow-sm: 0 1px 2px rgb(16 28 42 / .06), 0 1px 1px rgb(16 28 42 / .04);
            --shadow-md: 0 4px 10px -2px rgb(16 28 42 / .08), 0 12px 28px -8px rgb(16 28 42 / .10);
            --shadow-lg: 0 8px 20px -6px rgb(16 28 42 / .12), 0 30px 60px -20px rgb(16 28 42 / .20);

            --r-sm: 8px;
            --r-md: 14px;
            --r-lg: 20px;
            --r-pill: 999px;

            --measure: 44rem;
            --shell: 72rem;

            --ease: cubic-bezier(.4, 0, .2, 1);
        }

        /* ---- Base ---------------------------------------------------- */
        *, *::before, *::after { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }

        body {
            margin: 0;
            background: var(--page);
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 1.0625rem;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }

        a { color: var(--accent); text-underline-offset: 3px; }

        img { max-width: 100%; height: auto; }

        h1, h2, h3, h4 { line-height: 1.15; letter-spacing: -0.021em; margin: 0 0 .5em; font-weight: 700; }
        h1 { font-size: clamp(2rem, 1.4rem + 2.4vw, 3rem); }
        h2 { font-size: clamp(1.5rem, 1.2rem + 1.3vw, 2.125rem); }
        h3 { font-size: clamp(1.1875rem, 1.1rem + .5vw, 1.4375rem); }

        :where(a, button, summary, [tabindex]):focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
            border-radius: 4px;
        }

        .shell { max-width: var(--shell); margin: 0 auto; padding: 0 20px; }
        .wrap  { max-width: var(--shell); margin: 0 auto; padding: 0 20px; }

        .skip {
            position: absolute; left: -9999px; top: 0; z-index: 100;
            background: var(--card); color: var(--ink); padding: 10px 16px;
            border-radius: 0 0 var(--r-sm) 0;
        }
        .skip:focus { left: 0; }

        /* ---- Header -------------------------------------------------- */
        header.site {
            position: sticky; top: 0; z-index: 50;
            background: color-mix(in srgb, var(--page) 86%, transparent);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--rule-soft);
        }
        header.site .wrap { display: flex; align-items: center; gap: 24px; min-height: 66px; }

        .brand { display: inline-flex; align-items: center; gap: 11px; text-decoration: none; color: var(--ink); }
        .brand .mark {
            width: 34px; height: 34px; border-radius: 10px; flex: 0 0 auto;
            background: var(--grad); display: grid; place-items: center;
            box-shadow: var(--shadow-sm);
        }
        .brand .mark img { width: 21px; height: 21px; display: block; }
        .brand b { font-size: 1.0625rem; font-weight: 700; letter-spacing: -0.02em; white-space: nowrap; }

        header.site nav.bar { margin-left: auto; display: flex; align-items: center; gap: 4px; }
        header.site nav.bar a {
            color: var(--ink-soft); text-decoration: none; font-size: .9375rem; font-weight: 500;
            padding: 8px 12px; border-radius: var(--r-sm); white-space: nowrap;
            transition: color .2s var(--ease), background .2s var(--ease);
        }
        header.site nav.bar a:hover { color: var(--ink); background: var(--page-alt); }

        header.site .cta {
            background: var(--brand-blue); color: #fff; text-decoration: none;
            font-size: .9375rem; font-weight: 600; white-space: nowrap;
            border-radius: var(--r-pill); padding: 9px 18px; margin-left: 10px;
            box-shadow: var(--shadow-sm); transition: background .2s var(--ease);
        }
        header.site .cta:hover { background: #2470a3; }

        /* ---- The menu ------------------------------------------------ */
        .menu { display: none; position: relative; }
        .menu summary {
            list-style: none; cursor: pointer; width: 42px; height: 42px;
            display: grid; place-items: center; border-radius: var(--r-sm);
            border: 1px solid var(--rule); background: var(--card);
        }
        .menu summary::-webkit-details-marker { display: none; }
        .menu summary:hover { background: var(--page-alt); }
        .bars, .bars::before, .bars::after {
            display: block; width: 18px; height: 2px; border-radius: 2px;
            background: var(--ink); transition: transform .2s var(--ease), opacity .15s var(--ease);
        }
        .bars { position: relative; }
        .bars::before, .bars::after { content: ''; position: absolute; left: 0; }
        .bars::before { top: -6px; }
        .bars::after  { top: 6px; }
        .menu[open] .bars { background: transparent; }
        .menu[open] .bars::before { transform: translateY(6px) rotate(45deg); }
        .menu[open] .bars::after  { transform: translateY(-6px) rotate(-45deg); }

        .menu .panel {
            position: absolute; right: 0; top: calc(100% + 10px); z-index: 60;
            min-width: 14rem; max-width: calc(100vw - 32px); padding: 8px;
            background: var(--card); border: 1px solid var(--rule);
            border-radius: var(--r-md); box-shadow: var(--shadow-lg);
            display: flex; flex-direction: column; gap: 2px;
        }
        .menu .panel a {
            display: block; padding: 11px 14px; border-radius: var(--r-sm);
            color: var(--ink); text-decoration: none; font-size: .9375rem; font-weight: 500;
        }
        .menu .panel a:hover { background: var(--page-alt); color: var(--accent); }
        .menu .panel .panel-cta {
            margin-top: 6px; background: var(--brand-blue); color: #fff;
            text-align: center; font-weight: 600;
        }
        .menu .panel .panel-cta:hover { background: #2470a3; color: #fff; }

        /* The bar has room for four links and a button; below that the links
           fold into the menu, and brand, button and menu have to fit 390px
           between them with the gutters — so the gap and the button shrink
           rather than pushing the menu off the right edge. */
        @media (max-width: 820px) {
            header.site .wrap { gap: 12px; }
            header.site nav.bar { display: none; }
            header.site .cta { margin-left: 0; order: 2; }
            .menu { display: block; margin-left: auto; order: 3; }
        }
        @media (max-width: 440px) {
            header.site .wrap { gap: 8px; }
            header.site .cta { padding: 8px 13px; font-size: .875rem; }
            .brand { gap: 9px; }
            .brand b { font-size: .875rem; }
            .brand .mark { width: 30px; height: 30px; border-radius: 9px; }
            .brand .mark img { width: 18px; height: 18px; }
            .menu summary { width: 38px; height: 38px; }
        }
        @media (max-width: 340px) { .brand b { display: none; } }

        /* ---- Page furniture ------------------------------------------ */
        main { display: block; }
        .page { padding: 40px 0 72px; }

        nav.crumbs { font-size: .875rem; color: var(--ink-faint); margin: 0 0 24px; }
        nav.crumbs a { color: var(--ink-soft); text-decoration: none; }
        nav.crumbs a:hover { color: var(--accent); text-decoration: underline; }
        nav.crumbs span { margin: 0 8px; opacity: .45; }

        .eyebrow {
            font-size: .75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .09em; color: var(--accent); margin: 0 0 14px;
        }

        .lede { font-size: 1.1875rem; line-height: 1.6; color: var(--ink-soft); margin: 0 0 32px; max-width: 46rem; }

        p.meta { color: var(--ink-faint); font-size: .875rem; margin: 0 0 28px; }
        p.meta time { white-space: nowrap; }

        /* ---- Reading columns ----------------------------------------- */
        /* The literature files bring their own markup and their own <style>;
           this sets the measure, the rhythm and nothing else. */
        article.reading {
            max-width: var(--measure); margin: 0 auto;
            font-family: Georgia, "Iowan Old Style", "Palatino Linotype", serif;
            font-size: 1.125rem; line-height: 1.8; color: var(--ink);
        }
        article.reading p { margin: 0 0 1.15em; }
        article.reading hr { border: 0; border-top: 1px solid var(--rule); margin: 2.4em 0; }
        article.reading strong { font-weight: 600; }
        article.reading p[align="center"] { text-align: center; }

        article.prose { max-width: var(--measure); margin: 0 auto; font-size: 1.0625rem; line-height: 1.8; }
        article.prose h2 { font-size: clamp(1.3125rem, 1.2rem + .6vw, 1.625rem); margin: 2em 0 .55em; }
        article.prose h3 { font-size: 1.125rem; margin: 1.7em 0 .45em; }
        article.prose p { margin: 0 0 1.25em; }
        article.prose ul, article.prose ol { padding-left: 1.35em; margin: 0 0 1.25em; }
        article.prose li { margin-bottom: .5em; }
        article.prose a { font-weight: 500; }
        article.prose blockquote {
            margin: 1.8em 0; padding: 18px 22px;
            background: var(--page-alt); border-left: 3px solid var(--brand-blue);
            border-radius: 0 var(--r-md) var(--r-md) 0; color: var(--ink-soft);
        }
        article.prose blockquote p:last-child { margin-bottom: 0; }
        article.prose img { border-radius: var(--r-md); display: block; margin: 2em 0; }
        article.prose table { border-collapse: collapse; width: 100%; margin: 1.8em 0; font-size: .9375rem; }
        article.prose th, article.prose td { border: 1px solid var(--rule); padding: 10px 12px; text-align: left; }
        article.prose th { background: var(--page-alt); font-weight: 600; }
        article.prose code {
            font-size: .9em; background: var(--page-alt); border: 1px solid var(--rule);
            border-radius: 5px; padding: 1px 6px;
        }
        article.prose hr { border: 0; border-top: 1px solid var(--rule); margin: 2.4em 0; }

        /* ---- Index lists & cards -------------------------------------- */
        ul.index { list-style: none; margin: 0 auto; padding: 0; max-width: var(--measure); }
        ul.index li + li { border-top: 1px solid var(--rule-soft); }
        ul.index a {
            display: block; padding: 15px 14px; margin: 0 -14px; text-decoration: none; color: var(--ink);
            border-radius: var(--r-sm); transition: background .18s var(--ease);
            font-weight: 500;
        }
        ul.index a:hover { background: var(--page-alt); color: var(--accent); }
        ul.index .sub { display: block; font-size: .875rem; color: var(--ink-faint); margin-top: 3px; font-weight: 400; }

        .cards { display: grid; gap: 18px; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .cards > a {
            display: block; padding: 22px; border: 1px solid var(--rule); border-radius: var(--r-lg);
            background: var(--card); text-decoration: none; color: var(--ink);
            box-shadow: var(--shadow-sm);
            transition: transform .2s var(--ease), box-shadow .2s var(--ease), border-color .2s var(--ease);
        }
        .cards > a:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: color-mix(in srgb, var(--brand-blue) 40%, var(--rule)); }
        .cards h3 { margin: 0 0 6px; font-size: 1.0625rem; }
        .cards .count { display: block; font-size: .875rem; color: var(--ink-faint); }

        /* ---- Next / previous ----------------------------------------- */
        nav.nextprev {
            display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
            margin: 56px auto 0; max-width: var(--measure);
        }
        nav.nextprev a {
            display: block; padding: 16px 18px; border: 1px solid var(--rule); border-radius: var(--r-md);
            background: var(--card); text-decoration: none; color: var(--ink); font-size: .9375rem;
            font-weight: 500; box-shadow: var(--shadow-sm);
            transition: border-color .2s var(--ease), box-shadow .2s var(--ease);
        }
        nav.nextprev a:hover { border-color: var(--brand-blue); box-shadow: var(--shadow-md); }
        nav.nextprev span {
            display: block; font-size: .6875rem; text-transform: uppercase;
            letter-spacing: .09em; color: var(--ink-faint); margin-bottom: 4px; font-weight: 700;
        }

        /* ---- Store badges & rating ------------------------------------ */
        .badges { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .badges a { display: block; line-height: 0; border-radius: 9px; transition: transform .2s var(--ease); }
        .badges a:hover { transform: translateY(-2px); }
        .badges img { height: 46px; width: auto; display: block; }

        .rating { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; font-size: .9375rem; color: var(--ink-soft); }
        .rating .stars { color: var(--star); letter-spacing: .08em; font-size: 1rem; }
        .rating b { color: var(--ink); font-weight: 700; }

        /* ---- The app call to action, on every page -------------------- */
        .appcta {
            max-width: var(--shell); margin: 72px auto 0;
            background: var(--grad); color: var(--ink-on-brand);
            border-radius: var(--r-lg); padding: 38px 36px;
            display: grid; gap: 28px; align-items: center;
            grid-template-columns: minmax(0, 1fr) auto;
            box-shadow: var(--shadow-lg);
            position: relative; overflow: hidden;
        }
        .appcta::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(60% 120% at 12% 0%, rgb(255 255 255 / .18), transparent 60%);
            pointer-events: none;
        }
        .appcta > * { position: relative; }
        .appcta h2 { margin: 0 0 8px; font-size: clamp(1.3125rem, 1.1rem + 1vw, 1.75rem); color: #fff; }
        .appcta p { margin: 0; color: rgb(255 255 255 / .82); font-size: 1rem; max-width: 46ch; }
        .appcta .rating { color: rgb(255 255 255 / .82); margin-top: 14px; }
        .appcta .rating b { color: #fff; }
        .appcta .badges img { height: 48px; }
        @media (max-width: 760px) {
            .appcta { grid-template-columns: 1fr; padding: 30px 24px; text-align: left; }
        }

        /* ---- Footer --------------------------------------------------- */
        footer.site {
            border-top: 1px solid var(--rule);
            background: var(--page-alt); padding: 48px 0 32px;
        }
        footer.site .cols {
            display: grid; gap: 32px; grid-template-columns: minmax(0, 1.4fr) repeat(3, minmax(0, 1fr));
        }
        @media (max-width: 820px) { footer.site .cols { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 460px) { footer.site .cols { grid-template-columns: 1fr; } }
        footer.site h4 {
            font-size: .75rem; text-transform: uppercase; letter-spacing: .09em;
            color: var(--ink-faint); margin: 0 0 14px; font-weight: 700;
        }
        footer.site ul { list-style: none; margin: 0; padding: 0; }
        footer.site li { margin-bottom: 9px; }
        footer.site a { color: var(--ink-soft); text-decoration: none; font-size: .9375rem; }
        footer.site a:hover { color: var(--accent); }
        footer.site .blurb { color: var(--ink-faint); font-size: .9375rem; margin: 12px 0 0; max-width: 34ch; }
        footer.site .legal {
            margin-top: 36px; padding-top: 22px; border-top: 1px solid var(--rule);
            display: flex; gap: 10px 20px; flex-wrap: wrap; align-items: center;
            font-size: .875rem; color: var(--ink-faint);
        }
        footer.site .legal .sep { margin-left: auto; }
        @media (max-width: 560px) { footer.site .legal .sep { margin-left: 0; } }
    </style>

    {{-- A page with layout of its own brings its CSS here rather than adding
         it to the shared block above, which every page would then download. --}}
    {{ $styles ?? '' }}
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>

    <header class="site">
        <div class="wrap">
            <a class="brand" href="/">
                <span class="mark" aria-hidden="true"><img src="/images/design-logo.webp" alt="" width="21" height="21"></span>
                <b>12 Step Toolkit</b>
            </a>
            {{-- The production site's top level was Literature, Blog, Web
                 Login and Contact Us. The web app link is the one people go
                 looking for and the port had dropped it. --}}
            <nav class="bar" aria-label="Main">
                @foreach($nav as $item)
                    <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <a class="cta" href="/get-app">Get the app</a>

            {{-- The menu is a <details>, so it opens, closes and takes focus
                 without a line of JavaScript, and it still works if the
                 script that is not there fails to load. --}}
            <details class="menu">
                <summary aria-label="Menu">
                    <span class="bars" aria-hidden="true"></span>
                </summary>
                <div class="panel">
                    @foreach($nav as $item)
                        <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
                    @endforeach
                    <a class="panel-cta" href="/get-app">Get the app</a>
                </div>
            </details>
        </div>
    </header>

    <main id="main">
        @if(($bare ?? false) === true)
            {{ $slot }}
        @else
            <div class="page">
                <div class="wrap">
                    @isset($crumbs)
                        <nav class="crumbs" aria-label="Breadcrumb">
                            @foreach($crumbs as $label => $href)
                                @unless($loop->first)<span aria-hidden="true">›</span>@endunless
                                @if($href === null){{ $label }}@else<a href="{{ $href }}">{{ $label }}</a>@endif
                            @endforeach
                        </nav>
                    @endisset

                    {{ $slot }}

                    @unless($hideCta ?? false)
                        <x-app-cta />
                    @endunless
                </div>
            </div>
        @endif
    </main>

    <footer class="site">
        <div class="wrap">
            <div class="cols">
                <div>
                    <a class="brand" href="/">
                        <span class="mark" aria-hidden="true"><img src="/images/design-logo.webp" alt="" width="21" height="21"></span>
                        <b>12 Step Toolkit</b>
                    </a>
                    <p class="blurb">A free A.A. app for counting days, working the Steps with a sponsor and reading the Big Book.</p>
                </div>
                <div>
                    <h4>Literature</h4>
                    <ul>
                        <li><a href="/aa-literature/big-book">The Big Book</a></li>
                        <li><a href="/aa-literature/prayers">Prayers</a></li>
                        <li><a href="/aa-literature/readings">Readings</a></li>
                        <li><a href="/aa-literature">All literature</a></li>
                    </ul>
                </div>
                <div>
                    <h4>The app</h4>
                    <ul>
                        <li><a href="/get-app">Get the app</a></li>
                        <li><a href="/blog">Blog</a></li>
                        <li><a href="/contacts">Contact us</a></li>
                        <li><a href="{{ config('site.identity.webApp') }}">Web login</a></li>
                    </ul>
                </div>
                <div>
                    <h4>Legal</h4>
                    <ul>
                        <li><a href="/privacy">Privacy</a></li>
                        <li><a href="/terms">Terms</a></li>
                    </ul>
                </div>
            </div>
            <div class="legal">
                <span>&copy; {{ date('Y') }} iByte Apps Limited</span>
                <span class="sep">Not affiliated with Alcoholics Anonymous World Services, Inc.</span>
            </div>
        </div>
    </footer>
</body>
</html>
