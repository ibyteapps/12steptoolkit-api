<?php

namespace App\Services\Chat;

use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use Illuminate\Support\Facades\DB;

/**
 * The one-to-one thread for a pair of accounts, created if it is not there.
 *
 * Lifted out of `SponsorController` so the iOS back-fill and the sponsorship
 * flow share one find-or-create rather than having one each — two of these
 * drifting apart is how a pair ends up with two threads and the conversation
 * split between them.
 *
 * Found by looking for a non-group, non-deleted thread both are subscribed
 * to, which is what `update_sponsors.php` does.
 */
class OneToOneThread
{
    /**
     * @param  int|null  $createdAt  The thread's `created` stamp. The back-fill
     *                               passes an old one on purpose: a thread
     *                               reconstructed from years-old comments is
     *                               not new, and dating it today would float
     *                               every migrated conversation to the top of
     *                               the list on the day somebody upgrades.
     * @param  int|null  $adminId  Which of the two is marked `is_admin`.
     *                             Null leaves both at 0, which is what the
     *                             sponsorship flow has always done.
     */
    public function for(int $a, int $b, ?int $createdAt = null, ?int $adminId = null): int
    {
        $existing = DB::table('comment_threads as t')
            ->join('comment_thread_subscribers as sa', 'sa.thread_id', '=', 't.id')
            ->join('comment_thread_subscribers as sb', 'sb.thread_id', '=', 't.id')
            ->where('t.is_group', 0)
            ->where('t.is_deleted', 0)
            ->where('sa.account_id', $a)
            ->where('sb.account_id', $b)
            ->value('t.id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $thread = new CommentThread;
        $thread->forceFill([
            'title' => '',
            'description' => '',
            'icon' => 0,
            'created_by' => $a,
            'created' => $createdAt ?? time(),
            'is_group' => 0,
            'is_muted' => 0,
        ])->save();

        foreach ([$a, $b] as $accountId) {
            // Upserted: UNIQUE (thread_id, account_id).
            CommentThreadSubscriber::query()->upsert(
                [[
                    'thread_id' => $thread->id,
                    'account_id' => $accountId,
                    'subscribed_at' => $createdAt ?? time(),
                    'is_subscribed' => 1,
                    'is_deleted' => 0,
                    'is_admin' => $adminId !== null && $accountId === $adminId ? 1 : 0,
                ]],
                uniqueBy: ['thread_id', 'account_id'],
                update: ['is_subscribed', 'is_deleted'],
            );
        }

        return (int) $thread->id;
    }
}
