<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\BlockedUser;
use App\Models\Sponsor;
use App\Services\Chat\OneToOneThread;
use App\Services\Legacy\LegacyEnvelope;
use App\Services\Sponsorship\SponseeOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sponsorship and chat relationships.
 *
 * One table, `sponsors`, carrying two request flows — 0 → 1 for sponsorship and
 * 5 → 6 for chat — and a thread is created the moment either is accepted, which
 * is what makes a conversation possible. {@see Sponsor} has the status map and
 * where each value was traced from.
 *
 * ## What changes
 *
 *  * **`update_sponsors.php` has no account clause.** `UPDATE sponsors SET
 *    status = ?, rejected_by = ? WHERE id = ?` — so anybody could accept,
 *    reject or delete anybody's sponsorship by guessing a row id. Every write
 *    here is scoped to a party to the relationship.
 *  * **only the other side may accept.** The old script lets whoever calls it
 *    set any status, so the person who sent a request could accept it
 *    themselves.
 *  * **a blocked pair cannot open a relationship.** Nothing stopped it before.
 *  * **the thread is created once.** `send_chat_request.php` looks for an
 *    existing thread first but `update_sponsors.php`'s acceptance path does not
 *    consistently, so accepting twice could leave two threads for one pair and
 *    the messages split between them.
 */
class SponsorController extends Controller
{
    public const ENDPOINTS = [
        'get_friends.php' => 'friends',
        'get_one_friend.php' => 'oneFriend',
        'fetch_sponsor_ids.php' => 'ids',
        'send_chat_request.php' => 'requestChat',
        'update_sponsors.php' => 'update',
        'check_if_has_sponsor_or_old_device.php' => 'hasSponsor',
        'get_sponsee_steps_data.php' => 'stepsData',
    ];

    // ---------------------------------------------------------------- reads

    /** `get_friends.php` — the relationships, and who the other people are. */
    public function friends(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $me = (int) $this->account($request)->id;

        $rows = Sponsor::query()->involving($me)->orderByDesc('id')->get();

        return LegacyEnvelope::ok([
            // Every row, including rejected and deleted ones: the client keeps
            // them to know not to ask again.
            'friends' => $rows->map(fn (Sponsor $s) => $s->toLegacyRow())->all(),
            // Details only for the relationships that are live.
            'friends_details' => $this->details($me, $rows->filter(
                fn (Sponsor $s) => in_array((int) $s->status, Sponsor::VISIBLE, true)
            )),
        ], 'Fetched '.$rows->count().' relationship(s)');
    }

    /**
     * `get_sponsee_steps_data.php` — a sponsee's Step work in numbers.
     *
     * The one endpoint in this family that answers about somebody else's
     * records, which is why it answers in counts and never in content.
     *
     * The live script takes `account_id` and `sponsor_id` from the body and
     * checks neither against the caller, so any signed-in person can read any
     * member's overview — how long they have been sober, how many amends they
     * still owe — by posting an id. Here the sponsor is the signed account,
     * and there has to be an accepted sponsorship to the member asked about.
     * Somebody asking about themselves is allowed: it is their own data.
     */
    public function stepsData(Request $request): JsonResponse
    {
        /*
         | No `accountMismatch` here, and it is the one endpoint where that
         | would be wrong: `account_id` on this script means the **sponsee**
         | being asked about, not the caller. The caller is the token, as
         | everywhere, and `sponsor_id` is ignored — the live script counts
         | comments from whatever `sponsor_id` it is handed, which reads
         | somebody else's conversation counts.
         */
        $me = (int) $this->account($request)->id;
        $sponseeId = (int) $request->input('account_id', $request->input('sponsee_id', 0));

        if ($sponseeId <= 0) {
            return LegacyEnvelope::fail('Invalid account ID', 400, []);
        }

        if ($sponseeId !== $me) {
            $sponsors = Sponsor::query()
                ->where('sponsorid', $me)
                ->where('sponseeid', $sponseeId)
                ->where('status', Sponsor::ACCEPTED)
                ->exists();

            if (! $sponsors) {
                return LegacyEnvelope::fail('Forbidden', 403, []);
            }
        }

        return LegacyEnvelope::ok(
            app(SponseeOverview::class)->for($sponseeId, $me),
            'Overview fetched successfully',
        );
    }

