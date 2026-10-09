{{--
    The member area's shell.

    It shares the website's tokens and type so the two feel like one product,
    and nothing else: no Tailwind, no build step, no admin template. The old
    web app was TailAdmin with 120 dashboard components, of which it used a
    sidebar and a table.
--}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'My Toolkit' }} · 12 Step Toolkit</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root {
            color-scheme: light;
            --brand-blue: #2980b9; --brand-violet: #b06ab3; --accent: #1583c4;
            --grad: linear-gradient(135deg, #2980b9 0%, #5f73c4 52%, #ab67b2 100%);
            --wash: linear-gradient(145deg, #eef4fb 0%, #f3f1fa 52%, #faf3f7 100%);
            --ink: #141a21; --ink-soft: #50606f; --ink-faint: #7b8794;
            --page: #ffffff; --page-alt: #f5f8fb; --card: #ffffff;
            --rule: #e4e9ef; --rule-soft: #eef2f6; --danger: #c0392b;
            --shadow-sm: 0 1px 2px rgb(16 28 42 / .06);
            --shadow-md: 0 4px 10px -2px rgb(16 28 42 / .08), 0 12px 28px -8px rgb(16 28 42 / .10);
            --shadow-lg: 0 8px 20px -6px rgb(16 28 42 / .12), 0 30px 60px -20px rgb(16 28 42 / .20);
            --r-sm: 8px; --r-md: 14px; --r-lg: 20px; --r-pill: 999px;
            --ease: cubic-bezier(.4,0,.2,1);
        }
        *,*::before,*::after { box-sizing: border-box; }
        body {
            margin: 0; background: var(--page-alt); color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 1.0625rem; line-height: 1.65; -webkit-font-smoothing: antialiased;
        }
        a { color: var(--accent); text-underline-offset: 3px; }
        h1,h2,h3 { line-height: 1.15; letter-spacing: -.021em; margin: 0 0 .5em; font-weight: 700; }
        h1 { font-size: clamp(1.625rem, 1.3rem + 1.4vw, 2.125rem); }
        h2 { font-size: 1.25rem; }
        .wrap { max-width: 60rem; margin: 0 auto; padding: 0 20px; }
        :where(a,button,summary,input,[tabindex]):focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 4px; }

        header.my {
            background: var(--page); border-bottom: 1px solid var(--rule-soft);
            position: sticky; top: 0; z-index: 40;
        }
        header.my .wrap { display: flex; align-items: center; gap: 14px; min-height: 64px; }
        .brand { display: inline-flex; align-items: center; gap: 11px; text-decoration: none; color: var(--ink); }
        .brand .mark { width: 32px; height: 32px; border-radius: 9px; background: var(--grad); display: grid; place-items: center; }
        .brand .mark img { width: 20px; height: 20px; display: block; }
        .brand b { font-size: 1rem; white-space: nowrap; }
        header.my form { margin-left: auto; }
        header.my button.out {
            background: none; border: 1px solid var(--rule); color: var(--ink-soft);
            font: inherit; font-size: .875rem; padding: 7px 14px; border-radius: var(--r-pill); cursor: pointer;
        }
        header.my button.out:hover { color: var(--ink); background: var(--page-alt); }

        main { padding: 32px 0 72px; }

        .flash {
            background: #eaf6ee; border: 1px solid #bfe0c9; color: #1e5c33;
            padding: 12px 16px; border-radius: var(--r-md); margin: 0 0 24px; font-size: .9375rem;
        }
        .errors {
            background: #fdecea; border: 1px solid #f3c2bd; color: #8d2b20;
            padding: 12px 16px; border-radius: var(--r-md); margin: 0 0 24px; font-size: .9375rem;
        }
        .errors ul { margin: 0; padding-left: 1.1em; }

        .card {
            background: var(--card); border: 1px solid var(--rule); border-radius: var(--r-lg);
            box-shadow: var(--shadow-sm);
        }

        /* --- sign in ------------------------------------------------- */
        .signin { min-height: 78vh; display: grid; place-items: center; background: var(--wash); }
        .signin .box { width: 100%; max-width: 25rem; padding: 34px 30px; }
        .signin h1 { font-size: 1.5rem; text-align: center; margin-bottom: 8px; }
        .signin .sub { text-align: center; color: var(--ink-soft); font-size: .9375rem; margin: 0 0 26px; }
        label { display: block; font-size: .8125rem; font-weight: 700; letter-spacing: .04em;
                text-transform: uppercase; color: var(--ink-faint); margin: 0 0 7px; }
        input[type=email], input[type=text] {
            width: 100%; font: inherit; padding: 12px 14px; color: var(--ink);
            background: var(--page); border: 1px solid var(--rule); border-radius: var(--r-md);
        }
        input:focus { border-color: var(--accent); }
        .code { letter-spacing: .6em; text-align: center; font-size: 1.5rem; font-weight: 700; padding: 14px; }
        button.primary {
            width: 100%; margin-top: 18px; font: inherit; font-weight: 600; cursor: pointer;
            background: var(--brand-blue); color: #fff; border: 0;
            padding: 13px 18px; border-radius: var(--r-pill); box-shadow: var(--shadow-sm);
        }
        button.primary:hover { background: #2470a3; }
        .signin .alt { text-align: center; margin: 18px 0 0; font-size: .875rem; color: var(--ink-faint); }
        .signin .alt button {
            background: none; border: 0; color: var(--accent); font: inherit; font-size: inherit;
            cursor: pointer; padding: 0; text-decoration: underline;
        }

        /* --- lists ---------------------------------------------------- */
        .tiles { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        .tile { padding: 20px 22px; text-decoration: none; color: var(--ink); display: block;
                transition: transform .18s var(--ease), box-shadow .18s var(--ease); }
        .tile:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .tile .n { display: block; font-size: 1.75rem; font-weight: 800; letter-spacing: -.03em;
                   background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .tile .k { font-weight: 600; }
        .tile .s { display: block; color: var(--ink-faint); font-size: .875rem; margin-top: 3px; }

        .rows { list-style: none; margin: 0; padding: 0; }
        .rows li + li { border-top: 1px solid var(--rule-soft); }
        .rows a { display: block; padding: 16px 22px; text-decoration: none; color: var(--ink); }
        .rows a:hover { background: var(--page-alt); }
        .rows .t { font-weight: 600; }
        .rows .d { display: block; color: var(--ink-faint); font-size: .875rem; margin-top: 3px; }
        .rows .x { display: block; color: var(--ink-soft); font-size: .9375rem; margin-top: 4px; }

        .empty { padding: 48px 22px; text-align: center; color: var(--ink-faint); }

        .back { display: inline-block; font-size: .9375rem; margin: 0 0 18px; text-decoration: none; }
        .field { padding: 18px 22px; }
        .field + .field { border-top: 1px solid var(--rule-soft); }
        .field dt { font-size: .75rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
                    color: var(--ink-faint); margin: 0 0 6px; }
        .field dd { margin: 0; white-space: pre-wrap; }

        form.danger { margin-top: 26px; }
        button.remove {
            font: inherit; font-size: .9375rem; cursor: pointer; background: none;
            color: var(--danger); border: 1px solid #efc9c4; padding: 10px 18px; border-radius: var(--r-pill);
        }
        button.remove:hover { background: #fdecea; }

        nav.pages { display: flex; gap: 10px; margin-top: 22px; font-size: .9375rem; }
        nav.pages a, nav.pages span {
            padding: 8px 14px; border-radius: var(--r-pill); border: 1px solid var(--rule);
            background: var(--card); text-decoration: none; color: var(--ink);
        }
        nav.pages span { color: var(--ink-faint); }
    </style>
</head>
<body>
    <header class="my">
        <div class="wrap">
            <a class="brand" href="{{ route('my.home') }}">
                <span class="mark" aria-hidden="true"><img src="/images/design-logo.webp" alt="" width="20" height="20"></span>
                <b>My Toolkit</b>
            </a>
            @auth('member')
                <form method="POST" action="{{ route('my.sign-out') }}">
                    @csrf
                    <button class="out" type="submit">Sign out</button>
                </form>
            @endauth
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>
</body>
</html>
