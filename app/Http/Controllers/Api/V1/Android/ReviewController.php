<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Amend;
use App\Models\Inventory;
use App\Models\Night;
use App\Models\Sponsor;
use App\Services\Push\DeviceTokens;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A sponsor marking a sponsee's Step work as reviewed.
 *
 * ## Two live scripts, and this answers the newer one
 *
 * `18/reviewed.php` is
 *
 * ```php
 * $sql = "UPDATE $tablename SET reviewed=$reviewed WHERE id='$id'";
 * ```
 *
 * — no account in the WHERE clause, no check that the caller sponsors
 * anybody, and the new value interpolated rather than bound.
 *
 * `19/mark_as_reviewed.php` is the rewrite that shipped: prepared, with a
 * fixed `item_type` → table map and a push to the member whose record it is.
 * It still updates by row id alone, so any id still marks any record reviewed
 * for any member.
 *
 * **This answers `mark_as_reviewed.php`** — the name, the fields and the
 * response shape — because that is what the client calls and parses. It is
 * one of the three endpoints in the whole surface with **no envelope**:
 * `{success: 0|1, message, …}`, read by hand in
 * `sponsorship_repository.dart`. Answering `{status, message, response}` here
 * would be a 200 the client reads as a failure.
 *
 * ## What is added
 *
 * The record must belong to the member named, and there must be an
 * **accepted** sponsorship from the caller to them — `status =
 * Sponsor::ACCEPTED`, in that direction, so a sponsee cannot mark their
 * sponsor's work reviewed and a pending request is not a relationship yet.
 *
 * ## `shared` and `reviewed`
 *
 * Both mean "the sponsor has seen it" and both exist; see {@see Inventory}.
 * This writes `reviewed` only, which is what `get_sponsee_steps_data.php`
 * reads. Nothing here touches `shared`, because a sponsor marking something
 * reviewed is not the same event as a sponsee sharing it.
 */
class ReviewController extends Controller
{
    /**
     * `item_type` → the table it means, as `19/mark_as_reviewed.php` maps it.
     *
     * Four values, and the client refuses to send anything else before it
     * asks. 4 and 10 are both the inventories table — Step Four's moral
     * inventory and Step Ten's spot check — which is why the row's own
     * `inventoryforstep` is checked below as well.
     */
    private const TABLES = [
        4 => Inventory::class,
        10 => Inventory::class,
        89 => Amend::class,
        11 => Night::class,
    ];

    /** The list name the client routes a tap on the push to. */
    private const LISTS = [
        Inventory::class => 'inventories',
        Amend::class => 'amends',
        Night::class => 'nights',
    ];

    public function __construct(
        private readonly PushSender $push,
        private readonly DeviceTokens $tokens,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $this->fail('Forbidden', 403);
        }

        $sponsor = $this->account($request);

        $itemType = (int) $request->input('item_type', 0);
        $recordId = (int) $request->input('record_id', 0);
        $friendId = (int) $request->input('friend_id', 0);
        $reviewed = (int) $request->input('reviewed', -1);

        $model = self::TABLES[$itemType] ?? null;

        if ($model === null) {
            return $this->fail('Unsupported item_type.');
        }

        if (! in_array($reviewed, [0, 1], true)) {
            return $this->fail('Invalid reviewed (must be 0 or 1).');
        }

        if ($recordId <= 0) {
            return $this->fail('Invalid record_id.');
        }

        if ($friendId <= 0 || $friendId === (int) $sponsor->id) {
            // Marking your own work reviewed is not a thing a sponsor does,
            // and it is the shape an id-swapping client would take.
            return $this->fail('Forbidden', 403);
        }

        $sponsors = Sponsor::query()
            ->where('sponsorid', $sponsor->id)
            ->where('sponseeid', $friendId)
            ->where('status', Sponsor::ACCEPTED)
            ->exists();

        if (! $sponsors) {
            return $this->fail('Forbidden', 403);
        }

        $query = $model::query()->ownedBy($friendId)->whereKey($recordId);

        if ($model === Inventory::class) {
            // A Step Ten spot check and a Step Four inventory are the same
            // table and different screens, so the type has to agree with the
            // row. Anything that is not a spot check counts as Four, because
            // Step Five's inventories are Step Four's rows read again.
            $itemType === 10
                ? $query->where('inventoryforstep', 10)
                : $query->where('inventoryforstep', '!=', 10);
        }

        /*
         | Read, then write, rather than writing and reading the affected-row
         | count. The count is a driver's opinion — PDO's MySQL driver reports
         | rows *changed*, so re-marking something already reviewed comes back
         | as 0, while SQLite reports the row as updated — and the answer this
         | endpoint gives should not depend on which database it is talking
         | to. The live script reads the count and then issues an unscoped
         | `SELECT COUNT(*) WHERE id = ?` to work out what 0 meant, which
         | answers "that record exists" about anybody's record; this one asks
         | through the same `ownedBy` scope, so the distinction is only ever
         | drawn about a sponsee the caller already sponsors.
         */
        $record = $query->first();

        if ($record === null) {
            return $this->fail('Record not found.', 404, [
                'updated' => 0,
                'record_id' => $recordId,
                'item_type' => $itemType,
            ]);
        }

        if ((int) $record->getRawOriginal('reviewed') === $reviewed) {
            return $this->ok('No change (already in requested state).', [
                'updated' => 0,
                'record_id' => $recordId,
                'item_type' => $itemType,
                'reviewed' => $reviewed,
                'notified' => 0,
            ]);
        }

        $record->forceFill(['reviewed' => $reviewed])->save();

        return $this->ok('Updated successfully.', [
            'updated' => 1,
            'record_id' => $recordId,
            'item_type' => $itemType,
            'reviewed' => $reviewed,
            'notified' => $this->tell($friendId, $recordId, $itemType, $reviewed, self::LISTS[$model]),
        ]);
    }

    /**
     * Tell the member their sponsor has read it.
     *
     * `ITEM_REVIEWED` is what both clients know. `list_type` and `record_id`
     * are what turn a tap into the record itself rather than a refresh — the
     * live script sends neither, so a tap there lands nowhere in particular.
     */
    private function tell(int $memberId, int $recordId, int $itemType, int $reviewed, string $list): int
    {
        $tokens = $this->tokens->for($memberId);

        if ($tokens === [] || $reviewed !== 1) {
            // Un-reviewing is a correction, not news.
            return 0;
        }

        try {
            return $this->push->send($tokens, new PushMessage(
                title: 'Your sponsor',
                body: 'has read your Step work',
                data: [
                    'table' => 'ITEM_REVIEWED',
                    'record_id' => (string) $recordId,
                    'item_type' => (string) $itemType,
                    'reviewed' => (string) $reviewed,
                    'list_type' => $list,
                    'type' => $list,
                ],
            ));
        } catch (\Throwable $e) {
            // The record is marked. A dead token must not undo that.
            Log::warning('review push failed', ['reason' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * `{success: 1, message, …}`.
     *
     * No envelope, deliberately: `mark_as_reviewed.php` is one of the three
     * endpoints that does not use one, and the client reads `success` by
     * hand.
     */
    private function ok(string $message, array $extra): JsonResponse
    {
        return response()->json(['success' => 1, 'message' => $message] + $extra);
    }

    private function fail(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json(['success' => 0, 'message' => $message] + $extra, $status);
    }
}
