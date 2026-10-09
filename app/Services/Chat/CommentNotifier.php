<?php

namespace App\Services\Chat;

use App\Models\Account;
use App\Models\Comment;
use App\Services\Push\DeviceTokens;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Telling the other people in a thread that somebody has written.
 *
 * Comments were being stored and nobody was being told, so a sponsor's reply
 * sat in the database until the sponsee happened to open the app.
 *
 * ## The message carries no message
 *
 * `18/notification.php` builds the body as `$nickname . $body`, where `$body`
 * is a fixed phrase — " has waved at you", " has nudged you to work on …".
 * The thing written is never in the push, and that is right: a push payload
 * travels through Google's servers and sits in a notification shade on a lock
 * screen. A sponsor conversation is the last thing that belongs in either. So
 * this says who, not what.
 *
 * ## Who is skipped
 *
 *  * the author;
 *  * anybody who has muted the thread, left it, or been removed from it;
 *  * either side of a block, in both directions;
 *  * erased accounts, which the legacy deletion marks `email = 'DELETED'`
 *    rather than removing.
 */
class CommentNotifier
{
    public function __construct(
        private readonly PushSender $push,
        private readonly DeviceTokens $tokens,
    ) {}

    /** @return int how many people were told */
    public function notify(Comment $comment, Account $author): int
    {
        $threadId = (int) $comment->thread_id;
        $authorId = (int) $author->getKey();

        if ($threadId <= 0) {
            return 0;
        }

        $told = 0;

        foreach ($this->recipients($threadId, $authorId) as $accountId) {
            $tokens = $this->tokens->for($accountId);

            if ($tokens === []) {
                continue;
            }

            try {
                $this->push->send($tokens, new PushMessage(
                    title: $this->name($author),
                    body: 'sent you a message',
                    data: ['type' => 'COMMENT', 'thread_id' => (string) $threadId],
                ));
                $told++;
            } catch (\Throwable $e) {
                // One stale token must not cost everybody else their message.
                Log::warning('comment push failed', [
                    'thread_id' => $threadId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $told;
    }

    /**
     * The author's display name.
     *
     * The legacy script falls back to a Hashids-encoded account id when the
     * nickname is blank. The new client draws a blank tile instead, so a
     * handle in the push that appears nowhere in the app would be a puzzle.
     * "Someone" matches what the recipient is looking at.
     */
    private function name(Account $author): string
    {
        $nickname = trim((string) $author->getAttribute('nickname'));

        return $nickname !== '' ? $nickname : 'Someone';
    }

    /** @return array<int, int> */
    private function recipients(int $threadId, int $authorId): array
    {
        $ids = DB::table('comment_thread_subscribers as s')
            ->join('accounts as a', 'a.id', '=', 's.account_id')
            ->where('s.thread_id', $threadId)
            ->where('s.account_id', '!=', $authorId)
            ->where('s.is_subscribed', 1)
            ->where('s.is_deleted', 0)
            ->where('s.is_muted', 0)
            ->where('a.email', '!=', 'DELETED')
            ->pluck('s.account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === [] || ! Schema::hasTable('blocked_users')) {
            return $ids;
        }

        $blocked = DB::table('blocked_users')
            ->where('is_deleted', 0)
            ->where(function ($q) use ($authorId, $ids): void {
                $q->where(fn ($w) => $w->where('blocker_id', $authorId)->whereIn('blocked_id', $ids))
                    ->orWhere(fn ($w) => $w->where('blocked_id', $authorId)->whereIn('blocker_id', $ids));
            })
            ->get(['blocker_id', 'blocked_id']);

        $excluded = [];
        foreach ($blocked as $row) {
            $excluded[] = (int) $row->blocker_id;
            $excluded[] = (int) $row->blocked_id;
        }

        return array_values(array_diff($ids, $excluded));
    }
}
