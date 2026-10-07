<?php

namespace App\Services\Apple;

use App\Models\Amend;
use App\Models\Gratitude;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Morning;
use App\Models\Night;
use App\Models\Sponsor;

/**
 * v8's `type` parameter, which selects a table.
 *
 * `getlist.php:22-28` is a chain of `else if`s and this is that chain. The
 * numbers are not the same taxonomy as v19's item types or as Android's
 * `Keys.kt`, which is why `StepRecordType` in the Flutter client refuses to use
 * either as an identifier — see its docblock. Here they are a wire parameter
 * and nothing more.
 *
 * Two of the nine are not tables at all: 50 is the sponsor directory, a join
 * across `account_details` and `accounts` with five correlated subqueries, and
 * 51 is the country facet list. They are handled separately and are here so
 * that `fromWire` can tell a known type from an unknown one.
 */
enum AppleRecordType: int
{
    case Inventories = 1;
    case Nights = 2;
    case Journals = 3;
    case Gratitudes = 4;
    case Amends = 5;
    case Mornings = 15;
    case Sponsors = 16;
    case SponsorDirectory = 50;
    case CountryList = 51;

    public static function fromWire(mixed $raw): ?self
    {
        return is_numeric($raw) ? self::tryFrom((int) $raw) : null;
    }

    /**
     * The model, for the seven that are a table.
     *
     * `getlist.php` defaults `$tablename` to `inventories` and then falls
     * through to an `else` that queries it for **any** unrecognised type. That
     * default is not reproduced: an unknown type is an unknown type, and
     * answering it with somebody's Fourth Step because a client sent `type=7`
     * is not a behaviour worth keeping bug-for-bug.
     *
     * @return class-string|null
     */
    public function model(): ?string
    {
        return match ($this) {
            self::Inventories => Inventory::class,
            self::Nights => Night::class,
            self::Journals => Journal::class,
            self::Gratitudes => Gratitude::class,
            self::Amends => Amend::class,
            self::Mornings => Morning::class,
            self::Sponsors => Sponsor::class,
            self::SponsorDirectory, self::CountryList => null,
        };
    }

    public function table(): ?string
    {
        $model = $this->model();

        return $model === null ? null : (new $model)->getTable();
    }

    /**
     * Does the list carry the unread-comment count?
     *
     * Types 1, 2 and 5 wrap their select in
     * `select *, (SELECT COUNT(*) FROM comments …) as notifications from (…) a`.
     * 3, 4 and 15 do not — `getlist.php:67-74` is a plain `SELECT *`. The three
     * that have it are the three a sponsor comments on.
     *
     * Type 16 reaches the trailing `else`, which *does* add the subquery, so it
     * has the column too.
     */
    public function hasNotifications(): bool
    {
        return match ($this) {
            self::Inventories, self::Nights, self::Amends, self::Sponsors => true,
            default => false,
        };
    }

    /**
     * The projection, as the old SQL wrote it.
     *
     * Six of the seven are `SELECT *`. **`nights` is not**, and this is the
     * detail most likely to be missed by reading the analysis rather than the
     * script: `getlist.php:56` selects `id, timestamp, thedate, reviewed` and
     * nothing else. The nightly review's twelve answers are never in the list;
     * the client fetches them one record at a time from
     * `getrecorddetail.php`. A faithful list that returned `*` here would send
     * every user's whole nightly inventory down the wire on every open of that
     * screen.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Nights => ['id', 'timestamp', 'thedate', 'reviewed'],
            default => ['*'],
        };
    }

    /**
     * `ORDER BY`, descending, as the old SQL wrote it.
     *
     * `nights` orders by `id` alone (`getlist.php:58, 61` — `ORDER BY a.id
     * desc`), everything else by `tstamp` then `id`. Not a detail worth
     * "improving": the client renders in the order it receives.
     *
     * @return list<string>
     */
    public function order(): array
    {
        return match ($this) {
            self::Nights => ['id'],
            default => ['tstamp', 'id'],
        };
    }
}
