<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Account;
use App\Models\Entitlement;
use App\Models\Sponsor;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\GiftOutcome;
use App\Services\Billing\SponseeGifts;
use App\Services\Legacy\LegacyEnvelope;
use App\Services\Push\DeviceTokens;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The four billing endpoints the new client calls, and the two that mattered.
 *
 * `19/` has exactly four: `add_order.php`, `add_sponsee_order_and_gift.php`,
 * `get_sponsee_gift_expiry.php` and `revcat_is_subscribed.php`. The Apple
 * tree still carries the older `giftsubscription.php`,
 * `getsponseepurchases.php` and `add_orderdata_sponsee.php`, which the v19
 * pair replaced; `FEATURE_PARITY.md` maps them.
 *
 * ## What was wrong with the live ones
 *
 *  * **`giftsubscription.php` (Apple 8, Android 18)** takes `accountid` from
 *    the POST body with no authentication, so anybody could spend any
 *    sponsor's seats on anybody; interpolates `$accountid`, `$type`,
 *    `$userid` and `$tstamp` into both statements; echoes `"Running 1"`,
 *    then the raw INSERT, then `sqlsuccess`; and sends its notification with
 *    two hard-coded account ids left in from a test.
 *  * **`add_orderdata_sponsee.php`** picks its table from a posted `type`,
 *    `include`s `giftsubscription.php` mid-request, and reports failure as
 *    `"sqlerror 22222222"` followed by the SQL and the rows it found.
 *  * **`add_sponsee_order_and_gift.php` (v19)** is prepared and idempotent —
 *    much better — but still reads `accountid` from the body, trusts the
 *    posted `quantity` as the seat limit, and does the count-then-insert
 *    outside a transaction, so two phones gifting at once can both take the
 *    last seat.
 *  * **`revcat_is_subscribed.php`** has its database include commented out,
 *    which leaves it unauthenticated, and asks RevenueCat about whatever
 *    `account_id` it is handed. Account ids are sequential. See
 *    `AUDIT_AND_IMPROVEMENTS.md` §1.8.
 *
 * Here the account always comes from the signed request, the seat count comes
 * from the stored row, and the assignment is one transaction. {@see
 * SponseeGifts} holds the rules themselves.
 */
class BillingController extends Controller
{
    /** Script name to method, as the routes file iterates it. */
    public const ENDPOINTS = [
        'add_order.php' => 'recordOrder',
        'add_sponsee_order_and_gift.php' => 'giftSeat',
        'get_sponsee_gift_expiry.php' => 'giftExpiry',
        'revcat_is_subscribed.php' => 'isSubscribed',
    ];

    public function __construct(
        private readonly SponseeGifts $gifts,
        private readonly EntitlementService $entitlements,
        private readonly PushSender $push,
        private readonly DeviceTokens $tokens,
    ) {}

    /**
     * `add_order.php` — record a receipt for a subscription somebody bought
     * for themselves.
     *
     * **This grants nothing.** It writes `subscription_orders`, which is an
     * audit trail: the row is a client's word about a purchase, and a
     * client's word is not a verified receipt. Access comes from
     * {@see EntitlementService}, whose sources are a store this server
     * verified with Apple or Google, a console grant, a gift, or RevenueCat.
     * If writing here granted premium, the paywall would be a POST away.
     */
    public function recordOrder(Request $request): JsonResponse
    {
        if ($refusal = $this->callerMismatch($request)) {
            return $refusal;
        }

        $order = $this->order($request);

        if ($order === null) {
            return LegacyEnvelope::fail('Missing required fields', 400);
        }

        $accountId = (int) $this->account($request)->id;

        $existing = DB::table('subscription_orders')
            ->where('accountid', $accountId)
            ->where('orderid', $order['orderid'])
            ->value('id');

        // The live script updates the details of an order it has seen before,
        // and that is worth keeping: a client that retried after a lost reply
        // may have learnt the price or the term in between.
        if ($existing !== null) {
            DB::table('subscription_orders')->where('id', $existing)->update([
                'sku' => $order['sku'],
                'tstamp' => $order['tstamp'],
                'months' => $order['months'],
                'quantity' => $order['quantity'],
                'price' => $order['price'],
            ]);

            return LegacyEnvelope::ok(null, 'Subscription order already existed — details updated');
        }

        DB::table('subscription_orders')->insert([
            'accountid' => $accountId,
            'orderid' => $order['orderid'],
            'sku' => $order['sku'],
            'tstamp' => $order['tstamp'],
            'months' => $order['months'],
            'quantity' => $order['quantity'],
            'price' => $order['price'],
        ]);

        return LegacyEnvelope::ok(null, 'Subscription order saved');
    }

