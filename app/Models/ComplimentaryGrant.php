<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A subscription given from the console: a refund that went wrong, a hardship, a
 * store reviewer, a sponsor's gift that did not land.
 *
 * It counts as paid, it runs alongside whatever the stores say, and it stacks —
 * which is the point. The old system's only equivalent was
 * `accounts.free_upgrade`, a tinyint with no reason, no author, no start and no
 * end, so nobody could ever answer "why does this person have premium" or "when
 * does it stop".
 *
 * Revoking sets `revoked_at` rather than deleting the row: somebody gave this,
 * and somebody took it away, and both are worth being able to find later.
 */
class ComplimentaryGrant extends Model
{
    protected $guarded = ['id'];

    public const PERIODS = [
        '1_month' => '1 month',
        '3_months' => '3 months',
        '1_year' => '1 year',
        'lifetime' => 'For good',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(ConsoleUser::class, 'granted_by');
    }

    public function grantsAccess(): bool
    {
        return $this->revoked_at === null
            && $this->starts_at->isPast()
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function scopeGrantingAccess(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public static function endFor(string $period, Carbon $from): ?Carbon
    {
        return match ($period) {
            '1_month' => $from->copy()->addMonth(),
            '3_months' => $from->copy()->addMonths(3),
            '1_year' => $from->copy()->addYear(),
            default => null, // lifetime
        };
    }
}