    /** `get_one_friend.php` — one person, if there is a relationship to justify it. */
    public function oneFriend(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $me = (int) $this->account($request)->id;
        $friendId = (int) $request->input('friend_id', 0);

        $relationship = Sponsor::query()
            ->involving($me)
            ->where(fn ($q) => $q->where('sponsorid', $friendId)->orWhere('sponseeid', $friendId))
            ->whereIn('status', Sponsor::VISIBLE)
            ->first();

        // The old script takes both ids from the body and returns the profile to
        // anybody holding the shared secret. A profile is only visible to
        // somebody you have a live relationship with.
        if ($relationship === null) {
            return LegacyEnvelope::fail('Not found', 404);
        }

        $details = $this->details($me, collect([$relationship]));

        return LegacyEnvelope::ok($details[0] ?? null, 'Fetched');
    }

    /** `fetch_sponsor_ids.php` — the row ids, for the client's reconciliation. */
    public function ids(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        return LegacyEnvelope::ok(
            Sponsor::query()->involving((int) $this->account($request)->id)->orderBy('id')->pluck('id')
                ->map(fn ($id) => (int) $id)->all(),
            'Matching sponsor record(s) found',
        );
    }

    /**
     * `check_if_has_sponsor_or_old_device.php` — does this person have a sponsor,
     * and are they on a build old enough to matter?
     */
    public function hasSponsor(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);

