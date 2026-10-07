<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment.
 *
 * Money is held as integers in milli-units of the smallest currency unit, never
 * as floats. A float "£39.99" that has been through a currency conversion and
 * back is not £39.99 any more, and a console that shows takings has to add up.
 *
 * `gbp_milli` is the converted figure at the rate on the day, kept beside the
 * rate that produced it so that a total can be re-derived rather than trusted.
 * `net_*` is after the store's share.
 */
class StoreOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_milli' => 'int',
            'tax_milli' => 'int',
            'revenue_milli' => 'int',
            'refunded_milli' => 'int',
            'gbp_milli' => 'int',
            'net_gbp_milli' => 'int',
            'after_first_year' => 'bool',
            'is_sandbox' => 'bool',
            'purchased_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(StoreSubscription::class, 'store_subscription_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_sandbox', false);
    }

    /**
     * The store's share, as a fraction, for the rows where the store did not say
     * what it kept.
     *
     * Apple takes 30% in a subscriber's first year and 15% after — or 15%
     * throughout under the Small Business Program. Google takes 15% of
     * subscriptions. These are settings rather than constants because the
     * programmes change and a wrong figure here is a wrong figure on a page
     * somebody makes decisions from.
     */
    public static function storeShare(string $store, bool $afterFirstYear): float
    {
        if ($store === StoreSubscription::GOOGLE) {
            return (float) config('billing.fees.google');
        }

        if (config('billing.fees.apple_small_business')) {
            return (float) config('billing.fees.apple_after');
        }

        return $afterFirstYear
            ? (float) config('billing.fees.apple_after')
            : (float) config('billing.fees.apple_first_year');
    }
}
