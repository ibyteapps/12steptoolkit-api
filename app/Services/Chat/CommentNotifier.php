<?php

namespace App\Services\Chat;

use App\Models\Account;
use App\Models\Comment;
use App\Services\Push\DeviceTokens;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\Log;

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
 *
 * That list lives in {@see ThreadAudience} now, because the typing indicator
 * asks the same question and two copies of it would drift until one of them
 * told somebody's blocked contact that they were typing.
 */
class CommentNotifier
{
    public function __construct(
        private readonly PushSender $push,
        private readonly DeviceTokens $tokens,
        private readonly ThreadAudience $audience,
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

        foreach ($this->audience->for($threadId, $authorId) as $accountId) {
            $tokens = $this->tokens->for($accountId);

            if ($tokens === []) {
                continue;
            }

            try {
                $this->push->send($tokens, new PushMessage(
                    title: $this->name($author),
                    body: 'sent you a message',
                    /*
                     | `table`, not `type`. Both clients switch on
                     | `data['table']` and neither has ever read `type` as the
                     | routing key: `PushRouter.knownTables` lists `COMMENTS`
                     | and answers `IgnoreLink('unknown_table')` for anything
                     | it does not recognise, and an absent `table` is
                     | `empty_payload`. Every live script that notifies a
                     | thread sends `['table' => 'COMMENTS']`, so this one
                     | does too, and `thread_id` is what makes a tap open the
                     | conversation rather than the community list.
                     */
                    data: ['table' => 'COMMENTS', 'thread_id' => (string) $threadId],
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
}
