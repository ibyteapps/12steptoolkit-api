<?php

namespace App\Services\Apple;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `8/getlist.php`, which is nine queries wearing one filename.
 *
 * Every one of them was built by string concatenation with `$accountid`,
 * `$sponsorid`, `$step` and — for the sponsor directory — `$_POST['country']`,
 * `$_POST['gender']` and `$_POST['age']` interpolated straight in. That last
 * group is a live SQL injection in the only endpoint that takes free text from
 * a filter form. Everything here is bound.
 *
 * ## What the account id means now
 *
 * In v8 it means nothing: `accountid` is a POST field, the only credential is a
 * secret printed in a `test.html`, and the query trusts the field. So anybody
 * holding the secret who knows an id can read that person's Fourth Step. That
 * cannot be fixed without breaking the shipped client — it is the old app's
 * design — so the account is still taken from the body, and the containment is
 * elsewhere: rate limiting, sealing an account the moment it signs in on 2.0,
 * and a short overlap. `ARCHITECTURE.md` §4 is explicit that this one is not
 * solved, only survived.
 *
 * What *is* fixed: the ownership clause is applied unconditionally rather than
 * being a property of whichever branch the type landed in, so there is no path
 * through this file that reads a table without one.
 */
final class AppleList
{
    /**
     * @return list<array<string, mixed>>
     */
    public function rows(AppleRecordType $type, AppleListRequest $request): array
    {
        $rows = match ($type) {
            AppleRecordType::SponsorDirectory => $this->directory($request),
            AppleRecordType::CountryList => $this->countries(),
            default => $this->records($type, $request),
        };

        return array_map(
            static fn (array $row): array => AppleOutput::clampIcon($row),
            $rows,
        );
    }

    /**
     * One of the seven record tables.
     *
     * @return list<array<string, mixed>>
     */
    private function records(AppleRecordType $type, AppleListRequest $request): array
    {
        $table = $type->table();
        if ($table === null) {
            return [];
        }

        $query = DB::table($table)
            ->select($type->columns())
            ->where('accountid', $request->accountId);

        $this->applyStepFilter($type, $request, $query);

        foreach ($type->order() as $column) {
            $query->orderByDesc($column);
        }

        $rows = array_map(
            static fn (object $row): array => (array) $row,
            $query->get()->all(),
        );

        return $type->hasNotifications()
            ? $this->attachNotifications($rows, $request)
            : $rows;
    }

    /**
     * The per-type narrowing, which is the part of the old file that reads as
     * arbitrary and is not.
     *
     *  * **Inventories, `step=5`** → Step *4* rows that are **not shared**
     *    (`inventoryforstep=4 and shared = 0`). Step 5 of the programme is
     *    sharing the Fourth Step with another person, so the list for it is the
     *    inventories still to share. The step number in the request is not the
     *    step number in the query, and that is correct.
     *  * **Inventories, anything else** → `inventoryforstep = step`, defaulting
     *    to 4.
     *  * **Amends, `step=9`** → amends not yet made (`amendsdone=0`). Step 8 is
     *    the list, Step 9 is making them, so Step 9's list is what is left.
     *  * everything else → no filter.
     *
     * `shared` and `amendsdone` are compared against `0` as the old SQL does.
     * Both are declared as strings in the live schema and hold `'0'`/`'1'`, so
     * a loose comparison is what the data needs — and binding the value keeps
     * MySQL doing the coercion rather than this file guessing at it.
     */
    private function applyStepFilter(
        AppleRecordType $type,
        AppleListRequest $request,
        Builder $query,
    ): void {
        if ($type === AppleRecordType::Inventories) {
            if ($request->step === 5) {
                $query->where('inventoryforstep', 4)->where('shared', 0);

                return;
            }

            $query->where('inventoryforstep', $request->step ?? 4);

            return;
        }

        if ($type === AppleRecordType::Amends && $request->step === 9) {
            $query->where('amendsdone', 0);
        }
    }

