<?php

/*
|--------------------------------------------------------------------------
| Redirects carried over from the addresses this site used to have
|--------------------------------------------------------------------------
|
| Extracted from the static site's generated `.htaccess` — `scripts/
| build-htaccess.mjs` in the 12steptoolkit-website repository — rather than
| retyped, because each line is a real address that still has links and
| ranking behind it and a typo turns one of those into a 404.
|
| Most are WordPress and OpenCart URLs from before that rebuild: `/aa/…`,
| `/tag/…`, `/privacy.php`. They have been 301ing since 2026-09-23 and go on
| 301ing here, because a redirect that has been consolidated is not a
| redirect to drop.
|
| **The 69 `%2F` story addresses are deliberately not in this list.** They
| follow one rule — a personal story addressed under `big-book/` belongs at
| its own section — and `LiteratureController::storyUnderBigBook()` applies
| it to all of them. 69 lines of config that a route already covers is 69
| chances to mistype a slug.
|
| Everything else the old file did — stripping `.html`, the trailing slash,
| the canonical host, serving the export — is either the router's job now or
| the web server's, and `docs/WEBSITE_TAKEOVER.md` says which.
*/

return [

    /*
     | Permanently gone, not moved. `410` tells a crawler to stop asking,
     | which `404` does not.
     */
    'gone' => [
        'aa/app/all-done',
    ],

    /*
     | Off-site, and a 302 rather than a 301: the daily reflection lives at
     | aa.org and this address has always been a pointer at it, not a page
     | that moved. A 301 would tell a crawler the pointer itself is now
     | aa.org's, which it is not.
     */
    'elsewhere' => [
        'reflections.php' => 'https://www.aa.org/pages/en_US/daily-reflection',
    ],

    'moved' => [
        'aa/aa-big-book-stories-edition-1/22-another-prodigal-story' => '/aa-literature/stories-edition-1/another-prodigal-story',
        'aa/aa-big-book-stories-edition-1/8-traveler-editor-scholar' => '/aa-literature/stories-edition-1/traveler-editor-scholar',
        'aa/aa-big-book-stories-edition-2/1-pioneers-of-a-a' => '/aa-literature/stories-edition-2/pioneers-of-aa',
        'aa/aa-big-book-stories-edition-2/17-fear-of-fear' => '/aa-literature/stories-edition-2/fear-of-fear',
        'aa/aa-big-book-stories-edition-2/18-the-professor-and-the-paradox' => '/aa-literature/stories-edition-2/the-professor-and-the-paradox',
        'aa/aa-big-book-stories-edition-2/31-jims-story' => '/aa-literature/stories-edition-2/jims-story',
        'aa/aa-big-book-stories-edition-2/4-he-had-to-be-shown' => '/aa-literature/stories-edition-2/he-had-to-be-shown',
        'aa/aa-big-book-stories-edition-2/40-free-from-bondage' => '/aa-literature/stories-edition-2/freedom-from-bondage',
        'aa/aa-big-book-stories-edition-2/8-the-vicious-cycle' => '/aa-literature/stories-edition-2/the-vicious-cycle',
        'aa/aa-big-book/chapter-1-bills-story' => '/aa-literature/big-book/bills-story',
        'aa/aa-big-book/chapter-3-more-about-alcoholism' => '/aa-literature/big-book/more-about-alcoholism',
        'aa/aa-big-book/dr-bobs-nightmare' => '/aa-literature/big-book/dr-bobs-nightmare',
        'aa/aa-literature/aa-3rd-step-prayer' => '/aa-literature/prayers/third-step-prayer',
        'aa/aa-literature/how-it-works' => '/aa-literature/readings/how-it-works',
        'aa/aa-literature/serenity-prayer-extended' => '/aa-literature/prayers/serenity-prayer-extended',
        'aa/uncategorised/1-pioneers-of-a-a' => '/aa-literature/stories-edition-2/pioneers-of-aa',
        'get-the-app' => '/get-app',
        'privacy-policy' => '/privacy',
        'privacy-policy-ibyte' => '/privacy',
        'privacy.php' => '/privacy',
        'tag/aa-literature' => '/aa-literature',
        'tag/he-sold-himself-short' => '/aa-literature/stories-edition-2/he-sold-himself-short',
        'tag/he-who-loses-his-wife' => '/aa-literature/stories-edition-2/he-who-loses-his-life',
        'tag/home-brewmeister' => '/aa-literature/stories-edition-2/home-brewmeister',
        'tag/now-we-are-thousands' => '/aa-literature/stories-edition-1/now-we-are-thousands',
        'tag/stories-edition-2' => '/aa-literature/stories-edition-2',
        'terms.php' => '/terms',
    ],

];
