<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One person blocking another. UNIQUE (blocker_id, blocked_id).
 *
 * `block_report_user.php` inserts without checking for an existing row, which
 * against that unique key is a duplicate-key error the second time somebody
 * blocks the same person. Here it is an upsert, so blocking twice is simply
 * blocking.
 */
class BlockedUser extends Model
{
    protected $table = 'blocked_users';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    /** Either direction: a block hides the conversation from both sides. */
    public function scopeBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->where('is_deleted', 0)
            ->where(function (Builder $q) use ($a, $b): void {
                $q->where(fn (Builder $i) => $i->where('blocker_id', $a)->where('blocked_id', $b))
                    ->orWhere(fn (Builder $i) => $i->where('blocker_id', $b)->where('blocked_id', $a));
            });
    }
}
