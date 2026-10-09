<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Comment;
use App\Services\Chat\OneToOneThread;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `check_threads_connections_with_another_account.php` — the once-per-account
 * iOS back-fill.
 *
 * The iOS app predates threads. Its messages live in `comments` with a
 * `sponsorid`/`accountid` pair and no `thread_id`, so an upgrading member
 * arrives with conversations the new client cannot find. This walks every
 * partner they have ever exchanged comments with, makes sure a one-to-one
 * thread exists for each pair, and stamps the orphaned `step = 13` comments
 * with its id.
 *
 * `lib/core/migration/steps/s011_server_backfill.dart` is the caller, and it
 * runs this exactly once per account — but it is written to be safe if that
 * promise is broken, and so is this: every step is find-or-create or an
 * UPDATE that is already true the second time.
 *
 * ## What changed from the script
 *
 *  * **It is signed.** The live one declares `REQUIRE_AUTH false` and
 *    `REQUIRE_HMAC false` and takes `account_id` from the query string, so
 *    anybody could run a back-fill against anybody — creating threads and
 *    re-pointing their comments. Here the account is the signed caller, and a
 *    posted `account_id` that disagrees is a 403.
 *  * **One transaction**, not one per partner. The script commits per partner
 *    and reports `action: error` for any that failed, which leaves a member
 *    half migrated and a client that has no idea.
 *  * **No step log in the response.** The live one returns a `$log` of every
 *    partner id it touched. Those are account ids of people the caller talks
 *    to, and the client ignores the payload anyway.
 *
 * ## The assistant
 *
 * Account 1 is the assistant. Every member gets a thread with it whether or
 * not they have ever written to it, and that thread gets one greeting — the
 * `byid = 1` check is what stops a second greeting on a second run.
 */
class ThreadBackfillController extends Controller
{
    /**
     * 2020-01-01. The script's own constant, and the reason for it is sound:
     * a thread reconstructed from years-old comments is not new, and dating
     * it today would float every migrated conversation to the top of the list
     * on the day somebody upgrades.
     */
    private const BACKFILL_EPOCH = 1577836800;

    private const ASSISTANT_ID = 1;

    private const GREETING = 'Hello, How can I help you?';

    /** The step the one-to-one messages carry. */
    private const DIRECT_MESSAGE_STEP = 13;

    public function __construct(private readonly OneToOneThread $threads) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $me = (int) $this->account($request)->getKey();

        DB::transaction(function () use ($me): void {
            foreach ($this->partners($me) as $partner) {
                $threadId = $this->threads->for(
                    $me,
                    $partner,
                    createdAt: self::BACKFILL_EPOCH,
                    adminId: $me,
                );

                $this->stamp($threadId, $me, $partner);

                if ($partner === self::ASSISTANT_ID) {
                    $this->greet($threadId, $me);
                }
            }
        });

        // The client ignores the payload and re-reads its threads on this
        // message, so it is kept exactly as the script sends it.
        return LegacyEnvelope::ok(null, 'REFRESH THREADS NOW');
    }

    /**
     * Everybody this account has exchanged comments with, plus the assistant.
     *
     * @return array<int, int>
     */
    private function partners(int $me): array
    {
        $theirs = Comment::query()->where('sponsorid', $me)->distinct()->pluck('accountid');
        $mine = Comment::query()->where('accountid', $me)->distinct()->pluck('sponsorid');

        $partners = $theirs->merge($mine)
            ->map(fn ($id): int => (int) $id)
            ->push(self::ASSISTANT_ID)
            // 0 is the absence of a partner, not account zero, and a thread
            // with oneself is the shape a bad row would take.
            ->reject(fn (int $id): bool => $id <= 0 || $id === $me)
            ->unique()
            ->sort()
            ->values();

        return $partners->all();
    }

    /** Point this pair's orphaned direct messages at their thread. */
    private function stamp(int $threadId, int $me, int $partner): void
    {
        Comment::query()
            ->where('step', self::DIRECT_MESSAGE_STEP)
            ->where(function ($query) use ($me, $partner): void {
                $query->where(fn ($q) => $q->where('sponsorid', $me)->where('accountid', $partner))
                    ->orWhere(fn ($q) => $q->where('sponsorid', $partner)->where('accountid', $me));
            })
            ->update(['thread_id' => $threadId]);
    }

    /** One greeting in the assistant's thread, however many times this runs. */
    private function greet(int $threadId, int $me): void
    {
        $already = Comment::query()
            ->where('thread_id', $threadId)
            ->where('byid', self::ASSISTANT_ID)
            ->exists();

        if ($already) {
            return;
        }

        $now = time();

        $comment = new Comment;
        $comment->forceFill([
            'thread_id' => $threadId,
            'accountid' => $me,
            'sponsorid' => self::ASSISTANT_ID,
            'step' => self::DIRECT_MESSAGE_STEP,
            'recordid' => 0,
            'byid' => self::ASSISTANT_ID,
            'comment' => self::GREETING,
            'tstamp' => $now,
            'deleted' => 0,
            'seen' => 0,
            'time_sent' => now(),
        ])->save();
    }
}
