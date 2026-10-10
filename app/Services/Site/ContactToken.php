<?php

namespace App\Services\Site;

use Illuminate\Support\Facades\Config;

/**
 * The token the contact form carries, and why it is not a CSRF token.
 *
 * `routes/site.php` runs with route-model binding and nothing else — no
 * session, no cookie. That is deliberate: the website's pages are read by
 * strangers and a session cookie on every one of them is a consent banner
 * waiting to happen. So there is no session to hold a CSRF token in, and
 * adding the `web` group to the contact page alone would start setting a
 * cookie on it.
 *
 * It would also protect very little. CSRF protects an action taken *with
 * somebody's credentials*; this form has none — a forged cross-site POST
 * opens a support ticket, which is exactly what an honest POST does. The real
 * threat to a public form is a spam robot, and a CSRF token does not stop one.
 *
 * What this does instead: the page carries an HMAC of the current hour,
 * signed with the application key. A sender has to have fetched the page, and
 * the value stops working two hours later. It is stateless, needs no cookie,
 * and raises the cost of blind POSTing — which, with the honeypot and the
 * rate limit, is the part that matters.
 */
final class ContactToken
{
    /** One hour. The previous one is accepted too, so a form is good for up to two. */
    private const WINDOW = 3600;

    public static function issue(): string
    {
        return self::sign(self::bucket());
    }

    public static function valid(?string $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        foreach ([self::bucket(), self::bucket() - 1] as $bucket) {
            if (hash_equals(self::sign($bucket), $token)) {
                return true;
            }
        }

        return false;
    }

    private static function bucket(): int
    {
        return intdiv(time(), self::WINDOW);
    }

    private static function sign(int $bucket): string
    {
        return hash_hmac('sha256', 'contact:'.$bucket, (string) Config::get('app.key'));
    }
}
