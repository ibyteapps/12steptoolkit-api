<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Jobs\NotifyThreadOfComment;
use App\Models\BlockedUser;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\CommentReceipt;
use App\Models\CommentStar;
use App\Models\CommentThread;
use App\Models\CommentThreadSubscriber;
use App\Models\ReportedUser;
use App\Services\Chat\CommentVisibility;
use App\Services\Chat\ThreadAudience;
use App\Services\Legacy\LegacyEnvelope;
use App\Services\Push\DeviceTokens;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comments, chat threads, receipts and blocking.
 *
 * The v19 surface for this is twenty-odd scripts; these are the ones
 * `retrofit/ApiService.kt` actually calls, which is the contract that matters.
 *
 * ## What changes, and why each one had to
 *
 *  * **`comment_add_update.php` updates with no account clause.** It is
 *    `UPDATE comments SET comment = ?, deleted = ? WHERE id = ?`, so anybody
 *    could rewrite or delete anybody's message in any thread by guessing an id.
 *    Every write here is scoped to the author.
 *  * **reads were not scoped to membership.** Here every read goes through
 *    `CommentVisibility`, which is also where the subscribe-interval rule lives.
 *  * **`block_report_user.php` inserts without checking.** Against
 *    `UNIQUE (blocker_id, blocked_id)` that is a duplicate-key error the second
 *    time somebody blocks the same person. Upserted.
 *  * **a block was one-directional on read.** Blocking someone should take the
 *    conversation away from both sides, so `BlockedUser::between()` is checked
 *    in both directions.
 *
 * ## What is kept exactly
 *
 * The response shapes, down to the oddities: `comment_add_update.php` returns
 * `comment_id` at the top level *and* inside `response`; every value in that
 * `response` is `strval()`'d; `subscribed_at_send` and `is_updated_online` are
 * always 1; and `get_comment_threads.php` keys its three lists
 * `commentThreads`, `commentThreadSubscribers`, `blockedUsers` while
 * `get_comments.php` uses `comments`, `reactions`, `stars`, `counts`. The
 * Kotlin wrappers are written against exactly that.
 */
class CommentController extends Controller
{
    public const ENDPOINTS = [
        'get_comments.php' => 'comments',
        'get_step_comments.php' => 'stepComments',
        'get_comment_for_account_id.php' => 'withAccount',
        'get_comment_threads.php' => 'threads',
        'get_comment_receipts.php' => 'receipts',
        'comment_add_update.php' => 'write',
        'comment_star.php' => 'star',
        'comment_update_receipt.php' => 'receipt',
        'update_thread_subscriber.php' => 'updateSubscriber',
        'update_is_typing.php' => 'typing',
        'block_report_user.php' => 'blockOrReport',
        'unblock_user.php' => 'unblock',
        'get_blocked_users.php' => 'blockedUsers',
    ];

    public function __construct(
        private readonly CommentVisibility $visibility,
        private readonly ThreadAudience $audience,
        private readonly PushSender $push,
        private readonly DeviceTokens $tokens,
    ) {}

    // ---------------------------------------------------------------- reads

    /**
     * `get_comments.php` — one thread, or every thread this account belongs to.
     *
     * `thread_id = 0` means all of them, which is how the client does its first
     * sync.
     */
    public function comments(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $accountId = (int) $this->account($request)->id;
        $threadId = (int) $request->input('thread_id', 0);

        $threadIds = $threadId > 0 ? [$threadId] : $this->visibility->threadIds($accountId);

        $comments = collect();
        foreach ($threadIds as $id) {
            $comments = $comments->concat($this->visibility->comments($accountId, $id));
        }

        $comments = $comments->sortBy('tstamp')->values();

        return $this->commentPayload($comments);
    }

    /**
     * `get_step_comments.php` — comments against this account's step records.
     *
     * The old script returns empty `reactions` and `stars` arrays rather than
     * looking them up, and the client is written for that, so they stay empty.
     */
    public function stepComments(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $comments = Comment::query()
            ->where('accountid', (int) $this->account($request)->id)
            ->where('step', '>', 0)
            ->orderBy('tstamp')
            ->get();

        return LegacyEnvelope::ok([
            'comments' => $comments->map(fn (Comment $c) => $c->toLegacyRow())->all(),
            'reactions' => [],
            'stars' => [],
            'counts' => ['comments' => $comments->count(), 'reactions' => 0, 'stars' => 0],
        ], 'Fetched '.$comments->count().' comment(s)');
    }

