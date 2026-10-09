{{--
    The public website's layout.

    Self-contained on purpose: no build step, no Node, no external stylesheet.
    The CSS is here, in one place, which is also what makes the eventual
    restyle a change to one file rather than to 105 pages.

    The `<head>` is the part that must not drift. Every title, description and
    keyword list comes from `resources/site/literature.php`, which was
    converted from what the Next.js site served rather than retyped, so the
    pages keep exactly the metadata Google already has for them.
--}}
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

    <link rel="icon" href="/favicon.ico" sizes="any">

    {{-- One connected @graph per page: the organisation and the site, the
         breadcrumb trail as rendered, and whatever this page is. Assembled by
         the layout component so a page cannot ship without it. --}}
    @if($schemaJson = $schemaJson())
        <script type="application/ld+json">{!! $schemaJson !!}</script>
    @endif

    <style>
        :root {
            color-scheme: light dark;
            --ink: #1b1b19;
            --ink-soft: #55554e;
            --ink-faint: #8a8a81;
            --page: #fbfaf7;
            --card: #ffffff;
            --rule: #e5e3dc;
            --link: #bf0210;
            --measure: 38rem;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #f0efea; --ink-soft: #b0afa7; --ink-faint: #7c7b73;
                --page: #15151a; --card: #1c1c22; --rule: #2e2e36; --link: #ff8a84;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--page);
            color: var(--ink);
            font: 17px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
        }
        a { color: var(--link); }
        .wrap { max-width: 64rem; margin: 0 auto; padding: 0 16px; }

        header.site {
            border-bottom: 1px solid var(--rule);
            background: var(--card);
        }
        header.site .wrap { display: flex; align-items: center; gap: 20px; min-height: 60px; flex-wrap: wrap; }
        header.site strong { font-size: 1.0625rem; letter-spacing: -0.01em; }
        header.site a { color: var(--ink); text-decoration: none; }
        header.site nav { margin-left: auto; display: flex; gap: 18px; font-size: 0.9375rem; }
        header.site nav a { color: var(--ink-soft); }
        header.site nav a:hover { color: var(--link); }

        main { padding: 36px 0 64px; }
        nav.crumbs { font-size: 0.875rem; color: var(--ink-faint); margin-bottom: 22px; }
        nav.crumbs a { color: var(--ink-soft); text-decoration: none; }
        nav.crumbs a:hover { text-decoration: underline; }
        nav.crumbs span { margin: 0 7px; opacity: 0.5; }

        h1 { font-size: 1.75rem; line-height: 1.25; letter-spacing: -0.02em; margin: 0 0 10px; }
        .lede { color: var(--ink-soft); margin: 0 0 32px; max-width: var(--measure); }

        /* The reading column. The literature files bring their own markup and
           their own <style>; this only sets the measure and the rhythm. */
        article.reading {
            max-width: var(--measure);
            font-family: Georgia, "Iowan Old Style", "Palatino Linotype", serif;
            font-size: 1.0625rem;
            line-height: 1.75;
        }
        article.reading p { margin: 0 0 1.15em; }
        article.reading hr { border: 0; border-top: 1px solid var(--rule); margin: 2em 0; }
        article.reading img { max-width: 100%; height: auto; }
        article.reading strong { font-weight: 600; }
        /* The page numbers the scans carry, set small and quiet rather than
           as body text. */
        article.reading p[align="center"] { text-align: center; }

        ul.index { list-style: none; margin: 0; padding: 0; max-width: var(--measure); }
        ul.index li { border-bottom: 1px solid var(--rule); }
        ul.index li:first-child { border-top: 1px solid var(--rule); }
        ul.index a {
            display: block; padding: 13px 2px; text-decoration: none; color: var(--ink);
        }
        ul.index a:hover { color: var(--link); }
        ul.index .sub { display: block; font-size: 0.875rem; color: var(--ink-faint); margin-top: 2px; }

        .cards { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); }
        .cards a {
            display: block; padding: 18px; border: 1px solid var(--rule); border-radius: 10px;
            background: var(--card); text-decoration: none; color: var(--ink);
        }
        .cards a:hover { border-color: var(--link); }
        .cards .count { display: block; font-size: 0.8125rem; color: var(--ink-faint); margin-top: 4px; }

        p.meta { color: var(--ink-faint); font-size: 0.875rem; margin: -4px 0 28px; }

        /* The blog's own prose, which this application renders from Markdown
           rather than injecting as scanned HTML — so it can be styled. */
        article.prose { max-width: var(--measure); }
        article.prose h2 { font-size: 1.3125rem; line-height: 1.3; margin: 1.9em 0 0.5em; letter-spacing: -0.01em; }
        article.prose h3 { font-size: 1.0625rem; margin: 1.6em 0 0.4em; }
        article.prose ul, article.prose ol { padding-left: 1.3em; margin: 0 0 1.15em; }
        article.prose li { margin-bottom: 0.4em; }
        article.prose blockquote {
            margin: 1.5em 0; padding: 2px 0 2px 18px;
            border-left: 3px solid var(--rule); color: var(--ink-soft); font-style: italic;
        }
        article.prose table { border-collapse: collapse; width: 100%; margin: 1.5em 0; font-size: 0.9375rem; }
        article.prose th, article.prose td { border: 1px solid var(--rule); padding: 8px 10px; text-align: left; }
        article.prose th { background: var(--card); font-weight: 600; }
        article.prose code {
            font-size: 0.9em; background: var(--card); border: 1px solid var(--rule);
            border-radius: 4px; padding: 1px 5px;
        }

        nav.nextprev {
            display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
            margin-top: 44px; max-width: var(--measure);
        }
        nav.nextprev a {
            display: block; padding: 14px 16px; border: 1px solid var(--rule); border-radius: 10px;
            background: var(--card); text-decoration: none; color: var(--ink); font-size: 0.9375rem;
        }
        nav.nextprev a:hover { border-color: var(--link); }
        nav.nextprev span { display: block; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-faint); margin-bottom: 3px; }

        p.stores { display: flex; gap: 12px; flex-wrap: wrap; margin: 28px 0 0; }
        p.stores a {
            display: inline-block; padding: 10px 18px; border: 1px solid var(--rule);
            border-radius: 8px; background: var(--card); text-decoration: none;
            color: var(--ink); font-size: 0.9375rem;
        }
        p.stores a:hover { border-color: var(--link); }

        footer.site {
            border-top: 1px solid var(--rule); padding: 26px 0; margin-top: 40px;
            font-size: 0.875rem; color: var(--ink-faint);
        }
        footer.site .wrap { display: flex; gap: 18px; flex-wrap: wrap; }
        footer.site a { color: var(--ink-soft); text-decoration: none; }
    </style>

    {{-- A page with layout of its own brings its CSS here rather than adding
         it to the shared block above, which every page would then download. --}}
    {{ $styles ?? '' }}
</head>
<body>
    <header class="site">
        <div class="wrap">
            <strong><a href="/">12 Step Toolkit</a></strong>
            <nav>
                <a href="/aa-literature">A.A. Literature</a>
                <a href="/blog">Blog</a>
                <a href="/get-the-app">Get the app</a>
            </nav>
        </div>
    </header>

    <main>
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
        </div>
    </main>

    <footer class="site">
        <div class="wrap">
            <span>&copy; {{ date('Y') }} iByte Apps Limited</span>
            <a href="/privacy">Privacy</a>
            <a href="/terms">Terms</a>
            <a href="/contacts">Contact</a>
        </div>
    </footer>
</body>
</html>