    /**
     * `add_sponsee_order_and_gift.php` — record a gift purchase and hand one
     * of its seats to a member.
     *
     * The recipient has to be somebody the caller is actually connected to —
     * a sponsor, a sponsee or a chat — because that is who the app offers
     * this for: the Upgrade card lives on a member's profile, which is
     * reached from the friends list, and `get_friends.php` returns exactly
     * `status IN (0, 1, 5, 6)`. So the check is that set, not "is an accepted
     * sponsee", which would have taken gifting to a sponsor or a friend away
     * from people who have it today.
     */
    public function giftSeat(Request $request): JsonResponse
    {
        if ($refusal = $this->callerMismatch($request)) {
            return $refusal;
        }

        $sponsor = $this->account($request);
        $sponsorId = (int) $sponsor->id;
        $sponseeId = (int) $this->firstFilled($request, ['sponsee_id', 'sponseeUserID', 'userid']);
        $order = $this->order($request);

        if ($order === null || $sponseeId <= 0) {
            return LegacyEnvelope::fail('Missing required fields', 400);
        }

        if (! $this->mayGiftTo($sponsorId, $sponseeId)) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $outcome = $this->gifts->assign($sponsorId, $sponseeId, $order);

        if (! $outcome->succeeded()) {
            return LegacyEnvelope::fail(match ($outcome) {
                GiftOutcome::NoSeats => 'No remaining quantity available for gifting on this order',
                GiftOutcome::UnusableTerm => 'This purchase does not say how many months it is worth',
                default => 'The gift could not be recorded',
            }, 409);
        }

        if ($outcome === GiftOutcome::Gifted) {
            // The entitlement row is recomputed here rather than left to the
            // sponsee's next launch, so that `accounts.subscribed` is set and
            // somebody who is handed a seat while sitting on the paywall in
            // an old build is premium by the time they pull to refresh.
            $this->entitlements->refresh($sponseeId);
            $this->tell($sponseeId);
        }

        return LegacyEnvelope::ok(
            ['outcome' => $outcome->value],
            $outcome === GiftOutcome::Gifted
                ? 'Order saved and one usage gifted to the sponsee'
                : 'Order already saved and gifted to this sponsee',
        );
    }

    /**
     * `get_sponsee_gift_expiry.php` — when this member's gifted months run
     * out.
     *
     * One of the three endpoints with no envelope: it answers a bare array,
     * `[{"tstamp": "1760000000"}]` or `[]`, and both clients parse exactly
     * that. The shape is kept; what changes is that the member is the signed
     * one rather than whoever the body named.
     */
    public function giftExpiry(Request $request): JsonResponse
    {
        if ($refusal = $this->callerMismatch($request)) {
            return $refusal;
        }

        $until = $this->gifts->accessUntil((int) $this->account($request)->id);

        if ($until === null || $until->isPast()) {
            return response()->json([]);
        }

        return response()->json([['tstamp' => (string) $until->getTimestamp()]]);
    }

    /**
     * `revcat_is_subscribed.php` — "does this server think I am subscribed?"
     *
     * The live script asks RevenueCat's REST API with a key written into the
     * file and answers about any account id it is given. This asks the
     * entitlement engine instead, about the caller and nobody else, which is
     * both narrower and better informed: it knows the store subscriptions
     * this server has verified, the console grants and the sponsee gifts as
     * well as what RevenueCat last said.
     *
     * The client treats a `false` here as meaningful only when the store has
     * already said no (`EntitlementService._refresh`), so a cold account with
     * no entitlement row is computed once rather than answered from an
     * absence.
     */
    public function isSubscribed(Request $request): JsonResponse
    {
        if ($refusal = $this->callerMismatch($request)) {
            return $refusal;
        }

        $accountId = (int) $this->account($request)->id;

        if (Entitlement::query()->whereKey($accountId)->doesntExist()) {
            $this->entitlements->refresh($accountId);
        }

        $active = $this->entitlements->isActive($accountId);

        return LegacyEnvelope::okWith(
            ['is_subscribed' => $active],
            $active,
            $active ? 'User is subscribed' : 'User is not subscribed',
        );
    }

