<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Services\Legacy\LegacyEnvelope;
use App\Services\Newsletter\NewsletterStatus;
use App\Services\Newsletter\Sendy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Joining the mailing list.
 *
 * ## The address is the account's, never the body's
 *
 * Both legacy versions take the address from the POST —
 * `aa/web/1/newsletter_subscribe.php` reads `email` and `accountid` and
 * writes `update accounts set newsletter_subscribed = 1 where id=$accountid`
 * with neither authenticated, and `8/newsletter_subscribe.php` reads `sEmail`
 * and hands it to Sendy with a key written into the file. Either one will
 * subscribe somebody else's address to an A.A. mailing list, which is a thing
 * people do to each other.
 *
 * Here the address comes from the signed account's own row. There is no
 * parameter for it, so there is nothing to abuse.
 *
 * ## The column and the list are two different facts
 *
 * `accounts.newsletter_subscribed` is what the apps read to decide whether to
 * nag; the list is what actually sends mail. The column is set only when
 * Sendy has accepted the address, so the two cannot drift into "the app
 * thinks you subscribed and no mail ever comes".
 */
class NewsletterController extends Controller
{
    public const ENDPOINTS = [
        'newsletter_subscribe.php' => 'subscribe',
        'newsletter_status.php' => 'status',
    ];

    public function __construct(private readonly Sendy $sendy) {}

    public function subscribe(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);
        $email = trim((string) $account->getAttribute('email'));

        if ($email === '' || $email === 'DELETED' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return LegacyEnvelope::fail('This account has no email address to subscribe', 422);
        }

        if (! $this->sendy->configured()) {
            // Said plainly rather than answered with a cheerful true: a member
            // who is told they have subscribed and never hears anything has
            // been lied to by a missing environment variable.
            return LegacyEnvelope::fail('The mailing list is not configured', 503);
        }

        if (! $this->sendy->subscribe($email, (string) ($account->getAttribute('nickname') ?: ''))) {
            return LegacyEnvelope::fail('The mailing list did not accept that', 502);
        }

        $account->forceFill(['newsletter_subscribed' => 1])->save();

        return LegacyEnvelope::ok(['newsletter_subscribed' => 1], 'Subscribed');
    }

    /**
     * Where this account stands with the list.
     *
     * Only ever about the caller — the old `getnewslettersubscribed.php` will
     * answer about any address anybody posts, which turns it into a way of
     * asking whether somebody is on an A.A. mailing list.
     */
    public function status(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);
        $email = trim((string) $account->getAttribute('email'));

        $status = $email === '' ? NewsletterStatus::NotSubscribed : $this->sendy->status($email);

        // "Could not ask" is not "no". The column is what this server knows,
        // and it is the answer when Sendy cannot be reached.
        if ($status === NewsletterStatus::Unknown) {
            $status = (int) $account->getAttribute('newsletter_subscribed') === 1
                ? NewsletterStatus::Subscribed
                : NewsletterStatus::NotSubscribed;
        }

        return LegacyEnvelope::ok(['status' => $status->value], 'ok');
    }
}
