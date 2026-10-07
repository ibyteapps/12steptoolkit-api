<?php

namespace App\Models;

use App\Models\Concerns\LegacyTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The six step-work collections share one shape, and this is it.
 *
 * Every `*_add_update.php` in the v19 API is the same forty lines with the
 * column list changed:
 *
 *   * `online_id == 0`                    → INSERT, `action: "ADD"`
 *   * `online_id > 0 && is_deleted == 1`  → DELETE, `action: "DELETE"`
 *   * `online_id > 0`                     → UPDATE, `action: "UPDATE"`
 *
 * Three things about that are load-bearing and are kept:
 *
 *  * **`id` is the device's own row id and is echoed back untouched.** The
 *    client reconciles on it. It is never used for a lookup here.
 *  * **every statement carries `AND accountid = ?`.** In the old scripts that
 *    clause is the only thing preventing one person from writing into
 *    another's Fourth Step, because `account_id` comes from the POST body and
 *    not from the token. Here the account comes from the token *and* the clause
 *    is still applied — belt and braces, because the clause is one line and the
 *    failure is somebody else's inventory.
 *  * **the delete is a hard delete.** The old API has no tombstone on these
 *    tables, the Flutter client's own `is_deleted` is local, and adding a
 *    column would alter an adopted table. So the row goes, and the client's
 *    outbox is what makes that idempotent.
 *
 * What is *not* kept: `account_id` is no longer read from the body. A request
 * that names another account is refused rather than quietly scoped, so a
 * mis-built client is a visible error instead of a silent no-op.
 */
abstract class StepRecord extends Model
{
    use LegacyTable;

    /** @see LegacyTable — Eloquent's own constants, pointed at the real columns. */
    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    /** Columns a client may write, in the order the old INSERT lists them. */
    abstract public static function writableColumns(): array;

    /** The projection the matching `get_*.php` returns, column => cast. */
    abstract public static function projection(): array;

    /** The column the list is ordered by, descending. */
    public static function orderColumn(): string
    {
        return 'tstamp';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountid', 'id');
    }

    public function scopeOwnedBy(Builder $query, int $accountId): Builder
    {
        return $query->where('accountid', $accountId);
    }

    /**
     * One row, in the shape the client's parser expects.
     *
     * The old readers rename `id` to `onlineID` and cast a specific set of
     * columns to int, leaving the rest as strings. Both halves matter: the
     * Flutter and Kotlin models are written against exactly this.
     */
    public function toLegacyRow(): array
    {
        $out = ['onlineID' => (int) $this->getAttribute('id')];

        foreach (static::projection() as $column => $cast) {
            $value = $this->getRawOriginal($column);
            $out[$column] = match ($cast) {
                'int' => (int) $value,
                default => (string) ($value ?? ''),
            };
        }

        return $out;
    }
}
