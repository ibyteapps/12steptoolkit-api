<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody's membership of a thread.
 *
 * **UNIQUE (thread_id, account_id)** — confirmed from the real schema, and it
 * is the fact the whole read path turns on. See `CommentVisibility`: the old
 * reader walks these rows as a *history* of subscribe and unsubscribe
 * transitions, and the unique key means there can only ever be one, so that
 * history is always a single entry. (`comment_thread_subscriber_history` is
 * where transitions were presumably meant to live; no reader touches it.)
 *
 * Joining is therefore an upsert on that pair, never an insert.
 */
class CommentThreadSubscriber extends Model
{
    protected $table = 'comment_thread_subscribers';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommentThread::class, 'thread_id', 'id');
    }

    public function toLegacyRow(): array
    {
        return [
            'id' => (int) $this->id,
            'thread_id' => (int) $this->thread_id,
            'account_id' => (int) $this->account_id,
            // An INT epoch, nullable. Sent unchanged: the client reads it as a Long.
            'subscribed_at' => $this->getRawOriginal('subscribed_at'),
            'is_subscribed' => (int) $this->is_subscribed,
            'is_deleted' => (int) $this->is_deleted,
            'is_admin' => (int) $this->is_admin,
            'is_typing' => (int) $this->is_typing,
            'last_typing_at' => $this->getRawOriginal('last_typing_at'),
            'is_muted' => (int) $this->is_muted,
            'modified' => $this->getRawOriginal('modified'),
            // Always 1 from the old reader: it means "this came from the server".
            'is_updated_online' => 1,
        ];
    }
}
