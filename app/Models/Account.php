<?php

namespace App\Models;

use App\Models\Concerns\LegacyTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person, as the existing `accounts` table has them.
 *
 * This is the adopted legacy table, unchanged. What it holds and what that
 * costs is worth knowing before writing anything that touches it:
 *
 *  * **Two password columns.** `password` is plaintext and is what the iOS app
 *    still compares against; `hashed_password` is bcrypt and is what Android
 *    writes. Android's sign-in blanks the plaintext one, which silently breaks
 *    that person's iOS sign-in. `config/legacy.php` → `plaintext_password`
 *    decides whether this application repeats that or not; nothing here reads
 *    `password` for authentication under any setting.
 *  * **`email` has no unique index** and nothing in the old code enforced one,
 *    so duplicates can exist. Lookups are written to expect more than one row
 *    and to prefer the one that has actually been used.
 *  * **`nickname` is created as a single space**, not an empty string.
 *  * **`icon`** is 1–40 for a bundled avatar and above 40 an `icons.id`. The
 *    iOS readers coerce anything above 40 to 0, so an Android upload shows as
 *    "no avatar" there.
 *  * **`modified`** starts life as `'0000-00-00 00:00:00'`. See `LegacyTable`.
 *  * **`subscribed`** is the legacy entitlement flag. It is never cleared once
 *    set, by any code path in either old API, so it answers "has this person
 *    ever paid", not "is this person premium". `Entitlement` answers the second
 *    question and this column is left alone for the old apps that read it.
 *
 * @property int $id
 */
class Account extends Authenticatable
{
    use HasApiTokens;

    /**
     * The legacy `accounts` table has no `remember_token` column.
     *
     * Returning null tells Laravel's session guard not to read or write one,
     * which is what stops a "remember me" login trying to UPDATE a column
     * that does not exist. Both apps authenticate with tokens and never used
     * it either.
     */
    public function getRememberTokenName(): ?string
    {
        return null;
    }

    use HasFactory;
    use LegacyTable;

    protected $table = 'accounts';

    /** @see LegacyTable — Eloquent's own constants, pointed at the real columns. */
    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    /**
     * Writes are explicit everywhere; nothing is mass-assigned into the table
     * that holds somebody's recovery. A `$fillable` list here would be read as
     * permission by the next person.
     */
    protected $guarded = ['*'];

    protected $hidden = ['password', 'hashed_password', 'verificationcode', 'fcm_token'];

    /** Device types, as `accounts.devicetype` records them. */
    public const DEVICE_APPLE = 1;

    public const DEVICE_ANDROID = 2;

    /** `accounts.sociallogin`. */
    public const SOCIAL_EMAIL = 0;

    public const SOCIAL_FACEBOOK = 1;

    public const SOCIAL_GOOGLE = 2;

    public const SOCIAL_ANONYMOUS = 3;

    public const SOCIAL_APPLE = 4;

    protected function casts(): array
    {
        return [
            'verified' => 'int',
            'accounttype' => 'int',
            'devicetype' => 'int',
            'sociallogin' => 'int',
            'icon' => 'int',
            'hp' => 'int',
            'subscribed' => 'int',
            'newsletter_subscribed' => 'int',
            'free_upgrade' => 'int',
            'logincount' => 'int',
            'timestamp' => 'int',
            'created_timestamp' => 'int',
            'vcode_expires' => 'int',
            'last_login_tstamp' => 'int',
            'on_boarding_completed_timestamp' => 'int',
            'software_version' => 'int',
            'deletion_timestamp' => 'int',
        ];
    }

    // ------------------------------------------------------------ relations

    public function security(): HasOne
    {
        return $this->hasOne(AccountSecurity::class, 'account_id', 'id');
    }

    public function details(): HasOne
    {
        return $this->hasOne(AccountDetail::class, 'accountid', 'id');
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(Entitlement::class, 'account_id', 'id');
    }

    public function installs(): HasMany
    {
        return $this->hasMany(Install::class, 'account_id', 'id');
    }

    public function storeSubscriptions(): HasMany
    {
        return $this->hasMany(StoreSubscription::class, 'account_id', 'id');
    }

    // ------------------------------------------------------------- helpers

    /**
     * The security row, created on demand.
     *
     * Every account predates this application, so the row never exists until
     * something needs it.
     */
    public function securityRow(): AccountSecurity
    {
        $row = $this->security;
        if ($row !== null) {
            return $row;
        }

        $row = AccountSecurity::firstOrCreate(
            ['account_id' => $this->id],
            ['uuid' => (string) Str::uuid()],
        );
        $this->setRelation('security', $row);

        return $row;
    }

    /** The stable public identifier. `accounts.id` never leaves this server. */
    public function uuid(): string
    {
        return $this->securityRow()->uuid;
    }

    /**
     * True once this account has been seen on a properly authenticated client,
     * after which the Apple shared-secret path refuses to answer for it.
     */
    public function isSealed(): bool
    {
        return config('legacy.seal_on_v2_login')
            && $this->security?->v1_sealed_at !== null;
    }

    /**
     * The RevenueCat App User ID the old apps already use: the account id as a
     * string. Keeping it means an existing subscriber's purchases keep
     * following them with no identity mapping to get wrong.
     */
    public function revenueCatAppUserId(): string
    {
        return (string) $this->id;
    }

    /** Lower-cased and trimmed, which is how every legacy lookup compares it. */
    public static function normaliseEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * Finds an account by email.
     *
     * There is no unique index on the column, so duplicates are possible and
     * this returns the one most likely to be the person's real account: the one
     * that has been signed in to most recently, then the oldest.
     */
    public static function findByEmail(?string $email): ?self
    {
        $email = self::normaliseEmail($email);
        if ($email === '') {
            return null;
        }

        return static::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderByDesc('last_login_tstamp')
            ->orderBy('id')
            ->first();
    }
}
