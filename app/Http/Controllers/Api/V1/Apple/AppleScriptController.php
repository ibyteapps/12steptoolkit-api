<?php

namespace App\Http\Controllers\Api\V1\Apple;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Apple\AppleDelete;
use App\Services\Apple\AppleList;
use App\Services\Apple\AppleListRequest;
use App\Services\Apple\AppleOutput;
use App\Services\Apple\AppleRecordType;
use App\Services\Newsletter\NewsletterStatus;
use App\Services\Newsletter\Sendy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Apple (v8) API.
 *
 * Pointing `apple.12stepapp.com` at this application before an endpoint
 * exists must be a clear failure rather than a quiet one, so
 * `LEGACY_V8_ENABLED=false` makes `VerifyAppleSecret` answer **410**, and a
 * script with no implementation here answers **503**.
 *
 * Neither of those is `exit(0)`. An earlier version of this comment claimed
 * the disabled case returned an empty 200 "which is exactly what
 * `8/db.php`'s `exit(0)` does today" — it does not, and conflating the two
 * would have been worse than either: an empty 200 is what a *wrong secret*
 * gets, because that is the one case where a 2021 client must see exactly
 * what it already knows how to interpret. "This host is not serving v8 yet"
 * and "your secret is wrong" are different answers and the client should be
 * able to tell them apart.
 *
 * ## What it has to do, when it is written
 *
 * Twenty-five live endpoints, four of them multiplexed:
 *
 *  * `writerecord.php` — `type` × `mode` over six tables. Note that iOS posts
 *    the nightly inventory's answers as `ans1..ans12` into the `desc1..desc12`
 *    columns, and that `type=1` overwrites the client's `tstamp` server-side.
 *  * `getlist.php` — the same six tables plus the sponsor directory and the
 *    country facets, with an extra `notifications` column for types 1, 2 and 5.
 *    Its output is hand-assembled with `echo '['`, a `json_encode` per row and
 *    `echo ','`, which produces valid JSON only by luck; the replacement
 *    produces the same bytes properly.
 *  * `getrecorddetail.php`, `deleterecord.php`, `getcounts.php`, `comment.php`,
 *    `sponsorship.php`, `accountexists.php`, and the rest.
 *
 * ## What must change on the way
 *
 *  * **Ownership.** `deleterecord.php` is `delete from $tablename where
 *    id='$id'` with no account clause at all. Every statement gets one.
 *  * **`updatefield.php`** names its own column: `UPDATE accounts set
 *    $field='$value'`. An allow-list, and `email`, both password columns and
 *    `subscribed` are not on it.
 *  * **Prepared statements.** Every value in v8 is `escape_string`'d and then
 *    interpolated into a string-built query.
 *  * **`"success " . $sql`.** Three endpoints return the executed SQL to the
 *    caller. The literal `"success "` — trailing space included — is kept
 *    because clients compare against it; the SQL is not.
 *  * **`mail_forgotpassword.php` emails the user their plaintext password.**
 *    It is not being ported. The replacement sends a one-time code.
 *  * **`login.php`, `getcounts_apple.php`, `sobrietydate.php` and
 *    `loguservisit.php` cannot run at all** — they use `mysql_*`, removed in
 *    PHP 7. The iOS email-and-password sign-in has been dead for years, which
 *    is worth knowing before anybody treats it as a requirement.
 */
class AppleScriptController extends Controller
{
    public function __construct(
        private readonly AppleList $list,
        private readonly AppleDelete $delete,
        private readonly Sendy $sendy,
    ) {}

    public function __invoke(Request $request, string $script): Response
    {
        return match ($script) {
            'reachable.php' => AppleOutput::text('success'),
            'getlist.php' => $this->getList($request),
            'deleterecord.php' => $this->deleteRecord($request),
            'getnewslettersubscribed.php' => $this->newsletterStatus($request),
            'newsletter_subscribe.php' => $this->newsletterSubscribe($request),

            // `mysql_connect` / `mysql_query`, removed in PHP 7. These four
            // have been fatalling in production for years, which is why iOS
            // email-and-password sign-in does not work and has not for a long
            // time. Reimplementing them would not restore a feature, it would
            // *introduce* one — and in `login.php`'s case a plaintext password
            // comparison. A 500 with an empty body is what a PHP fatal gives
            // the client today, so that is what they keep giving it.
            'login.php',
            'getcounts_apple.php',
            'sobrietydate.php',
            'loguservisit.php' => AppleOutput::text('', 500),

            default => $this->notBuilt($script),
        };
    }

