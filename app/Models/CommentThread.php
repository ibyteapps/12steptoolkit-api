<?php

namespace App\Models;

use App\Models\Concerns\LegacyTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation: either a sponsor/sponsee pair or a group.
 *
 * Adopted table. `created` is an INT here and a DATETIME in most of this
 * schema, which is why it is cast rather than left to `LegacyTable`.
 */
class CommentThread extends Model
{
    use LegacyTable;

    protected $table = 'comment_threads';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'thread_id', 'id');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(CommentThreadSubscriber::class, 'thread_id', 'id');
    }

    public function scopeVisibleTo(Builder $query, int $accountId): Builder
    {
        return $query->whereIn('id', CommentThreadSubscriber::query()
            ->select('thread_id')
            ->where('account_id', $accountId));
    }

    /** The projection `get_comment_threads.php` returns, including the duplicated id. */
    public function toLegacyRow(): array
    {
        return [
            'id' => (int) $this->id,
            // The client's `comment_thread_id` is its server id; `id` is sent as
            // well because the old reader sent both and the Room entity maps on
            // the second. Changing either breaks reconciliation.
            'comment_thread_id' => (int) $this->id,
            'title' => (string) $this->title,
            'description' => (string) $this->description,
            'icon' => (int) $this->icon,
            'created_by' => (int) $this->created_by,
            'created' => $this->getRawOriginal('created'),
            'modified' => $this->getRawOriginal('modified'),
            'is_group' => (int) $this->is_group,
        ];
    }
}