    /**
     * The `notifications` column: unread comments on each record.
     *
     * The old SQL is a correlated subquery per row inside a derived table. One
     * grouped query instead, keyed by `recordid` — same answer, one round trip
     * rather than one per record, and the N+1 was being paid on every open of
     * a list screen.
     *
     * The `byid != ?` comparand switches on who is asking: a sponsor does not
     * get a badge for their own comments, and neither does the owner
     * (`getlist.php:41-53`). `issponsor` decides which id that is.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function attachNotifications(array $rows, AppleListRequest $request): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn (array $row): ?int => isset($row['id']) ? (int) $row['id'] : null,
            $rows,
        )));

        $counts = $ids === [] ? [] : DB::table('comments')
            ->selectRaw('recordid, COUNT(*) as aggregate')
            ->where('accountid', $request->accountId)
            ->where('sponsorid', $request->sponsorId)
            ->where('seen', 0)
            ->whereIn('recordid', $ids)
            ->where('byid', '!=', $request->isSponsor ? $request->sponsorId : $request->accountId)
            ->groupBy('recordid')
            ->pluck('aggregate', 'recordid')
            ->all();

        return array_map(
            static function (array $row) use ($counts): array {
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                // The subquery produced an integer for every row, zero
                // included, so the key is always present.
                $row['notifications'] = (int) ($counts[$id] ?? 0);

                return $row;
            },
            $rows,
        );
    }

    /**
     * Type 50 — the sponsor wall.
     *
     * `account_details` joined to `accounts` where the person accepts sponsees
     * and has a country set, newest-seen first, **capped at 100** as the old
     * query caps it. Five correlated subqueries describe the viewer's existing
     * relationship with each listed person, and the client's buttons are drawn
     * from them, so all five are kept with their `COALESCE(..., -1)` default.
     *
     * The three filters were interpolated. They are bound, and each is applied
     * only when present — `isset`, as the old code has it, so an empty string
     * still filters, which is what a cleared form sends.
     *
     * @return list<array<string, mixed>>
     */
    private function directory(AppleListRequest $request): array
    {
        $viewer = $request->accountId;

        $relationship = static fn (string $select, string $where): string => "(SELECT COALESCE((SELECT $select FROM sponsors WHERE $where ORDER BY id DESC LIMIT 1), -1))";

        $query = DB::table('account_details as a')
            ->join('accounts as b', 'a.accountid', '=', 'b.id')
            ->select([
                'a.id', 'a.accountid', 'a.accept_new_sponsees', 'a.country',
                'a.countrycode', 'a.lastseen', 'b.nickname', 'b.icon',
                'b.sobrietydate',
            ])
            ->selectRaw($relationship('status', 'sponsorid = b.id AND sponseeid = ? AND status <= 1').' as sponsorstatus', [$viewer])
            ->selectRaw($relationship('id', 'sponsorid = b.id AND sponseeid = ? AND status <= 1').' as sponsorxid', [$viewer])
            ->selectRaw($relationship('status', 'sponseeid = ? AND status = 0').' as pendingrequest', [$viewer])
            ->selectRaw($relationship('status', 'sponseeid = b.id AND sponsorid = ? AND status <= 1').' as sponseestatus', [$viewer])
            ->selectRaw($relationship('id', 'sponseeid = b.id AND sponsorid = ? AND status <= 1').' as sponseexid', [$viewer])
            ->where('a.accept_new_sponsees', 'true')
            ->whereRaw('LENGTH(a.countrycode) > 0')
            ->orderByDesc('a.lastseen')
            ->limit(100);

        foreach ($request->filters as $column => $value) {
            $query->where("a.$column", $value);
        }

        return array_map(
            static fn (object $row): array => (array) $row,
            $query->get()->all(),
        );
    }

    /**
     * Type 51 — the country facets behind the wall's filter.
     *
     * `getlist.php:126`. Note `DISTINCT` beside `GROUP BY country` in the
     * original, which does nothing once the grouping is there; the grouping is
     * what produces one row per country. Kept as a plain grouped count.
     *
     * @return list<array<string, mixed>>
     */
    private function countries(): array
    {
        $rows = DB::table('account_details')
            ->selectRaw('country, countrycode, COUNT(*) as total')
            ->where('accept_new_sponsees', 'true')
            ->whereRaw('LENGTH(countrycode) > 0')
            ->groupBy('country', 'countrycode')
            ->orderByDesc('total')
            ->orderBy('country')
            ->get();

        return array_map(
            static fn (object $row): array => (array) $row,
            $rows->all(),
        );
    }
}
