<?php

namespace App\Services\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gifted sponsee months.
 *
 * A sponsor buys a consumable with a `quantity` of seats and a term in
 * `months`; each seat is handed to one member by a row in
 * `sponsee_order_users`. Two tables, and between them they decide whether
 * somebody is premium.
 *
 * ## The rules this encodes
 *
 * From `docs/ENTITLEMENT_RULES.md`, which is where the reasoning lives:
 *
 *  * **R4** — the sponsee keeps the full term even if the sponsor's own
 *    subscription lapses. The gift was bought outright; it is not a seat on
 *    the sponsor's plan.
 *  * **R10** — an unassigned seat never expires, and the term starts when it
 *    is assigned. So the clock runs from `sponsee_order_users.tstamp`, never
 *    from the purchase.
 *  * **R11** — a seat is not reassignable once assigned.
 *
 * ## Months: written at purchase, never inferred at read time
 *
 * `sponsee_orders.months` is the term, and the live
 * `get_sponsee_gift_expiry.php` refuses a row with `months <= 0` outright —
 * so a row with no term grants nothing today, in production, to real people.
 * {@see accessUntil()} reads the column and nothing else, which keeps this
 * class incapable of inventing access that production does not give.
 *
 * The term is instead resolved **once, on the way in** ({@see monthsFor()}),
 * recorded on the row, and auditable afterwards.
 */
class SponseeGifts
{
    /**
     * The two consumables, and what each is worth.
     *
     * Read off the product names in the stores — "1 Year Unlock For 1
     * Sponsee" and "3 Months Unlock For 1 Sponsee" — and matched on the
     * **word**, never on a digit in the id. The digit is a version suffix:
     * `…annual_sponsee1` is a twelve-month gift, and a regular expression
     * that reads a number out of the SKU returns 1.
     */
    private const TERMS = ['annual' => 12, 'year' => 12, 'quarterly' => 3, 'three' => 3];

    /**
     * How many seats an order carries when it does not say.
     *
     * Both consumables are "for 1 Sponsee", so one. The legacy script
     * enforced the seat count only when the *request* carried a positive
     * `quantity`, which made a posted `quantity=0` an unlimited gift
     * generator against a single purchase.
     */
    private const DEFAULT_SEATS = 1;

    /** Seats bought, used and left, per SKU, for one sponsor. */
    public function purchases(int $accountId): array
    {
        if (! Schema::hasTable('sponsee_orders')) {
            return [];
        }

        return DB::table('sponsee_orders as o')
            ->leftJoin('sponsee_order_users as u', 'u.sponsee_order_id', '=', 'o.id')
            ->where('o.accountid', $accountId)
            ->groupBy('o.sku')
            ->select([
                'o.sku',
                DB::raw('SUM(o.quantity) as quantity'),
                DB::raw('COUNT(u.id) as used'),
            ])
            ->get()
            ->map(fn ($row): array => [
                'sku' => (string) $row->sku,
                'quantity' => (int) $row->quantity,
                'used' => (int) $row->used,
                'available' => max(0, (int) $row->quantity - (int) $row->used),
            ])
            ->all();
    }

    /**
     * The sponsor's oldest order of this SKU that still has a seat free.
     *
     * Oldest first so a sponsor who bought twice spends the older purchase,
     * which is what the legacy `order by id asc limit 1` does and is the
     * friendlier way round.
     */
    public function orderWithSeat(int $accountId, string $sku): ?object
    {
        if (! Schema::hasTable('sponsee_orders')) {
            return null;
        }

        return DB::table('sponsee_orders as o')
            ->leftJoin('sponsee_order_users as u', 'u.sponsee_order_id', '=', 'o.id')
            ->where('o.accountid', $accountId)
            ->where('o.sku', 'like', '%'.$sku.'%')
            ->groupBy('o.id', 'o.sku', 'o.quantity', 'o.months')
            ->havingRaw('o.quantity > COUNT(u.id)')
            ->orderBy('o.id')
            ->select(['o.id', 'o.sku', 'o.quantity', 'o.months'])
            ->first();
    }

