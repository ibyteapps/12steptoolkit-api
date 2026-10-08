{{--
    The page a deploy shows while it is running.

    `php artisan down --render="errors.503"` renders this to static HTML *before*
    the code changes, so it is still on the disk when nothing else about the
    application works. That means it may not depend on a layout, a route, an
    asset or a database — everything it needs is in this file.

    `--retry` sets `Retry-After`, which is what tells a crawler this is a
    deploy and not a dead site. The status really is 503: a 200 saying "back
    soon" is how a page like this ends up indexed in place of the real one.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Back in a minute — 12 Step Toolkit</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f7f7f5; color: #1c1c1a; padding: 24px;
        }
        main { max-width: 32rem; text-align: center; }
        h1 { font-size: 1.5rem; font-weight: 600; margin: 0 0 .75rem; }
        p { margin: 0 0 .75rem; color: #5a5a54; }
        .quiet { font-size: .875rem; color: #8a8a82; }
        @media (prefers-color-scheme: dark) {
            body { background: #16161a; color: #f2f2f0; }
            p { color: #a8a8a2; }
            .quiet { color: #76766f; }
        }
    </style>
</head>
<body>
    <main>
        <h1>Back in a minute</h1>
        <p>The 12 Step Toolkit service is being updated. Nothing you have written
           is affected — it is all still here.</p>
        <p class="quiet">Your app will sync as usual once this page goes away.</p>
    </main>
</body>
</html>
