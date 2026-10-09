<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\Comment;
use App\Services\Chat\CommentNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushing a comment to the rest of its thread, off the request.
 *
 * Queued because a thread can have several subscribers and FCM v1 is one call
 * per device: doing that inline would make writing a message as slow as the
 * slowest handset's token lookup.
 *
 * Ids rather than models, so a message that sits in the queue for a minute
 * sends what the thread looks like when it runs, and a comment deleted in the
 * meantime quietly sends nothing.
 */
class NotifyThreadOfComment implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $commentId,
        public readonly int $authorId,
    ) {}

    public function handle(CommentNotifier $notifier): void
    {
        $comment = Comment::query()->find($this->commentId);
        $author = Account::query()->find($this->authorId);

        if ($comment === null || $author === null || (int) $comment->deleted === 1) {
            return;
        }

        $notifier->notify($comment, $author);
    }
}