    /**
     * Record a purchase and hand one of its seats to a member.
     *
     * Both halves in one transaction, which is the part the live script does
     * not do: it inserts the order, counts the seats taken, and inserts the
     * assignment in three separate statements, so two phones gifting at once
     * can both read "0 used" and both be given the one seat that exists.
     *
     * Idempotent twice over, because a purchase acknowledgement is exactly
     * the message a flaky connection loses: the order is keyed on
     * `(accountid, orderid)` and the assignment on
     * `(sponsee_order_id, sponseeid)`, so a client that retries after a lost
     * reply gets {@see GiftOutcome::AlreadyGifted} rather than a second row.
     *
     * @param  array{orderid: string, sku: string, tstamp: int, months: int, quantity: int, price: string}  $order
     */
    public function assign(int $sponsorId, int $sponseeId, array $order): GiftOutcome
    {
        $months = $this->monthsFor((int) $order['months'], (string) $order['sku']);

        if ($months <= 0) {
            return GiftOutcome::UnusableTerm;
        }

        $seats = (int) $order['quantity'] > 0 ? (int) $order['quantity'] : self::DEFAULT_SEATS;

        return DB::transaction(function () use ($sponsorId, $sponseeId, $order, $months, $seats): GiftOutcome {
            $existing = DB::table('sponsee_orders')
                ->where('accountid', $sponsorId)
                ->where('orderid', (string) $order['orderid'])
                ->lockForUpdate()
                ->first(['id', 'quantity']);

            if ($existing === null) {
                $orderId = (int) DB::table('sponsee_orders')->insertGetId([
                    'accountid' => $sponsorId,
                    'orderid' => (string) $order['orderid'],
                    'sku' => (string) $order['sku'],
                    'tstamp' => (int) $order['tstamp'],
                    // The resolved term, not the posted one, so that what the
                    // sponsee is owed is readable from the row for ever.
                    'months' => $months,
                    'quantity' => $seats,
                    'price' => (string) $order['price'],
                ]);
            } else {
                $orderId = (int) $existing->id;
                // The stored seat count wins over anything the request says:
                // the row is this server's record of what was bought, and a
                // client that asks for more seats than it paid for is the
                // shape of the hole this replaces.
                $seats = (int) $existing->quantity > 0 ? (int) $existing->quantity : self::DEFAULT_SEATS;
            }

            $alreadyHeld = DB::table('sponsee_order_users')
                ->where('sponsee_order_id', $orderId)
                ->where('sponseeid', $sponseeId)
                ->exists();

            if ($alreadyHeld) {
                return GiftOutcome::AlreadyGifted;
            }

            $used = DB::table('sponsee_order_users')->where('sponsee_order_id', $orderId)->count();

            if ($used >= $seats) {
                return GiftOutcome::NoSeats;
            }

            DB::table('sponsee_order_users')->insert([
                'sponsee_order_id' => $orderId,
                'sponseeid' => $sponseeId,
                // R10: the term starts now, not when the sponsor paid.
                'tstamp' => now()->getTimestamp(),
            ]);

            return GiftOutcome::Gifted;
        });
    }

    /**
     * When a gifted member's access runs out, or null if they have none.
     *
     * The latest expiry across every seat they hold, so two gifts extend
     * rather than overwrite. The live script takes the most recently
     * *assigned* active seat instead (`ORDER BY sou.id DESC LIMIT 1`), which
     * for somebody holding a twelve-month gift and a later three-month one
     * answers with the shorter of the two. This is the deliberate difference
     * recorded in `FEATURE_PARITY.md`: more generous, and never less.
     */
    public function accessUntil(int $sponseeId): ?Carbon
    {
        if (! Schema::hasTable('sponsee_orders')) {
            return null;
        }

        $seats = DB::table('sponsee_order_users as u')
            ->join('sponsee_orders as o', 'o.id', '=', 'u.sponsee_order_id')
            ->where('u.sponseeid', $sponseeId)
            ->get(['u.tstamp', 'o.months']);

        $latest = null;

        foreach ($seats as $seat) {
            $assignedAt = (int) $seat->tstamp;
            $months = (int) $seat->months;

            // Both conditions are the live endpoint's: `months > 0`, and an
            // assignment that actually happened. A row failing either grants
            // nothing today and must not start granting something now.
            if ($assignedAt <= 0 || $months <= 0) {
                continue;
            }

            $expires = Carbon::createFromTimestamp($assignedAt)->addMonths($months);

            if ($latest === null || $expires->greaterThan($latest)) {
                $latest = $expires;
            }
        }

        return $latest;
    }

    /**
     * The term for a purchase: the number given, or the one its SKU names.
     *
     * The client refuses to buy a gift whose months it cannot work out
     * (`SponseeGiftService::giftSeat`), so a zero here means either an older
     * client or a product nobody has mapped — and in both cases the fallback
     * reads the same product names the client reads. Zero out means the
     * purchase cannot be turned into access, which is a refusal and not a
     * row.
     */
    public function monthsFor(int $months, string $sku): int
    {
        if ($months > 0) {
            return $months;
        }

        $id = strtolower($sku);

        foreach (self::TERMS as $word => $term) {
            if (str_contains($id, $word)) {
                return $term;
            }
        }

        return 0;
    }
}
