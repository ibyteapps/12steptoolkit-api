<?php

namespace App\Services\Chat;

use App\Models\Comment;
use App\Models\CommentThreadSubscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which messages in a thread a person is allowed to read.
 *
 * ## The rule, and why it is worth being careful with
 *
 * `get_comments.php` does not simply return a thread's messages to its members.
 * It builds the intervals during which this account was subscribed, and returns
 * only the messages whose `tstamp` falls inside one. So somebody who leaves a
 * group and rejoins later does **not** see what was said while they were away.
 *
 * That is a real privacy promise in a recovery app — people say things in a
 * group on the understanding that the room is who is in it — and it would be
 * very easy to lose by "simplifying" this into `where thread_id = ?`. Hence a
 * class of its own, with tests.
 *
 * ## What the intervals actually are in production
 *
 * The old reader walks `comment_thread_subscribers` ordered by
 * `subscribed_at, id` as a *history* of transitions. But that table is
 * **UNIQUE (thread_id, account_id)**, so there is only ever one row per person
 * per thread, and the history is always a single entry:
 *
 *  * `is_subscribed = 1` → one open interval, `[subscribed_at, ∞)`;
 *  * `is_subscribed = 0` → no open interval, so nothing is visible.
 *
 * `comment_thread_subscriber_history` is presumably where the transitions were
 * meant to live, and no reader anywhere touches it.
 *
 * The interval walk below is written to handle any number of rows, so it
 * behaves identically to the old reader today and becomes correct on its own
 * terms the day somebody starts writing a real history. That is cheaper than
 * hard-coding the single-row case and finding out later.
 *
 * A null or zero `subscribed_at` means "since the beginning", which is how rows
 * written before that column was populated have to be read.
 */
class CommentVisibility
{
    /**
     * @return list<array{0: int, 1: int|null}> closed and open intervals, in order
     */
    public function intervals(int $accountId, int $threadId): array
    {
        $rows = CommentThreadSubscriber::query()
            ->where('account_id', $accountId)
            ->where('thread_id', $threadId)
            ->orderBy('subscribed_at')
            ->orderBy('id')
            ->get(['is_subscribed', 'subscribed_at', 'is_deleted']);

        $intervals = [];
        $openStart = null;
        $lastState = null;

        foreach ($rows as $row) {
            $state = (int) $row->is_subscribed === 1 && (int) $row->is_deleted === 0;
            $at = (int) ($row->getRawOriginal('subscribed_at') ?? 0);

            // A repeat of the same state is not a transition.
            if ($lastState !== null && $state === $lastState) {
                continue;
            }

            if ($state) {
                $openStart = $at;
            } elseif ($openStart !== null) {
                $intervals[] = [$openStart, $at];
                $openStart = null;
            }

            $lastState = $state;
        }

        if ($openStart !== null) {
            $intervals[] = [$openStart, null];
        }

        return $intervals;
    }

    /** The threads this account has any membership row for, subscribed or not. */
    public function threadIds(int $accountId): array
    {
        return CommentThreadSubscriber::query()
            ->where('account_id', $accountId)
            ->orderBy('thread_id')
            ->distinct()
            ->pluck('thread_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The messages this account may read from one thread.
     *
     * No intervals means no access, and that is returned as an empty list
     * rather than as "everything" — the direction a mistake here has to fail in.
     */
    public function comments(int $accountId, int $threadId): Collection
    {
        $intervals = $this->intervals($accountId, $threadId);

        if ($intervals === []) {
            return collect();
        }

        return Comment::query()
            ->inThread($threadId)
            ->where(function (Builder $q) use ($intervals): void {
                foreach ($intervals as [$from, $to]) {
                    $q->orWhere(function (Builder $i) use ($from, $to): void {
                        // Both sides are Unix **seconds**, so they compare
                        // directly. `F.getTStamp()` in the client is
                        // `Date().time / 1_000L` (`extras/F.kt:607`) and
                        // `comments.tstamp` is a bigint holding that — wide,
                        // not finer-grained. Checked, because assuming
                        // milliseconds here would have silently widened every
                        // interval by a factor of a thousand and let everybody
                        // read everything.
                        $i->where('tstamp', '>=', $from);
                        if ($to !== null) {
                            $i->where('tstamp', '<=', $to);
                        }
                    });
                }
            })
            ->orderBy('tstamp')
            ->get();
    }
}