    /**
     * `get_comment_for_account_id.php` — the conversation with one other person.
     *
     * Scoped to threads they actually share: the old script took both ids from
     * the body and trusted them.
     */
    public function withAccount(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $accountId = (int) $this->account($request)->id;
        $friendId = (int) $request->input('friend_id', 0);

        if ($friendId <= 0) {
            return LegacyEnvelope::fail('Invalid friend_id', 400);
        }

        // A block hides the conversation from both sides.
        if (BlockedUser::query()->between($accountId, $friendId)->exists()) {
            return $this->commentPayload(collect());
        }

        $shared = CommentThreadSubscriber::query()
            ->where('account_id', $accountId)
            ->whereIn('thread_id', CommentThreadSubscriber::query()
                ->select('thread_id')
                ->where('account_id', $friendId))
            ->pluck('thread_id');

        $comments = collect();
        foreach ($shared as $id) {
            $comments = $comments->concat($this->visibility->comments($accountId, (int) $id));
        }

        return $this->commentPayload($comments->sortBy('tstamp')->values());
    }

    /** `get_comment_threads.php` — threads, their members, and who is blocked. */
    public function threads(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $accountId = (int) $this->account($request)->id;
        $only = (int) $request->input('thread_id', 0);

        $threads = CommentThread::query()
            ->visibleTo($accountId)
            ->when($only > 0, fn ($q) => $q->where('id', $only))
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get();

        $subscribers = $threads->isEmpty()
            ? collect()
            : CommentThreadSubscriber::query()->whereIn('thread_id', $threads->pluck('id'))->get();

        $blocked = BlockedUser::query()
            ->where('blocker_id', $accountId)
            ->where('is_deleted', 0)
            ->get();

        return LegacyEnvelope::ok([
            'commentThreads' => $threads->map(fn (CommentThread $t) => $t->toLegacyRow())->all(),
            'commentThreadSubscribers' => $subscribers->map(fn (CommentThreadSubscriber $s) => $s->toLegacyRow())->all(),
            'blockedUsers' => $blocked->map(fn (BlockedUser $b) => [
                'id' => (int) $b->id,
                'blocker_id' => (int) $b->blocker_id,
                'blocked_id' => (int) $b->blocked_id,
            ])->all(),
        ], 'Fetched '.$threads->count().' thread(s)');
    }

    /** `get_comment_receipts.php` — delivered and read, for this account. */
    public function receipts(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $rows = CommentReceipt::query()
            ->where('account_id', (int) $this->account($request)->id)
            ->orderBy('id')
            ->get();

        return LegacyEnvelope::ok(
            $rows->map(fn (CommentReceipt $r) => $r->toLegacyRow())->all(),
            'Fetched '.$rows->count().' receipt(s)',
        );
    }

