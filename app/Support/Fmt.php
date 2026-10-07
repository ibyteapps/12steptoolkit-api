<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The console's formatting, in one place, because a date that reads differently
 * on two pages makes somebody doubt both of them.
 *
 * Three rules:
 *
 *  * **"never" is written, not left blank.** An empty cell is ambiguous between
 *    "no" and "we did not look".
 *  * **a date is always absolute as well as relative.** "3 days ago" is what you
 *    read; the exact timestamp is what you quote back to Apple.
 *  * **money is integers all the way to the string.** Everything is held in
 *    milli-units of the smallest currency unit, and this is the only place it
 *    becomes a decimal.
 */
final class Fmt
{
    public static function when(?Carbon $at, string $never = 'never'): string
    {
        return $at === null ? $never : $at->diffForHumans();
    }

    public static function exact(?Carbon $at, string $never = '—'): string
    {
        return $at === null ? $never : $at->toDayDateTimeString().' UTC';
    }

    /** `1250000` → `£1.25`. Milli-pence in, pounds out. */
    public static function gbp(int $milli): string
    {
        return '£'.number_format($milli / 100000, 2);
    }

    public static function count(?int $n): string
    {
        return number_format((int) $n);
    }

    /** `annual` → `Annual`; null → `—`. */
    public static function label(?string $value): string
    {
        return $value === null || $value === '' ? '—' : ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * The tag class for an entitlement or subscription state.
     *
     * `grace_period` is deliberately a warning rather than a success: the person
     * does have access, and somebody should know the card is failing.
     */
    public static function stateClass(string $state): string
    {
        return match ($state) {
            'active', 'trial' => 'good',
            'grace_period', 'cancelled', 'on_hold', 'paused' => 'warn',
            'expired', 'refunded', 'none' => 'bad',
            default => '',
        };
    }
}
