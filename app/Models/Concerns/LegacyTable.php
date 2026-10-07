<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * The conventions every adopted table shares.
 *
 * The legacy schema predates this application by years and is not going to be
 * reshaped (see `database/migrations/2026_10_07_000200`). Three of its habits
 * have to be handled in one place rather than in thirty:
 *
 *  1. **`created` / `modified`, not `created_at` / `updated_at`.** Eloquent's
 *     timestamp handling is pointed at the real column names.
 *
 *  2. **The zero date.** New rows are written with
 *     `modified = '0000-00-00 00:00:00'` by the old code, and a MySQL running
 *     with `NO_ZERO_DATE` cannot even store it. Carbon cannot parse it either:
 *     left alone it throws, or silently becomes -0001-11-30. It is read as
 *     *null* — "never modified" — which is what it means.
 *
 *  3. **`accountid`, one word.** The foreign key is spelled that way in every
 *     adopted table, and the ownership clause that goes with it is the only
 *     thing standing between one person's Fourth Step and another's. It is
 *     applied by a global scope rather than by remembering.
 */
trait LegacyTable
{
    /**
     * The sentinel the old code writes for "never".
     *
     * `CREATED_AT` and `UPDATED_AT` are **not** declared here: PHP 8.2 refuses
     * a trait constant that differs from one on the parent class, and Eloquent
     * declares both. Each model using this trait repeats the two lines; the
     * alternative is a trait that cannot be used on a model, which is worse.
     */
    public const ZERO_DATE = '0000-00-00 00:00:00';

    /**
     * Reads a legacy datetime, tolerating the zero date and an empty string.
     */
    public static function legacyDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === self::ZERO_DATE) {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        if (str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `created` is a real datetime on every adopted table; `modified` may be the
     * zero date. Both are read through {@see legacyDate}.
     */
    public function getCreatedAttribute(mixed $value): ?Carbon
    {
        return self::legacyDate($value);
    }

    public function getModifiedAttribute(mixed $value): ?Carbon
    {
        return self::legacyDate($value);
    }
}
