<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One address per page: no trailing slash, no `.html`.
 *
 * Both forms are reachable from the static site this replaces — it wrote
 * `/blog.html` beside a `/blog/` directory — and both are linked to from
 * elsewhere on the internet. Serving a 200 at each would be two addresses
 * for one page, which splits whatever ranking the page has between them.
 *
 * The static site did this in `.htaccess`, in rules whose comments run to
 * forty lines because Apache's `mod_dir` kept fighting `mod_rewrite` over
 * directories that were also pages. None of that applies here: Laravel has no
 * directories to stat, so the rule is just the rule.
 *
 * 301 rather than 308, matching what the site has been answering since
 * September — a crawler that has already recorded a 301 has nothing to
 * re-learn.
 *
 * ## `/app/` is exempt, and that is not tidiness
 *
 * The embedded PHP app's landing pages are served at their `.html` and
 * `index.php` addresses — `/app/reset/reset_password.html` is a 200 on the
 * live site and the extensionless form is a 404. **Password-reset and
 * email-verification links already sent to people point at those addresses.**
 * Stripping the extension there sends somebody halfway through resetting a
 * password to a 404, which is why the site's nginx config gives `/app/…` its
 * own location blocks rather than letting the general rules near it.
 *
 * Nothing under `/app/` is indexed (`robots.txt` disallows it, and the
 * `.htaccess` sets `X-Robots-Tag: noindex`), so there is no canonical-URL
 * argument to weigh against that.
 */
class CanonicalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodCacheable()) {
            return $next($request);
        }

        $path = $request->getPathInfo();

        // The PHP app's own addresses, which emails already point at.
        if (str_starts_with($path, '/app/') || $path === '/app') {
            return $next($request);
        }

        $canonical = $path;

        if (str_ends_with($canonical, '.html')) {
            $canonical = substr($canonical, 0, -5);
        }

        // Not the root: `/` is the one address that keeps its slash.
        if ($canonical !== '/' && str_ends_with($canonical, '/')) {
            $canonical = rtrim($canonical, '/');
        }

        if ($canonical === $path) {
            return $next($request);
        }

        $query = $request->getQueryString();

        return new RedirectResponse(
            ($canonical === '' ? '/' : $canonical).($query === null ? '' : '?'.$query),
            301,
        );
    }
}
