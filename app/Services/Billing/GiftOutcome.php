<?php

namespace App\Services\Billing;

/**
 * What happened when a sponsor handed a seat to somebody.
 *
 * Four answers rather than a boolean, because the three failures are not the
 * same thing to a client: `AlreadyGifted` is a success the second time round
 * (the call is idempotent and the retry must not look like an error),
 * `NoSeats` means buy another one, and `UnusableTerm` means the purchase
 * itself could not be read as a number of months and nobody should be charged
 * for it again until that is fixed.
 */
enum GiftOutcome: string
{
    /** A seat was assigned, and the sponsee is premium from now until the term ends. */
    case Gifted = 'gifted';

    /** This sponsee already holds a seat on this order. Nothing to do, and not a failure. */
    case AlreadyGifted = 'already_gifted';

    /** Every seat on the order is taken. */
    case NoSeats = 'no_seats';

    /**
     * The order carries no term — no `months`, and a SKU that does not say.
     *
     * `get_sponsee_gift_expiry.php` refuses a row with `months <= 0`, so
     * writing one would be taking the money and handing over nothing. See
     * {@see SponseeGifts::monthsFor()}.
     */
    case UnusableTerm = 'unusable_term';

    public function succeeded(): bool
    {
        return $this === self::Gifted || $this === self::AlreadyGifted;
    }
}
