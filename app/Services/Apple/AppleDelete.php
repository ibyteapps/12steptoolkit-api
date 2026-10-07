<?php

namespace App\Services\Apple;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * `8/deleterecord.php` — the worst statement in either API.
 *
 * ```php
 * $id = $_POST["id"] ?? 0;
 * $tablename = 'inventories';            // …and a chain of else-ifs
 * $sql = "delete from $tablename where id='$id'";
 * ```
 *
 * No ownership clause. No bound parameter. And the table **defaults to
 * `inventories`**, so a request with a missing or unrecognised `type` deletes
 * from somebody's Fourth Step.
 *
 * ## Why there is still no ownership clause here
 *
 * Because the protocol cannot carry one. The client posts exactly three fields
 * — `id`, `type` and the shared secret (`_Constants.swift:520`) — and the
 * secret is the same in every binary. There is no caller identity to compare an
 * owner against, so "scope the delete to the requesting account" is not a
 * change that can be made without changing the shipped app.
 *
 * Saying that plainly matters more than appearing to fix it. An ownership
 * clause bolted on here would have to invent the owner from the record itself,
 * which always matches and protects nobody, and the code would then *look*
 * safe. `ARCHITECTURE.md` §4 names this as the exposure v8 cannot shed, and the
 * cure is users upgrading.
 *
 * ## What this does change
 *
 *  * **The id is bound.** `where id='$id'` with a POST value was injectable on
 *    an endpoint whose verb is DELETE.
 *  * **An unknown `type` deletes nothing.** The `inventories` default is gone.
 *    This is the one real behaviour change, and it only affects requests the
 *    client does not make: `listType` is always one of the six.
 *  * **A sealed account is refused.** Once somebody has signed in on 2.0 they
 *    have a client that authenticates properly, and the legacy door closes
 *    behind them. For this endpoint that is the whole of the protection, and it
 *    is the reason sealing exists.
 *  * **The row is read before it goes**, so the response can distinguish "not
 *    there" from "refused" in the log without telling the caller which.
 */
final class AppleDelete
{
    /**
     * True when the row was deleted.
     *
     * Returns false for an unknown type, a missing row, and a sealed owner.
     * The caller turns all three into the same `"error"` the old script
     * returned for a failed query: the client's only test is
     * `contains("success")`, and a v8 response is not the place to start
     * explaining which of the three it was.
     */
    public function delete(mixed $rawType, mixed $rawId): bool
    {
        $type = AppleRecordType::fromWire($rawType);
        $table = $type?->table();
        $id = is_numeric($rawId) ? (int) $rawId : 0;

        if ($table === null || $id <= 0 || $type === AppleRecordType::Sponsors) {
            return false;
        }

        $owner = DB::table($table)->where('id', $id)->value('accountid');
        if ($owner === null) {
            return false;
        }

        if (Account::query()->whereKey((int) $owner)->first()?->isSealed() === true) {
            return false;
        }

        return DB::table($table)->where('id', $id)->delete() > 0;
    }
}
