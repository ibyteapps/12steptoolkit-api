<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who, in a thread, may hear from whom.
 *
 * One place for the question, because it is asked by everything that sends a
 * signal into a conversation — a message, a typing indicator, a receipt — and
 * two copies of it would drift until one of them told somebody's blocked
 * contact that they were typing.
 *
 * Always excluded: the sender; anybody who has left the thread or been
 * removed from it; either side of a block, in both directions; and erased
 * accounts, which the legacy deletion marks `email = 'DELETED'` rather than
 * removing.
 *
 * **Muting is separate.** Somebody who turned off notifications for a thread
 * still has the thread open sometimes, and a typing indicator is not an
 * interruption — it is a thing the screen draws while they are looking at it.
 * So `$includeMuted` is true for data signals and false for anything that
 * ends up in a notification shade.
 */
class ThreadAudience
{
    /** @return array<int, int> */
    public function for(int $threadId, int $exceptAccountId, bool $includeMuted = false): array
    {
        if ($threadId <= 0) {
            return [];
        }

        $query = DB::table('comment_thread_subscribers as s')
            ->join('accounts as a', 'a.id', '=', 's.account_id')
            ->where('s.thread_id', $threadId)
            ->where('s.account_id', '!=', $exceptAccountId)
            ->where('s.is_subscribed', 1)
            ->where('s.is_deleted', 0)
            ->where('a.email', '!=', 'DELETED');

        if (! $includeMuted) {
            $query->where('s.is_muted', 0);
        }

        $ids = $query->pluck('s.account_id')->map(fn ($id): int => (int) $id)->all();

        return $this->withoutBlocks($ids, $exceptAccountId);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function withoutBlocks(array $ids, int $accountId): array
    {
        if ($ids === [] || ! Schema::hasTable('blocked_users')) {
            return $ids;
        }

        $blocked = DB::table('blocked_users')
            ->where('is_deleted', 0)
            ->where(function ($q) use ($accountId, $ids): void {
                $q->where(fn ($w) => $w->where('blocker_id', $accountId)->whereIn('blocked_id', $ids))
                    ->orWhere(fn ($w) => $w->where('blocked_id', $accountId)->whereIn('blocker_id', $ids));
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
