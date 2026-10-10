<?php

/*
|--------------------------------------------------------------------------
| The public website
|--------------------------------------------------------------------------
|
| 12steptoolkit.com is a Next.js static export today — 205 URLs in its
| sitemap, 180 of them the A.A. literature. This application serves the
| handful of pages a static export cannot (`routes/site.php`), and will one
| day serve all of them; `docs/WEBSITE_TAKEOVER.md` is what has to be true
| first.
|
| Until that day the application is grafted onto three prefixes of
| `12steptoolkit.com` (`/console`, `/api/v2`, `/up`) while the export goes on
| serving everything else. It is on the website's host without being the
| website, and must not look like one to a search engine.
*/

return [

    /*
     | Keep this host out of search results, as an `X-Robots-Tag` header only —
     | so the pages stay byte-for-byte what production will serve.
     |
     | **Defaults to TRUE, which is the opposite of the AA Big Book app**, and
     | deliberately: there, the application IS the website, so the safe default
     | is indexable. Here it answers three prefixes of a domain whose 205 real
     | ranking URLs come from somewhere else, and an indexed placeholder or
     | staff login does nobody any good. (The console is kept out regardless —
     | see `SecurityHeaders`.)
     |
     | The cost of that default is that somebody could switch the document root
     | of `12steptoolkit.com` to this application and silently de-index the
     | whole site. So `deploy.sh` fails the deploy — not warns — when the host
     | in `APP_URL` is the website host below and this is still on. Forgetting
     | it is caught by a machine rather than by the traffic graph a week later.
     */
    'noindex' => (bool) env('SITE_NOINDEX', true),

    /*
     | The one host where this application being indexable is correct. Read by
     | `deploy.sh` out of `.env`, which is why it is a flat value and not
     | derived from `APP_URL`.
     */
    'website_host' => env('SITE_WEBSITE_HOST', '12steptoolkit.com'),

    /*
     | Does this installation own `/` on that host?
     |
     | **False today, and that is the arrangement, not a stepping stone.**
     | `12steptoolkit.com`'s document root is
     | `WEBSITES/12steptoolkit.com/out` — the Next.js export, served through
     | Plesk's nginx → Apache proxy. This application lives in the *sibling*
     | folder `…/12steptoolkit.com/api`, and nginx routes three prefixes to it
     | (`/console`, `/api/v2`, `/up`) while everything else goes on reaching
     | the export exactly as before. One domain, two things serving it, and
     | the 169 indexed URLs never pass through here at all.
     |
     | It turns true only if the document root is ever moved onto this
     | application, which needs `docs/WEBSITE_TAKEOVER.md` satisfied first.
     |
     | What reads it: `deploy.sh`. With `false` it smoke-tests the paths this
     | application actually answers and does not judge `/`, because `/` is not
     | its business. With `true` the indexability check becomes a hard one.
     | Without this flag the smoke test would report a cheerful green tick for
     | a page served by something else entirely, which is worse than no check.
     */
    'serves_website' => (bool) env('SITE_SERVES_WEBSITE', false),

    /*
     | Who this site says it is.
     |
     | Ported from the static site's `lib/site.js`, which was its single source
     | of truth for identity and fed the canonicals, the Open Graph tags and
     | every JSON-LD node. Same job here: `App\Services\Site\Schema` reads
     | nothing else.
     |
     | **The ratings are shown on the page as well as marked up**, and that is
     | a requirement rather than a nicety: Google issues a structured-data
     | manual action for an `aggregateRating` describing numbers a visitor
     | cannot see. `combined()` derives the average from the two stores by
     | weight, so the figure in the markup cannot drift from the parts beside
     | it. Update `asOf` with the numbers.
     */
    'identity' => [
        'name' => '12 Step Toolkit',
        'legalName' => 'iByte Apps Limited',
        'tagline' => 'Sobriety App for Android & iOS',
        'description' => '12 Step Toolkit is a free sobriety app for Android and iOS with a sobriety calculator, Twelve Step worksheets, daily inventory reminders, recovery literature and in-app sponsorship.',
        'locale' => 'en_GB',
        'lang' => 'en-GB',
        'twitter' => '@12steptoolkit',
        'webApp' => 'https://web.12steptoolkit.com/',
        'appStore' => 'https://apps.apple.com/gb/app/12-step-toolkit/id1452072215',
        'appStoreId' => '1452072215',
        'playStore' => 'https://play.google.com/store/apps/details?id=com.ibyteapps.aa12steptoolkit',
        'playStoreId' => 'com.ibyteapps.aa12steptoolkit',
        'ratings' => [
            'asOf' => '2026-09-21',
            'stores' => [
                [
                    'name' => 'App Store',
                    'label' => 'on the App Store',
                    'value' => 4.8,
                    'count' => 4407,
                ],
                [
                    'name' => 'Google Play',
                    'label' => 'on Google Play',
                    'value' => 4.523,
                    'count' => 13853,
                ],
            ],
        ],
        'social' => [
            'https://facebook.com/12steptoolkit/',
            'https://tiktok.com/@12steptoolkit/',
            'https://www.instagram.com/12steptoolkit',
            'https://www.youtube.com/@12steptoolkit',
        ],
    ],

    /*
     | Where the website's content cache lives.
     |
     | The **file** store, not this application's default (the database): every
     | page it caches is derived from files on the same disk as the code, so a
     | database outage has nothing to do with whether the blog renders. Putting
     | it in the database would mean 53 indexed URLs returning 500 whenever
     | MySQL hiccups — which the static export this replaces could not do.
     |
     | A config value rather than a literal because `Cache::store('file')`
     | ignores `CACHE_STORE`, and a test run that writes real cache files
     | leaves them for the next run to read.
     */
    'cache_store' => env('SITE_CACHE_STORE', 'file'),

];
