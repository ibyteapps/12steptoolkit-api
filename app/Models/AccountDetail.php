<?php

namespace App\Models;

use App\Models\Concerns\LegacyTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The 1:1 extension of `accounts`: profile, sponsorship availability and the
 * reminder times.
 *
 * Two of its columns are not what their names suggest:
 *
 *  * **`accept_new_sponsees` is a string.** Some rows hold `'true'`/`'false'`
 *    and some hold `1`/`0`, because the two APIs wrote it differently;
 *    `get_community_wall.php` has to test `= 1 OR = 'true'`. {@see acceptsNewSponsees}
 *    is the only thing that should read it.
 *  * **`age`, `gender` and `profession` use `-1` as "not said"**, not null.
 *
 * Every column other than `accountid` must have a default, because
 * `8/getcounts.php` creates the row with `INSERT INTO account_details
 * (accountid) VALUES (…)` and nothing else.
 */
class AccountDetail extends Model
{
    use LegacyTable;

    protected $table = 'account_details';

    /** @see LegacyTable — Eloquent's own constants, pointed at the real columns. */
    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'accountid' => 'int',
            'lastseen' => 'int',
            'age' => 'int',
            'gender' => 'int',
            'profession' => 'int',
            'accept_new_sponsor' => 'int',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountid', 'id');
    }

    /** Reads the dual-typed column the way the live SQL does. */
    public function acceptsNewSponsees(): bool
    {
        return self::truthy($this->attributes['accept_new_sponsees'] ?? null);
    }

    public function acceptsNewChat(): bool
    {
        return self::truthy($this->attributes['accept_new_chat'] ?? null);
    }

    public static function truthy(mixed $value): bool
    {
        return $value === 1 || $value === '1' || $value === true || $value === 'true';
    }
}