    /** `get_blocked_users.php` — who this account has blocked, with their nickname. */
    public function blockedUsers(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $rows = DB::table('blocked_users as b')
            ->join('accounts as a', 'a.id', '=', 'b.blocked_id')
            ->where('b.blocker_id', (int) $this->account($request)->id)
            ->where('b.is_deleted', 0)
            // The old script's filter. `email = 'DELETED'` is how the legacy
            // account deletion marked a row rather than removing it.
            ->where('a.email', '!=', 'DELETED')
            ->orderBy('b.id')
            ->get(['b.id', 'b.blocker_id', 'b.blocked_id', 'b.reason_code', 'a.nickname', 'a.icon']);

        return LegacyEnvelope::ok($rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'blocker_id' => (int) $r->blocker_id,
            'blocked_id' => (int) $r->blocked_id,
            'reason_code' => (int) $r->reason_code,
            'nickname' => (string) $r->nickname,
            'icon' => (int) $r->icon,
        ])->all(), 'Fetched '.$rows->count().' blocked user(s)');
    }

    // --------------------------------------------------------------- writes

    /**
     * `comment_add_update.php` — ADD when `comment_id` is 0, UPDATE otherwise.
     *
     * The update touches `comment` and `deleted` only, as the old one does, and
     * only for the author's own message. A `comment_id` that is not theirs is a
     * 403 rather than a silent no-op, so a muddled client finds out.
     */
    public function write(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);
        $accountId = (int) $account->id;
        $commentId = (int) $request->input('comment_id', 0);
        $threadId = (int) $request->input('thread_id', 0);
        $body = (string) $request->input('comment', '');
        $isDeleted = (int) $request->input('is_deleted', 0);

        if ($commentId > 0) {
            $comment = Comment::query()->find($commentId);

            if ($comment === null) {
                return LegacyEnvelope::fail('Comment not found', 404);
            }

            // The clause the old script does not have.
            if ((int) $comment->accountid !== $accountId) {
                return LegacyEnvelope::fail('Forbidden', 403);
            }

            $comment->forceFill(['comment' => $body, 'deleted' => $isDeleted])->save();
            $action = 'UPDATE';
        } else {
            if (trim($body) === '') {
                return LegacyEnvelope::fail('Comment is empty', 400);
            }

            if (! $this->mayPostTo($accountId, $threadId)) {
                return LegacyEnvelope::fail('Forbidden', 403);
            }

            $comment = new Comment;
            $comment->forceFill([
                'thread_id' => $threadId,
                'accountid' => $accountId,
                'sponsorid' => (int) $request->input('fcm_account_id', 0),
                'step' => (int) $request->input('step', 0),
                'recordid' => (int) $request->input('record_id', 0),
                'byid' => (int) $request->input('by_id', $accountId),
                'comment' => $body,
                'tstamp' => (int) $request->input('tstamp', time()),
                'time_sent' => $request->input('time_sent') ?: now()->toDateTimeString(),
            ])->save();

            $commentId = (int) $comment->id;
            $action = 'ADD';

            // Tell the rest of the thread. Only on ADD: an edit is not an
            // event anybody's phone needs to light up for.
            NotifyThreadOfComment::dispatch($commentId, $accountId);
        }

        // The old response: `comment_id` at the top level as well as inside
        // `response`, and every value in `response` a string.
        return LegacyEnvelope::okWith(
            ['comment_id' => $commentId],
            LegacyEnvelope::strings([
                'table' => 'COMMENTS',
                'action' => $action,
                'comment_id' => $commentId,
                'thread_id' => (int) $comment->thread_id,
                'accountid' => (int) $comment->accountid,
                'sponsorid' => (int) $comment->sponsorid,
                'step' => (int) $comment->step,
                'recordid' => (int) $comment->recordid,
                'byid' => (int) $comment->byid,
                'comment' => (string) $comment->comment,
                'tstamp' => (int) $comment->tstamp,
                'deleted' => (int) $comment->deleted,
                'seen' => 0,
                'time_sent' => $comment->getRawOriginal('time_sent'),
                'time_delivered' => '0000-00-00 00:00:00',
                'time_read' => '0000-00-00 00:00:00',
                'status' => 1,
                'modified' => $comment->getRawOriginal('modified'),
            ]),
            "Comment {$action} successful",
        );
    }

    /** `comment_star.php` — upserted, because the table is unique on the pair. */
    public function star(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $accountId = (int) $this->account($request)->id;
        $commentId = (int) $request->input('comment_id', 0);

        if ($commentId <= 0 || ! $this->mayRead($accountId, $commentId)) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        CommentStar::query()->upsert(
            [['comment_id' => $commentId, 'account_id' => $accountId, 'is_deleted' => (int) $request->input('is_deleted', 0)]],
            uniqueBy: ['account_id', 'comment_id'],
            update: ['is_deleted'],
        );

        return LegacyEnvelope::ok(['comment_id' => $commentId], 'Star updated');
    }

    /** `comment_update_receipt.php` — delivered and read times, never backwards. */
    public function receipt(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $accountId = (int) $this->account($request)->id;
        $commentId = (int) $request->input('comment_id', 0);

        if ($commentId <= 0 || ! $this->mayRead($accountId, $commentId)) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $existing = CommentReceipt::query()
            ->where('account_id', $accountId)
            ->where('comment_id', $commentId)
            ->first();

        // A receipt only ever moves forward. The old script writes whatever it
        // is sent, so a retry with a stale value un-reads a message.
        $delivered = max((int) $request->input('time_delivered', 0), (int) ($existing->time_delivered ?? 0));
        $read = max((int) $request->input('time_read', 0), (int) ($existing->time_read ?? 0));

        CommentReceipt::query()->upsert(
            [['account_id' => $accountId, 'comment_id' => $commentId, 'time_delivered' => $delivered, 'time_read' => $read]],
            uniqueBy: ['account_id', 'comment_id'],
            update: ['time_delivered', 'time_read'],
        );

        return LegacyEnvelope::ok(
            ['comment_id' => $commentId, 'time_delivered' => $delivered, 'time_read' => $read],
            'Receipt updated',
        );
    }

    /**
     * `update_is_typing.php` — "somebody is writing".
     *
     * The live script takes `account_id` from the body and updates
     * `comment_thread_subscribers` by `(thread_id, account_id)` with no check
     * that the caller is either of them, so anybody could set anybody's
     * typing flag in anybody's thread — and then have the server push it to
     * that thread. Here the account is the signed one and it has to be a
     * member of the thread.
     *
     * The push is **silent**, which it has to be: a typing indicator that
     * arrives in the notification shade is not a typing indicator. It also
     * goes to people who have muted the thread, because muting is about
     * being interrupted and this draws on a screen somebody is already
     * looking at. {@see ThreadAudience}.
     */
    public function typing(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $me = (int) $this->account($request)->id;
        $threadId = (int) $request->input('thread_id', 0);
        $isTyping = $request->boolean('is_typing') ? 1 : 0;

        $membership = CommentThreadSubscriber::query()
            ->where('thread_id', $threadId)
            ->where('account_id', $me)
            ->where('is_deleted', 0)
            ->first();

        if ($membership === null) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $now = now();

        $membership->forceFill($isTyping === 1
            ? ['is_typing' => 1, 'last_typing_at' => $now]
            : ['is_typing' => 0],
        )->save();

        $this->announceTyping($threadId, $me, $isTyping, $now);

        return LegacyEnvelope::ok([
            'thread_id' => $threadId,
            'account_id' => $me,
            'is_typing' => $isTyping,
        ]);
    }

    /** Tell the rest of the thread, quietly. */
    private function announceTyping(int $threadId, int $me, int $isTyping, Carbon $now): void
    {
        foreach ($this->audience->for($threadId, $me, includeMuted: true) as $accountId) {
            $tokens = $this->tokens->for($accountId);

            if ($tokens === []) {
                continue;
            }

            try {
                $this->push->send($tokens, PushMessage::silent([
                    'table' => 'comment_thread_subscribers',
                    'action' => 'TYPING',
                    'thread_id' => (string) $threadId,
                    'account_id' => (string) $me,
                    'is_typing' => (string) $isTyping,
                    'last_typing_at' => $isTyping === 1 ? $now->toDateTimeString() : '',
                ]));
            } catch (\Throwable $e) {
                // Nobody's conversation is worse off for a lost typing dot.
                Log::warning('typing push failed', ['thread_id' => $threadId, 'reason' => $e->getMessage()]);
            }
        }
    }

    /**
     * `update_thread_subscriber.php` — leave, mute, or be made an admin.
     *
     * Two rules the old script has not got: you may change your own membership,
     * and an admin may change somebody else's; and `is_admin` cannot be granted
     * to yourself.
     *
     * **`accountMismatch()` is deliberately not called here.** Everywhere else
     * in this API `account_id` names the caller, and the base class refuses a
     * request whose body disagrees with its token. On this endpoint — and on
     * `block_report_user.php`, where the caller is `user_id` — `account_id`
     * names the *subscriber being changed*, so the usual check would make it
     * impossible for an admin to remove anybody. The authorisation it would have
     * given is done below instead, against the caller's own membership row.
     */
    public function updateSubscriber(Request $request): JsonResponse
    {
        $me = (int) $this->account($request)->id;
        $threadId = (int) $request->input('thread_id', 0);
        $target = (int) $request->input('account_id', $me) ?: $me;

        $mine = CommentThreadSubscriber::query()
            ->where('thread_id', $threadId)->where('account_id', $me)->first();

        if ($mine === null) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $row = $target === $me ? $mine : CommentThreadSubscriber::query()
            ->where('thread_id', $threadId)->where('account_id', $target)->first();

        if ($row === null) {
            return LegacyEnvelope::fail('Not found', 404);
        }

        $amAdmin = (int) $mine->is_admin === 1;

        if ($target !== $me && ! $amAdmin) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $changes = [
            'is_deleted' => (int) $request->input('is_deleted', $row->is_deleted),
            'is_muted' => (int) $request->input('is_muted', $row->is_muted),
        ];

        // Only an admin promotes, and never themselves.
        if ($request->has('is_admin') && $amAdmin && $target !== $me) {
            $changes['is_admin'] = (int) $request->input('is_admin');
        }

        // Leaving closes the visibility interval, which is what stops somebody
        // reading what is said after they have gone.
        if ($changes['is_deleted'] === 1) {
            $changes['is_subscribed'] = 0;
        }

        $row->forceFill($changes)->save();

        return LegacyEnvelope::ok(['thread_id' => $threadId, 'account_id' => $target], 'Subscriber updated');
    }

    /** `block_report_user.php` — `action` is "block" or "report". */
    public function blockOrReport(Request $request): JsonResponse
    {
        $me = (int) $this->account($request)->id;

        // The old script takes `user_id` from the body and acts as them.
        if ((int) $request->input('user_id', $me) !== $me) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $target = (int) $request->input('target_id', 0);
        $action = (string) $request->input('action', 'block');
        $reasonCode = (int) $request->input('reason_code', 0);
        $reason = mb_substr((string) $request->input('reason', ''), 0, 1000);

        if ($target <= 0 || $target === $me) {
            return LegacyEnvelope::fail('Invalid target_id', 400);
        }

        if ($action === 'report') {
            ReportedUser::query()->insert([
                'reporter_id' => $me,
                'reported_id' => $target,
                'reported_thread_id' => ((int) $request->input('thread_id', 0)) ?: null,
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'status' => ReportedUser::PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return LegacyEnvelope::ok(['reported' => $target], 'Reported');
        }

        // Upserted: blocking twice is blocking, not a duplicate-key error.
        BlockedUser::query()->upsert(
            [[
                'blocker_id' => $me,
                'blocked_id' => $target,
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'is_deleted' => 0,
                'created_at' => now(),
            ]],
            uniqueBy: ['blocker_id', 'blocked_id'],
            update: ['reason_code', 'reason', 'is_deleted'],
        );

        return LegacyEnvelope::ok(['blocked' => $target], 'Blocked');
    }

    /** `unblock_user.php` — only your own blocks. */
    public function unblock(Request $request): JsonResponse
    {
        $me = (int) $this->account($request)->id;

        if ((int) $request->input('blocker_id', $me) !== $me) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $removed = BlockedUser::query()
            ->where('blocker_id', $me)
            ->where('blocked_id', (int) $request->input('blocked_id', 0))
            ->delete();

        return $removed > 0
            ? LegacyEnvelope::ok(['unblocked' => (int) $request->input('blocked_id')], 'Unblocked')
            : LegacyEnvelope::fail('Not found', 404);
    }

    // ------------------------------------------------------------- plumbing

    private function commentPayload(Collection $comments): JsonResponse
    {
        $ids = $comments->pluck('id');

        $reactions = $ids->isEmpty() ? collect() : CommentReaction::query()->whereIn('comment_id', $ids)->get();
        $stars = $ids->isEmpty() ? collect() : CommentStar::query()->whereIn('comment_id', $ids)->get();

        return LegacyEnvelope::ok([
            'comments' => $comments->map(fn (Comment $c) => $c->toLegacyRow())->all(),
            'reactions' => $reactions->map(fn (CommentReaction $r) => $r->toLegacyRow())->all(),
            'stars' => $stars->map(fn (CommentStar $s) => $s->toLegacyRow())->all(),
            'counts' => [
                'comments' => $comments->count(),
                'reactions' => $reactions->count(),
                'stars' => $stars->count(),
            ],
        ], sprintf(
            'Successfully fetched %d comment%s and %d reaction%s',
            $comments->count(), $comments->count() !== 1 ? 's' : '',
            $reactions->count(), $reactions->count() !== 1 ? 's' : '',
        ));
    }

    /** A subscribed, undeleted membership of the thread. */
    private function mayPostTo(int $accountId, int $threadId): bool
    {
        return $threadId > 0 && CommentThreadSubscriber::query()
            ->where('thread_id', $threadId)
            ->where('account_id', $accountId)
            ->where('is_deleted', 0)
            ->where('is_subscribed', 1)
            ->exists();
    }

    /** May this account see this comment at all? Used before starring or receipting it. */
    private function mayRead(int $accountId, int $commentId): bool
    {
        $comment = Comment::query()->find($commentId);

        if ($comment === null) {
            return false;
        }

        return $this->visibility
            ->comments($accountId, (int) $comment->thread_id)
            ->contains('id', $commentId);
    }
}
