<?php

namespace App\Services\Accounts;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Erasing an account, the way `18/deleteaccount.php` does — and the ways it
 * does not.
 *
 * ## What the old script does
 *
 * Eleven unwrapped `UPDATE`/`DELETE` statements against `$accountid`, taken
 * from the POST body with no authentication at all, so any account id erased
 * any member. There is no transaction: a failure on statement six leaves a
 * member whose inventories are gone and whose sponsorships are not, and the
 * reply is still `error` with nothing said about how far it got.
 *
 * ## What this keeps
 *
 * The **tombstone**, which is the important part and is easy to get wrong.
 * The `accounts` row is not deleted — it is emptied. Sponsors, comments and
 * orders all point at account ids, and a deleted row would leave those
 * dangling and the app showing blanks where a name should be. So the row
 * stays, carrying nothing.
 *
 * It also keeps Apple's version of the `account_details` wipe rather than
 * Android's: the Apple script sets `accept_new_sponsees = 'false'` and the
 * Android one forgets to, which leaves an erased account advertising itself
 * in the sponsor directory.
 *
 * ## What this adds
 *
 * * **A transaction**, so it either happens or it does not.
 * * **Credentials.** The old script clears `password` but not
 *   `hashed_password`, which is what Android authenticates against — so an
 *   "erased" account could still be signed into from an Android device. It
 *   also leaves `verificationcode`. Both go, along with every API token,
 *   install secret, device row and password-reset row.
 * * **The tables written since.** Comment subscriptions, receipts, reactions
 *   and stars; reminders; icons; online-notification rows; meeting
 *   locations; `step12`. None existed when the old script was written, and
 *   all of them carry an account id.
 *
 * ## What this deliberately does not erase
 *
 * * **`reported_users`.** A report is a safety record about somebody else.
 *   Erasing reports on request would mean deleting your account wiped the
 *   reports made against you, which is the opposite of what the table is
 *   for. Reports stay; the reporter and the reported are already ids
 *   pointing at a tombstone.
 * * **`orders` and `sponsee_order_users`.** Purchase history. The old script
 *   leaves them too. The account they point at is anonymous once this has
 *   run.
 * * **Comments written by this account in somebody else's thread** —
 *   `comments.byid`. The old script deletes where `accountid` or `sponsorid`
 *   match, which covers every one-to-one sponsor thread. Group threads can
 *   hold a message whose author is this account and whose `accountid` is
 *   not, and removing those takes content out of other people's
 *   conversations. Left as the old script leaves it, and recorded here
 *   rather than decided quietly.
 */
class AccountEraser
{
    /** Tables cleared by a plain `account_id` match. */
    private const BY_ACCOUNT_ID = [
        'install_secrets', 'devices', 'password_resets', 'reminder_subscriptions',
        'comment_thread_subscribers', 'comment_thread_subscriber_history',
        'comment_reactions', 'comment_receipts', 'comment_stars',
        'icons', 'user_online_notifications', 'meeting_locations', 'sign_ins',
    ];

    /** Tables cleared by a plain `accountid` match — the older spelling. */
    private const BY_ACCOUNTID = [
        'amends', 'gratitudes', 'inventories', 'journals', 'mornings', 'nights', 'step12',
    ];

    /** Tables where either of two columns may name this account. */
    private const BY_PAIR = [
        'sponsors' => ['sponsorid', 'sponseeid'],
        'comments' => ['accountid', 'sponsorid'],
        'lastseen_requests' => ['accountid', 'forid'],
        'blocked_users' => ['blocker_id', 'blocked_id'],
    ];

    /**
     * Erase one account. Returns the row counts, per table, for the audit
     * line — never the contents of anything.
     *
     * @return array<string, int>
     */
    public function erase(Account $account): array
    {
        $id = (int) $account->getKey();
        $removed = [];

        DB::transaction(function () use ($id, &$removed): void {
            foreach (self::BY_ACCOUNTID as $table) {
                $removed[$table] = $this->wipe($table, ['accountid' => $id]);
            }

            foreach (self::BY_ACCOUNT_ID as $table) {
                $removed[$table] = $this->wipe($table, ['account_id' => $id]);
            }

            foreach (self::BY_PAIR as $table => $columns) {
                $removed[$table] = $this->wipeEither($table, $columns, $id);
            }

            $removed['reviewed'] = $this->wipe('reviewed', ['sponsorid' => $id]);

            // Sanctum's tokens are polymorphic, so they are not in the lists.
            if (Schema::hasTable('personal_access_tokens')) {
                $removed['personal_access_tokens'] = DB::table('personal_access_tokens')
                    ->where('tokenable_type', Account::class)
                    ->where('tokenable_id', $id)
                    ->delete();
            }

            $this->tombstone($id);
        });

        return array_filter($removed);
    }

    /**
     * Empty the account row and its details, keeping both rows.
     *
     * `nickname` becomes a single space rather than an empty string, which is
     * how the old `login.php` creates it — a sponsor list that reads one
     * erased account differently from another is a bug nobody will find.
     */
    private function tombstone(int $id): void
    {
        DB::table('accounts')->where('id', $id)->update([
            'email' => 'DELETED',
            'nickname' => ' ',
            'password' => '',
            'hashed_password' => '',
            'verificationcode' => '',
            'sobrietydate' => '',
            'sobrietytime' => '',
            'timestamp' => '',
            'devicetype' => 0,
            'lastlogin' => '',
            'fbid' => '',
            'googleid' => '',
            'appleid' => '',
            'fcm_token' => '',
            'ip_address' => '',
        ]);

        if (! Schema::hasTable('account_details')) {
            return;
        }

        DB::table('account_details')->where('accountid', $id)->update([
            'phonenumber' => '',
            'countrycode' => '',
            'country' => '',
            'lastseen' => 0,
            'age' => -1,
            'gender' => -1,
            'profession' => -1,
            'about' => '',
            // Apple's script sets this and Android's does not. An erased
            // account must not go on offering to sponsor people.
            'accept_new_sponsees' => 'false',
        ]);
    }

    /** @param array<string, int> $where */
    private function wipe(string $table, array $where): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $column = array_key_first($where);

        return Schema::hasColumn($table, $column)
            ? DB::table($table)->where($column, $where[$column])->delete()
            : 0;
    }

    /** @param array<int, string> $columns */
    private function wipeEither(string $table, array $columns, int $id): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $present = array_values(array_filter($columns, fn (string $c): bool => Schema::hasColumn($table, $c)));

        if ($present === []) {
            return 0;
        }

        return DB::table($table)->where(function ($query) use ($present, $id): void {
            foreach ($present as $i => $column) {
                $i === 0 ? $query->where($column, $id) : $query->orWhere($column, $id);
            }
        })->delete();
    }
}