    // ----------------------------------------------------------------- shared

    /**
     * The order fields, or null when the ones the old scripts require are
     * missing.
     *
     * Both spellings of the timestamp are read: the new client sends
     * `tstamp`, every old script reads `timestamp`.
     *
     * @return array{orderid: string, sku: string, tstamp: int, months: int, quantity: int, price: string}|null
     */
    private function order(Request $request): ?array
    {
        $order = [
            'orderid' => trim((string) $request->input('orderid', '')),
            'sku' => trim((string) $request->input('sku', '')),
            'tstamp' => (int) $this->firstFilled($request, ['tstamp', 'timestamp']),
            'months' => (int) $request->input('months', 0),
            'quantity' => (int) $request->input('quantity', 0),
            'price' => (string) $request->input('price', ''),
        ];

        if ($order['orderid'] === '' || $order['sku'] === '' || $order['tstamp'] <= 0) {
            return null;
        }

        return $order;
    }

    /**
     * The first of these fields that carries something, as a string.
     *
     * A field posted empty is not a field: `http_build_query` turns a null
     * into `tstamp=`, and an old client that sends `timestamp` may well send
     * the other one blank. Chained `input()` defaults do not catch that,
     * because an empty string is a value.
     *
     * @param  array<int, string>  $keys
     */
    private function firstFilled(Request $request, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if ($value !== null && $value !== '' && ! is_array($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Null when the body's account agrees with the token, a 403 when it does
     * not.
     *
     * {@see Controller::accountMismatch()} checks `account_id`, which is what
     * the new client sends. These four scripts are also the ones the old
     * clients post `accountid` to, and in every one of them that field means
     * the caller — so it is held to the same agreement rather than ignored.
     */
    private function callerMismatch(Request $request): ?JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $claimed = $request->input('accountid');

        if ($claimed !== null && $claimed !== '' && (int) $claimed !== (int) $this->account($request)->id) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        return null;
    }

    /** Is this somebody the caller may hand a seat to? */
    private function mayGiftTo(int $sponsorId, int $sponseeId): bool
    {
        $exists = Account::query()
            ->whereKey($sponseeId)
            // Erased accounts keep their row — `AccountEraser` writes
            // `email = 'DELETED'` — and must not be gifted months nobody can
            // use.
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', '!=', 'DELETED'))
            ->exists();

        if (! $exists) {
            return false;
        }

        // Keeping your own seat is not a flow the app offers, but it spends
        // only your own purchase and refusing it would turn a strange tap
        // into a support ticket.
        if ($sponseeId === $sponsorId) {
            return true;
        }

        return Sponsor::query()
            ->where(fn ($q) => $q
                ->where(fn ($pair) => $pair->where('sponsorid', $sponsorId)->where('sponseeid', $sponseeId))
                ->orWhere(fn ($pair) => $pair->where('sponsorid', $sponseeId)->where('sponseeid', $sponsorId)))
            ->whereIn('status', Sponsor::VISIBLE)
            ->exists();
    }

    /**
     * Tell the member a gift has landed.
     *
     * `table` is `SUBSCRIPTION_GIFTED`, which is what the live script sends
     * and what both clients know: the new one opens the paywall on a tap so
     * the person can see what they now have. This one is displayed rather
     * than silent, because the old script displayed it and a gift nobody is
     * told about is a gift nobody uses.
     *
     * A push failure is logged and swallowed. The seat is already assigned,
     * and failing the response would have the client retry a purchase that
     * went through.
     */
    private function tell(int $sponseeId): void
    {
        $tokens = $this->tokens->for($sponseeId);

        if ($tokens === []) {
            return;
        }

        try {
            $this->push->send($tokens, new PushMessage(
                title: 'Congratulations',
                body: 'You have been gifted an upgraded membership.',
                data: ['table' => 'SUBSCRIPTION_GIFTED'],
            ));
        } catch (\Throwable $e) {
            Log::warning('gift push failed', ['reason' => $e->getMessage()]);
        }
    }
}
