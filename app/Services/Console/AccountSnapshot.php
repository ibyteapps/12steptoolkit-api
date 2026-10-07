<?php

namespace App\Services\Console;

use App\Models\Account;
use App\Models\ComplimentaryGrant;
use App\Models\Install;
use App\Models\StoreSubscription;
use App\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the console may know about one account.
 *
 * ## The boundary, and why it is a class rather than a convention
 *
 * The owner's decision of 2026-10-07: **counts and dates and sync state, never
 * content.** No inventory, no amend, no journal line, no chat message and no
 * note is readable from the console, by anybody, at any permission level.
 *
 * A convention would be "don't select content columns". That lasts until
 * somebody adds a page in a hurry. So this class is the *only* thing the console
 * uses to look at an account, and it is written so that selecting a content
 * column would have to be a deliberate act: every query here is a `count()` or a
 * `max()` on a timestamp. There is no `select *` in this file and no method that
 * returns a model from one of the six step-work tables.
 *
 * What a support person gets is enough to answer the questions they are actually
 * asked — "is my backup working", "why have I lost premium", "I paid twice", "I
 * deleted my account and it's still here" — and nothing else.
 *
 * `COLLECTIONS` is the one list to extend when a seventh collection appears, and
 * the test suite asserts that the shape of what comes back has not grown a
 * content column.
 */
class AccountSnapshot
{
    /** table => the label the console shows, and the column its recency is read from. */
    public const COLLECTIONS = [
        'inventories' => ['Inventories', 'tstamp'],
        'amends' => ['Amends', 'tstamp'],
        'nights' => ['Nightly reviews', 'tstamp'],
        'mornings' => ['Mornings', 'tstamp'],
        'journals' => ['Journal entries', 'tstamp'],
        'gratitudes' => ['Gratitude lists', 'tstamp'],
    ];

    /**
     * @return array{
     *     account: array<string, mixed>,
     *     work: list<array{label: string, count: int, last: ?Carbon}>,
     *     totals: array{records: int, last: ?Carbon},
     *     entitlement: array<string, mixed>,
     *     subscriptions: Collection,
     *     grants: Collection,
     *     installs: Collection,
     *     tickets: Collection,
     * }
     */
    public function for(Account $account): array
    {
        $work = $this->work((int) $account->id);

        return [
            'account' => $this->identity($account),
            'work' => $work,
            'totals' => [
                'records' => array_sum(array_column($work, 'count')),
                'last' => $this->latest(array_column($work, 'last')),
            ],
            'entitlement' => $this->entitlement($account),
            'subscriptions' => $this->subscriptions((int) $account->id),
            'grants' => $this->grants((int) $account->id),
            'installs' => $this->installs((int) $account->id),
            'tickets' => $this->tickets((int) $account->id),
        ];
    }

    // ----------------------------------------------------------------- parts

    /**
     * Who this is, in the fewest fields that let a support person be sure they
     * have the right person.
     *
     * The nickname is shown because it is how the person refers to themselves in
     * a support email. The sobriety date is shown because "my sobriety date is
     * wrong" is a real ticket and the whole app is built around that one date.
     * Neither password column is read, the FCM token is not read, and the
     * verification code is not read.
     */
    private function identity(Account $account): array
    {
        $details = $account->details;

        return [
            'id' => (int) $account->id,
            'uuid' => $account->uuid(),
            'nickname' => trim((string) $account->nickname) ?: '—',
            'email' => (string) $account->email ?: '—',
            'sign_in' => match ((int) $account->sociallogin) {
                Account::SOCIAL_FACEBOOK => 'Facebook',
                Account::SOCIAL_GOOGLE => 'Google',
                Account::SOCIAL_APPLE => 'Apple',
                Account::SOCIAL_ANONYMOUS => 'Anonymous',
                default => (string) $account->email !== '' ? 'Email and password' : 'Anonymous',
            },
            'platform' => match ((int) $account->devicetype) {
                Account::DEVICE_APPLE => 'iOS',
                Account::DEVICE_ANDROID => 'Android',
                default => 'unknown',
            },
            'verified' => (int) $account->verified === 1,
            'sobriety_date' => (string) $account->sobrietydate ?: null,
            'created' => $account->legacyDate('created'),
            'last_seen' => $account->last_login_tstamp > 0
                ? Carbon::createFromTimestamp($account->last_login_tstamp)
                : null,
            'sign_in_count' => (int) $account->logincount,
            'sealed' => $account->isSealed(),
            'sealed_at' => $account->security?->v1_sealed_at,
            // The old flag, shown as what it is rather than as "premium": it is
            // never cleared, so it means "has paid at some point".
            'ever_paid_flag' => (int) $account->getRawOriginal('subscribed') === 1,
            'free_upgrade_flag' => (int) $account->free_upgrade === 1,
            'deletion_requested' => $account->deletion_timestamp > 0
                ? Carbon::createFromTimestamp($account->deletion_timestamp)
                : null,
            'timezone' => (string) ($details->timezone ?? '') ?: null,
            'country' => (string) ($details->country ?? '') ?: null,
        ];
    }