        return LegacyEnvelope::ok([
            'has_sponsor' => Sponsor::query()
                ->where('sponseeid', $account->id)
                ->where('status', Sponsor::ACCEPTED)
                ->exists(),
            'software_version' => (int) $account->software_version,
        ], 'ok');
    }

    // --------------------------------------------------------------- writes

    /**
     * `send_chat_request.php` — a chat request, and the thread it will use.
     *
     * Status 5, direction 1, a thread, and both people subscribed to it. The
     * thread exists before the request is accepted because the old client shows
     * the conversation immediately; `CommentVisibility` is what stops either
     * side reading anything they should not.
     */
    public function requestChat(Request $request): JsonResponse
    {
        $me = (int) $this->account($request)->id;
        $sponsorId = (int) $request->input('sponsor_id', 0);
        $sponseeId = (int) $request->input('sponsee_id', 0);

        // One of the two has to be the caller. The old script takes both from
        // the body and would happily create a relationship between two
        // strangers on somebody else's behalf.
        if ($me !== $sponsorId && $me !== $sponseeId) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        if ($sponsorId <= 0 || $sponseeId <= 0 || $sponsorId === $sponseeId) {
            return LegacyEnvelope::fail('Invalid sponsor or sponsee ID', 400);
        }

        if (BlockedUser::query()->between($sponsorId, $sponseeId)->exists()) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        return DB::transaction(function () use ($sponsorId, $sponseeId): JsonResponse {
            $existing = Sponsor::query()
                ->where(fn ($q) => $q->where('sponsorid', $sponsorId)->where('sponseeid', $sponseeId))
                ->orWhere(fn ($q) => $q->where('sponsorid', $sponseeId)->where('sponseeid', $sponsorId))
                ->whereIn('status', Sponsor::VISIBLE)
                ->first();

            if ($existing !== null) {
                // Already asked, or already connected. Hand back the thread
                // rather than making a second relationship.
                return LegacyEnvelope::ok(
                    ['thread_id' => $this->threadFor($sponsorId, $sponseeId)],
                    'Sponsor request sent',
                );
            }

            $relationship = new Sponsor;
            $relationship->forceFill([
                'sponsorid' => $sponsorId,
                'sponseeid' => $sponseeId,
                'status' => Sponsor::CHAT_PENDING,
                'relationship_direction' => Sponsor::SPONSOR_TO_SPONSEE,
                'requested_tstamp' => time(),
            ])->save();

            return LegacyEnvelope::ok(
                ['thread_id' => $this->threadFor($sponsorId, $sponseeId)],
                'Sponsor request sent',
            );
        });
    }

    /**
     * `update_sponsors.php` — accept, reject, delete or block.
     *
     * The rules the old script does not have:
     *
     *  * you must be a party to the relationship;
     *  * **only the other side may accept.** Otherwise whoever sent a request
     *    could accept it themselves, which is the whole of the request;
     *  * rejecting and blocking record who did it, which `rejected_by` and
     *    `blocked_by` exist for and the old script only sometimes filled in.
     */
    public function update(Request $request): JsonResponse
    {
        $me = (int) $this->account($request)->id;
        $id = (int) $request->input('local_id', $request->input('id', 0));
        $status = (int) $request->input('status', -1);

        $relationship = $id > 0 ? Sponsor::query()->find($id) : null;

        if ($relationship === null) {
            return LegacyEnvelope::fail('Not found', 404);
        }

        // The clause the old script has not got.
        if (! $relationship->involves($me)) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        if (! in_array($status, [
            Sponsor::ACCEPTED, Sponsor::REJECTED, Sponsor::DELETED,
            Sponsor::BLOCKED, Sponsor::CHAT_ACCEPTED,
        ], true)) {
            return LegacyEnvelope::fail('Invalid status', 400);
        }

        $accepting = in_array($status, [Sponsor::ACCEPTED, Sponsor::CHAT_ACCEPTED], true);

        if ($accepting && ! $this->mayAccept($relationship, $me)) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        $changes = ['status' => $status];

        match ($status) {
            Sponsor::ACCEPTED, Sponsor::CHAT_ACCEPTED => $changes['accepted_tstamp'] = time(),
            Sponsor::REJECTED => [$changes['rejected_tstamp'] = time(), $changes['rejected_by'] = $me],
            Sponsor::DELETED => $changes['deleted_tstamp'] = time(),
            Sponsor::BLOCKED => [$changes['blocked_tstamp'] = time(), $changes['blocked_by'] = $me],
            default => null,
        };

        return DB::transaction(function () use ($relationship, $changes, $accepting): JsonResponse {
            $relationship->forceFill($changes)->save();

            $threadId = $accepting
                ? $this->threadFor((int) $relationship->sponsorid, (int) $relationship->sponseeid)
                : 0;

            return LegacyEnvelope::ok(
                ['local_id' => (int) $relationship->id, 'thread_id' => $threadId],
                'Updated status to '.$relationship->status,
            );
        });
    }

    // ------------------------------------------------------------- plumbing

    /**
     * Only the person who did **not** ask may accept.
     *
     * `relationship_direction` says which way the request went: 1 is
     * sponsor → sponsee, so the sponsee accepts; 2 is the reverse.
     */
    private function mayAccept(Sponsor $relationship, int $me): bool
    {
        $asker = (int) $relationship->relationship_direction === Sponsor::SPONSEE_TO_SPONSOR
            ? (int) $relationship->sponseeid
            : (int) $relationship->sponsorid;

        return $me !== $asker;
    }

    /**
     * The one-to-one thread for a pair, created if it is not there.
     *
     * The logic moved to {@see OneToOneThread} when the iOS back-fill needed
     * the same find-or-create. Behaviour here is unchanged: a thread dated
     * now, with neither subscriber marked admin.
     */
    private function threadFor(int $a, int $b): int
    {
        return app(OneToOneThread::class)->for($a, $b);
    }

    /**
     * The counterparties' profiles, with `friendType` and `friendStatus`
     * derived relative to the reader exactly as the old SELECT's CASE does.
     *
     * `account_details` is joined to `accounts` for the nickname, icon and
     * sobriety date. No email address, and no password column of either kind.
     *
     * @param  Collection<int, Sponsor>  $relationships
     */
    private function details(int $me, Collection $relationships): array
    {
        $byCounterpart = [];
        foreach ($relationships as $relationship) {
            $byCounterpart[$relationship->counterpartTo($me)] = $relationship;
        }

        if ($byCounterpart === []) {
            return [];
        }

        $rows = DB::table('account_details as ad')
            ->join('accounts as a', 'a.id', '=', 'ad.accountid')
            ->whereIn('ad.accountid', array_keys($byCounterpart))
            ->get([
                'ad.id', 'ad.accountid', 'ad.countrycode', 'ad.country', 'ad.lastseen',
                'ad.age', 'ad.gender', 'ad.profession', 'ad.about', 'ad.modified',
                'a.nickname', 'a.icon', 'a.sobrietydate', 'a.sobrietytime',
                'a.software_version', 'a.devicetype',
            ]);

        return $rows->map(function ($row) use ($byCounterpart, $me) {
            $relationship = $byCounterpart[(int) $row->accountid];

            return LegacyEnvelope::strings([
                'id' => $row->id,
                'accountid' => $row->accountid,
                'nickname' => $row->nickname,
                'icon' => $row->icon,
                'countrycode' => $row->countrycode,
                'country' => $row->country,
                'lastseen' => $row->lastseen,
                'age' => $row->age,
                'gender' => $row->gender,
                'profession' => $row->profession,
                'about' => $row->about,
                'modified' => $row->modified,
                'sobrietydate' => $row->sobrietydate,
                'sobrietytime' => $row->sobrietytime,
                'software_version' => $row->software_version,
                'devicetype' => $row->devicetype,
            ]) + [
                // Left as ints: the old CASE produces them and they can be null,
                // which `strings()` would turn into ''.
                'friendType' => $relationship->friendTypeFor($me),
                'friendStatus' => $relationship->friendStatusFor(),
            ];
        })->values()->all();
    }
}
