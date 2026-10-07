<?php

namespace App\Models;

use App\Models\Concerns\LegacyTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message — in a chat thread, or against a step record.
 *
 * Three things the old writer got wrong, all kept in mind here:
 *
 *  * **`comment_add_update.php` updates with no account clause.**
 *    `UPDATE comments SET comment = ?, deleted = ? WHERE id = ?` — so anybody
 *    could edit or delete anybody's message, in any thread, by guessing an id.
 *    Every write here is scoped to the author.
 *  * **the delete is soft** (`deleted = 1`), unlike the step-work collections.
 *    Kept: the client's `deleteCommentByCommentID` sets the same flag, and the
 *    readers filter on it.
 *  * **only `comment` and `deleted` are updatable.** The old UPDATE sets those
 *    two columns and nothing else, so a client resending a whole message cannot
 *    rewrite its thread, its author or its timestamp. That is worth keeping
 *    exactly as it is.
 */
class Comment extends Model
{
    use LegacyTable;

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $table = 'comments';

    protected $guarded = ['*'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommentThread::class, 'thread_id', 'id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountid', 'id');
    }

    /**
     * The projection `get_comments.php` returns.
     *
     * `subscribed_at_send` is always 1 there — the reader sets it on every row
     * it decides to include, so it carries no information and is kept only
     * because the client's parser expects the key.
     */
    public function toLegacyRow(): array
    {
        return [
            'comment_id' => (int) $this->id,
            'thread_id' => (int) $this->thread_id,
            'accountid' => (int) $this->accountid,
            'sponsorid' => (int) $this->sponsorid,
            'step' => (int) $this->step,
            'recordid' => (int) $this->recordid,
            'byid' => (int) $this->byid,
            'comment' => (string) $this->comment,
            'tstamp' => (int) $this->tstamp,
            'deleted' => (int) $this->deleted,
            'seen' => (int) $this->seen,
            'time_sent' => $this->getRawOriginal('time_sent'),
            'modified' => $this->getRawOriginal('modified'),
            'subscribed_at_send' => 1,
        ];
    }

    public function scopeInThread(Builder $query, int $threadId): Builder
    {
        return $query->where('thread_id', $threadId);
    }
}