    /**
     * How much step work there is, and when it was last touched.
     *
     * This is the answer to "is my backup working", which is the single most
     * common support question a recovery app gets — and the one the old system
     * could not answer at all, because the dirty-flag bug meant a free user's
     * writing was never uploaded and nobody could see that from the outside.
     *
     * `count()` and `max(tstamp)`. Nothing else.
     *
     * @return list<array{table: string, label: string, count: int, last: ?Carbon}>
     */
    private function work(int $accountId): array
    {
        $out = [];

        foreach (self::COLLECTIONS as $table => [$label, $recency]) {
            $row = DB::table($table)
                ->where('accountid', $accountId)
                ->selectRaw("COUNT(*) AS c, MAX({$recency}) AS last")
                ->first();

            $last = (int) ($row->last ?? 0);

            $out[] = [
                'table' => $table,
                'label' => $label,
                'count' => (int) ($row->c ?? 0),
                'last' => $last > 0 ? Carbon::createFromTimestamp($last) : null,
            ];
        }

        return $out;
    }

    private function entitlement(Account $account): array
    {
        $row = $account->entitlement;

        if ($row === null) {
            return ['is_active' => false, 'state' => 'none', 'source' => null, 'expires_at' => null, 'will_renew' => false, 'synced_at' => null];
        }

        return [
            'is_active' => (bool) $row->is_active,
            'state' => (string) $row->state,
            'source' => $row->source,
            'product_id' => $row->product_id,
            'cycle' => $row->cycle,
            'will_renew' => (bool) $row->will_renew,
            'expires_at' => $row->expires_at,
            'grace_period_expires_at' => $row->grace_period_expires_at,
            'synced_at' => $row->synced_at,
            // Whether RevenueCat has an opinion, not what it is.
            'revenuecat_active' => is_array($row->revenuecat) ? (bool) ($row->revenuecat['is_active'] ?? false) : null,
        ];
    }

    private function subscriptions(int $accountId): Collection
    {
        return StoreSubscription::query()
            ->where('account_id', $accountId)
            ->orderByDesc('purchased_at')
            ->get()
            ->map(fn (StoreSubscription $s) => [
                'id' => $s->id,
                'store' => $s->store,
                'product_id' => $s->product_id,
                'cycle' => $s->cycle,
                'status' => $s->status,
                'unmapped' => $s->isUnmapped(),
                'sandbox' => (bool) $s->is_sandbox,
                'will_renew' => (bool) $s->will_renew,
                'purchased_at' => $s->purchased_at,
                'access_until' => $s->accessUntil(),
                'refunded_at' => $s->refunded_at,
                'grants_access' => $s->grantsAccess(),
                'paid' => $s->orders()->sum('gbp_milli'),
            ]);
    }

    private function grants(int $accountId): Collection
    {
        return ComplimentaryGrant::query()
            ->with('grantedBy:id,name')
            ->where('account_id', $accountId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ComplimentaryGrant $g) => [
                'id' => $g->id,
                'period' => ComplimentaryGrant::PERIODS[$g->period] ?? $g->period,
                // A reason is written by staff about staff's own decision, so it
                // is not the member's content and is safe to show here.
                'reason' => $g->reason,
                'by' => $g->grantedBy?->name ?? 'unknown',
                'starts_at' => $g->starts_at,
                'ends_at' => $g->ends_at,
                'revoked_at' => $g->revoked_at,
                'active' => $g->grantsAccess(),
            ]);
    }

    private function installs(int $accountId): Collection
    {
        return Install::query()
            ->where('account_id', $accountId)
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get()
            ->map(fn (Install $i) => [
                'platform' => $i->platform,
                'app_version' => $i->app_version,
                'os_version' => $i->os_version,
                'last_seen_at' => $i->last_seen_at,
                'push' => $i->push_token !== null && $i->push_token !== '',
            ]);
    }

    private function tickets(int $accountId): Collection
    {
        return SupportTicket::query()
            ->where('account_id', $accountId)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'uuid', 'subject', 'state', 'category', 'last_member_at', 'created_at']);
    }

    /** @param  list<?Carbon>  $dates */
    private function latest(array $dates): ?Carbon
    {
        $dates = array_filter($dates);

        return $dates === [] ? null : max($dates);
    }
}
