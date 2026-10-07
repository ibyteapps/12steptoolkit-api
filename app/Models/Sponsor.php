<?php

namespace App\Models;

use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A relationship between two people: sponsor and sponsee, or two people who
 * just chat.
 *
 * ## The statuses, which the schema comment only half documents
 *
 * `sponsors.status` is commented in the database as
 * *"0 = pending, 1 = accepted, 2 = rejected, 3 = deleted, 4 = blocked"* — and
 * the code uses two more. Traced from the Android client rather than guessed:
 * `extras/F.kt:684-687` reads 5 as a pending chat request and 6 alongside 1 as
 * an established one, `FragmentMemberProfile.kt:278` writes 5, and
 * `get_friends.php` filters on `status IN (0, 1, 5, 6)`.
 *
 * So there are two parallel request flows through one column: 0 → 1 is
 * sponsorship, 5 → 6 is chat. Which is why `friendType` comes out as 3 (chat)
 * for 5 and 6, and as sponsor or sponsee for 0 and 1 depending on which side of
 * the row you are.
 *
 * ## `rejected_tstamp` means two things
 *
 * Its column comment is *"also used to signify accepted by id if status = 0"* —
 * so while a request is pending it holds an **account id**, and once it is
 * rejected it holds a **timestamp**. Nothing here writes it that way, but
 * anything reading it has to know.
 *
 * ## What changes
 *
 * `update_sponsors.php` is `UPDATE sponsors SET status = ?, rejected_by = ?
 * WHERE id = ?` — no account clause, so anybody could accept, reject or delete
 * anybody's sponsorship by guessing a row id. Every write here is scoped to a
 * party to the relationship.
 */
class Sponsor extends Model
{
    protected $table = 'sponsors';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'modified';

    protected $guarded = ['*'];

    /** Sponsorship: requested, then accepted. */
    public const PENDING = 0;

    public const ACCEPTED = 1;

    public const REJECTED = 2;

    public const DELETED = 3;

    public const BLOCKED = 4;

    /** Chat: the same column, a separate flow. */
    public const CHAT_PENDING = 5;

    public const CHAT_ACCEPTED = 6;

    /** Statuses `get_friends.php` returns. 2, 3 and 4 are not shown to anybody. */
    public const VISIBLE = [self::PENDING, self::ACCEPTED, self::CHAT_PENDING, self::CHAT_ACCEPTED];

    /** `relationship_direction`: who asked. */
    public const SPONSOR_TO_SPONSEE = 1;

    public const SPONSEE_TO_SPONSOR = 2;

    /** Rows where this account is either party. */
    public function scopeInvolving(Builder $query, int $accountId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('sponsorid', $accountId)->orWhere('sponseeid', $accountId));
    }

    public function involves(int $accountId): bool
    {
        return (int) $this->sponsorid === $accountId || (int) $this->sponseeid === $accountId;
    }

    /** The other person. */
    public function counterpartTo(int $accountId): int
    {
        return (int) $this->sponsorid === $accountId ? (int) $this->sponseeid : (int) $this->sponsorid;
    }

    public function isChat(): bool
    {
        return in_array((int) $this->status, [self::CHAT_PENDING, self::CHAT_ACCEPTED], true);
    }

    /**
     * The projection `get_friends.php` returns.
     *
     * Everything is a string, because mysqli hands back strings and the old
     * script passes them through untouched — `'id' => $row['id']`, not
     * `(int) $row['id']`. `is_updated_online` is the literal `'1'` there and
     * means "this came from the server".
     */
    public function toLegacyRow(): array
    {
        return LegacyEnvelope::strings([
            'id' => $this->id,
            'sponsorid' => $this->sponsorid,
            'sponseeid' => $this->sponseeid,
            'status' => $this->status,
            'relationship_direction' => $this->relationship_direction,
            'requested_tstamp' => $this->requested_tstamp,
            'accepted_tstamp' => $this->accepted_tstamp,
            'rejected_tstamp' => $this->rejected_tstamp,
            'deleted_tstamp' => $this->deleted_tstamp,
            'blocked_tstamp' => $this->blocked_tstamp,
            'rejected_by' => $this->rejected_by,
            'blocked_by' => $this->blocked_by,
            'modified' => $this->getRawOriginal('modified'),
            'is_updated_online' => 1,
        ]);
    }

    /**
     * `friendType` as the old SELECT derives it, relative to the reader.
     *
     * 1 = they are my sponsor, 2 = they are my sponsee, 3 = we chat. Null for
     * rejected, deleted and blocked, because the old CASE has no ELSE.
     */
    public function friendTypeFor(int $accountId): ?int
    {
        if ($this->isChat()) {
            return 3;
        }

        if (! in_array((int) $this->status, [self::PENDING, self::ACCEPTED], true)) {
            return null;
        }

        return (int) $this->sponsorid === $accountId ? 2 : 1;
    }

    /** `friendStatus`: 0 while a request is pending, 1 once it is accepted. */
    public function friendStatusFor(): ?int
    {
        return match ((int) $this->status) {
            self::PENDING, self::CHAT_PENDING => 0,
            self::ACCEPTED, self::CHAT_ACCEPTED => 1,
            default => null,
        };
    }
}
