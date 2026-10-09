<?php

namespace App\Http\Controllers\Site;

use App\Services\Site\BlogLibrary;
use App\Services\Site\Schema;
use Illuminate\Contracts\View\View;

/**
 * The home page.
 *
 * The one page in this port that is not a conversion. The static site built it
 * from twelve stacked template sections — a hero, three alternating
 * image-and-text blocks, a statistics strip, a six-card feature grid, a
 * screenshot carousel, a "Follow Us" logo row, the FAQ, a download band, a
 * reviews carousel and a cross-platform block. Seven sections carry the same
 * facts with less scrolling, and the facts are what matter: the download and
 * sponsor counts, the store ratings, what is actually in the app.
 *
 * ## The one claim that changed
 *
 * The old page said "Free For Life — Enjoy unlimited access to all premium
 * features completely free for life", three sections above an FAQ answer
 * saying the app is ad-supported with "some minor restrictions" and asking
 * members to subscribe. Both cannot be true, and the paywall is real:
 * `config/billing.php` maps thirteen Apple products and seventeen Google ones.
 * A promise of free premium above a subscription page is how an app collects
 * refund requests and one-star reviews, so the page now says what is true —
 * free to download, ad-supported, everything needed to work the Steps
 * included, subscriptions for those who can.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly Schema $schema,
        private readonly BlogLibrary $blog,
    ) {}

    public function __invoke(): View
    {
        $faqs = require resource_path('site/faqs.php');

        return view('site.home', [
            'faqs' => $faqs,
            'rating' => Schema::combinedRating(),
            'identity' => config('site.identity'),
            // Three most recent posts. The static site never linked the blog
            // from the home page, which left 52 articles two clicks from the
            // only page that gets linked to.
            'latest' => $this->blog->posts()->take(3),
            // The app node and the FAQ node: the two that earn a rich result
            // on this page. Both describe things rendered below.
            'schema' => [$this->schema->app(), $this->schema->faqs($faqs)],
        ]);
    }
}
