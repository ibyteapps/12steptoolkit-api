<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Amend;
use App\Models\Inventory;
use App\Models\Night;
use App\Models\Sponsor;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A sponsor marking a sponsee's Step work as reviewed.
 *
 * ## What `18/reviewed.php` does
 *
 * ```php
 * $sql = "UPDATE $tablename SET reviewed=$reviewed WHERE id='$id'";
 * ```
 *
 * No account in the WHERE clause and no check that the caller sponsors
 * anybody, so any id marks any record reviewed — or un-reviewed — for any
 * member. `$reviewed` is interpolated rather than bound, so it is also an
 * injection point into an UPDATE statement. The table is chosen by a `step`
 * number posted by the caller.
 *
 * ## What this does
 *
 * The caller is the signed-in account, from the signed request. The record
 * must belong to the sponsee named, and there must be an **accepted**
 * sponsorship from the caller to that sponsee — `status = Sponsor::ACCEPTED`,
 * in that direction, so a sponsee cannot mark their own sponsor's work
 * reviewed and a pending request is not a relationship yet.
 *
 * `step` still chooses the table, because that is the contract the apps in
 * the field already speak, but it chooses from a fixed map rather than
 * naming a table, and a step that is not in the map is refused before any
 * query runs.
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
     * The step numbers the apps send, and the table each one means.
     *
     * 4 and 5 are both the moral inventory — Step Four writes it, Step Five
     * admits it — and 89 is the apps' shorthand for the amends pair.
     */
    private const TABLES = [
        4 => Inventory::class,
        5 => Inventory::class,
        10 => Inventory::class,
        11 => Night::class,
        8 => Amend::class,
        9 => Amend::class,
        89 => Amend::class,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $sponsor = $this->account($request);

        $step = (int) $request->input('step', 0);
        $onlineId = (int) $request->input('online_id', $request->input('id', 0));
        $sponseeId = (int) $request->input('sponsee_id', $request->input('userid', 0));
        $reviewed = $request->boolean('reviewed') ? 1 : 0;

        $model = self::TABLES[$step] ?? null;

        if ($model === null) {
            return LegacyEnvelope::fail('Unknown step', 400);
        }

        if ($onlineId <= 0 || $sponseeId <= 0) {
            return LegacyEnvelope::fail('A record and a sponsee are required', 400);
        }

        if ($sponseeId === (int) $sponsor->id) {
            // Marking your own work reviewed is not a thing a sponsor does,
            // and it is the shape an id-swapping client would take.
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $sponsors = Sponsor::query()
            ->where('sponsorid', $sponsor->id)
            ->where('sponseeid', $sponseeId)
            ->where('status', Sponsor::ACCEPTED)
            ->exists();

        if (! $sponsors) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $query = $model::query()->ownedBy($sponseeId)->whereKey($onlineId);

        if ($model === Inventory::class) {
            // A step number that means "inventory" still has to agree with the
            // row: marking a Step Ten spot check from the Step Four screen
            // would otherwise work.
            $query->where('inventoryforstep', $step === 10 ? 10 : 4);
        }

        $updated = $query->update(['reviewed' => $reviewed]);

        if ($updated === 0) {
            // Not found, or not theirs. The same answer either way, so this
            // cannot be used to ask whether a record id exists.
            return LegacyEnvelope::fail('Record not found', 404);
        }

        return LegacyEnvelope::ok(['reviewed' => $reviewed]);
    }
}