    /**
     * `getnewslettersubscribed.php` — one of three bare words.
     *
     * The old script opens the Sendy install's own database with this
     * application's credentials and interpolates the posted address and list
     * id into a SELECT against its `subscribers` table
     * (AUDIT_AND_IMPROVEMENTS.md §1.9). This asks Sendy's API instead, and
     * only about an address that belongs to an account here — so it cannot be
     * used to ask whether a stranger's address is on an A.A. mailing list,
     * which is what the old one answers for anybody holding the shared
     * secret printed in every binary.
     *
     * The posted `list` is ignored: iOS sends `list=5`, which is a row id
     * inside Sendy's database and not something its API accepts. See
     * `config/services.sendy`.
     */
    private function newsletterStatus(Request $request): Response
    {
        $account = $this->accountForEmail($request);

        if ($account === null) {
            return AppleOutput::text(NewsletterStatus::NotSubscribed->value);
        }

        $status = $this->sendy->status((string) $account->email);

        if ($status === NewsletterStatus::Unknown) {
            // What this server knows, when Sendy cannot be asked.
            $status = (int) $account->newsletter_subscribed === 1
                ? NewsletterStatus::Subscribed
                : NewsletterStatus::NotSubscribed;
        }

        return AppleOutput::text($status->value);
    }

    /**
     * `newsletter_subscribe.php` — subscribe, and record it.
     *
     * The old script takes `sEmail` from the body and hands it to Sendy with
     * a key written into the file, so it will subscribe anybody to anything.
     * This subscribes only an address that belongs to an account here, which
     * is the difference between a member joining a list and a stranger
     * signing up somebody else.
     */
    private function newsletterSubscribe(Request $request): Response
    {
        $account = $this->accountForEmail($request);

        if ($account === null || ! $this->sendy->configured()) {
            return AppleOutput::error();
        }

        if (! $this->sendy->subscribe((string) $account->email, (string) ($account->nickname ?: ''))) {
            return AppleOutput::error();
        }

        $account->forceFill(['newsletter_subscribed' => 1])->save();

        // The old script echoes Sendy's own body, and the client only checks
        // that the request did not error before showing "check your email".
        return AppleOutput::text('1');
    }

    /**
     * The account whose address this is, or null.
     *
     * Deliberately one lookup by address and no fallback: the v8 layer
     * authenticates with a secret that is printed in every binary, so the
     * most an endpoint here may do with an address it was handed is act on
     * one that already exists.
     */
    private function accountForEmail(Request $request): ?Account
    {
        $email = trim((string) $request->input('email', $request->input('sEmail', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return Account::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->where('email', '!=', 'DELETED')
            ->first();
    }

    /**
     * `getlist.php`, all nine of it.
     */
    private function getList(Request $request): Response
    {
        $type = AppleRecordType::fromWire($request->input('type'));

        // The old file defaults `$tablename` to `inventories` and falls through
        // to an `else` that queries it for any unrecognised type, so `type=7`
        // returned somebody's Fourth Step. An empty list instead: still valid
        // JSON, still parses, and it answers a question the client never asks.
        if ($type === null) {
            return AppleOutput::rows([]);
        }

        return AppleOutput::rows(
            $this->list->rows($type, AppleListRequest::from($request)),
        );
    }

    private function deleteRecord(Request $request): Response
    {
        $ok = $this->delete->delete($request->input('type'), $request->input('id'));

        // `echo("success")` / `echo("error")`, and no trailing space on either
        // — unlike `writerecord.php`, which echoed `"success " . $sql`. The
        // client only looks for the substring, but there is no reason to send
        // bytes the old server did not.
        return AppleOutput::text($ok ? 'success' : 'error');
    }

    private function notBuilt(string $script): Response
    {
        Log::warning('v8 endpoint requested before it exists', ['script' => $script]);

        return AppleOutput::text('error', 503);
    }
}
