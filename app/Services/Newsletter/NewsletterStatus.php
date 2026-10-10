<?php

namespace App\Services\Newsletter;

/**
 * Where an address stands with the mailing list.
 *
 * The three values the old iOS build reads are `subscribed`, `notconfirmed`
 * and `notsubscribed` — bare words in the response body, compared with
 * `contains`. They are the enum's values here so that the Apple endpoint can
 * answer with one and nothing has to remember the spelling.
 */
enum NewsletterStatus: string
{
    case Subscribed = 'subscribed';

    /** Sendy has the address but the person has not clicked the confirmation. */
    case NotConfirmed = 'notconfirmed';

    case NotSubscribed = 'notsubscribed';

    /** Sendy could not be asked. Never shown to a client as a negative. */
    case Unknown = 'unknown';

    /** Sendy's own words, which are capitalised sentences rather than codes. */
    public static function fromSendy(string $body): self
    {
        return match (mb_strtolower($body)) {
            'subscribed' => self::Subscribed,
            'unconfirmed' => self::NotConfirmed,
            'unsubscribed', 'bounced', 'soft bounced', 'complained' => self::NotSubscribed,
            default => self::Unknown,
        };
    }
}
