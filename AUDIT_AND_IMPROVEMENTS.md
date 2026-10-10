# Audit and improvements

What was found in the legacy system while porting it, what was done about it,
and what is still open. Written as findings rather than as a narrative,
because most of it is the sort of thing that is only ever read when somebody
is looking for one answer.

Every finding below was confirmed by reading the live source on the server or
the copies under `~/Work/Scripts` and `~/Work/Websites`, not inferred. Nothing
here was tested against live data.

---

## 1. Security

### 1.1 Unauthenticated record deletion — **live, both APIs**

`aa/web/1/deleterecord.php`, and `android/18/deleterecord.php`, and the Apple
equivalents:

```php
$id   = $_POST["id"];
$type = $_POST["type"];
$sql  = "delete from $tablename where id=$id";
```

There is no account in the `WHERE` clause, no session and no token. A POST
naming a row id and a type deletes that row — a journal, an inventory, a
nightly inventory, a gratitude list, an amends entry — whoever it belongs to.
Record ids are sequential integers.

The same file has a second branch:

```php
if ($mode == 1) {
    $records = $_POST["records"] ?? " id <= 0";
    $sql = "delete from $tablename where $records";
}
```

`$records` is concatenated into the statement and executed through `query()`,
not a prepared statement. One request with `mode=1` and a condition that is
always true empties the table.

The web copies send `Access-Control-Allow-Origin: *`, so any page in any
browser can call them.

**Status:** not fixed on the live servers — these are the legacy files and
this project does not deploy to them. v19 has no equivalent endpoint:
deletion there goes through the `*_add_update.php` scripts with
`is_deleted=1` and is scoped with `ownedBy()`. The exposure ends when `/18`,
`/7` and `aa/web/1` come off the server, which is the point of the port.

### 1.2 No authorisation at all on the web API

None of the 38 files in `aa/web/1` reads `random_device_token` — the value
the web client stores at sign-in and sends with every request. Authorisation
is whatever `accountid` the caller puts in the body:

```php
$accountid = $input["accountid"] ?? $_POST['accountid'];
$sql = "SELECT * FROM $tablename where accountid='$accountid' ...";
```

So `getlist_working.php` returns any member's inventories, journals, nights
and amends to anyone who asks, and the same value is interpolated into SQL.

**Status:** replaced. `/my` takes the account from a signed session cookie
and every query goes through `RecordTypes::query()`, which cannot be called
without one.

### 1.3 Unauthenticated account erasure

`18/deleteaccount.php` and the Apple copy read `accountid` from the POST body
with no authentication and erase that member: journals, inventories,
gratitudes, mornings, nights, amends, comments, sponsorships.

**Status:** replaced by `19/delete_account.php`, which has no parameter that
names whose account goes. See §2.3 for the three defects in the old one that
were fixed rather than carried over.

### 1.4 `reviewed.php` — any record, any member

```php
$sql = "UPDATE $tablename SET reviewed=$reviewed WHERE id='$id'";
```

No account, no check that the caller sponsors anybody, and `$reviewed`
interpolated into an UPDATE.

There are two live versions. `19/mark_as_reviewed.php` is the rewrite that
shipped — prepared statements, a fixed `item_type` map, a push to the member
— and it keeps the part that matters: `UPDATE … WHERE id = ? LIMIT 1`, with
no account clause. It also answers an unscoped `SELECT COUNT(*) WHERE id = ?`
to tell "already reviewed" from "no such record", which is a record-existence
oracle for anybody holding an id.

**Status:** replaced. This application answers `mark_as_reviewed.php` — the
same name, fields and envelope-less `{success: 0|1}` body, because that is
what the client parses — and adds that the record must belong to the member
named and that there must be an accepted sponsorship from the caller to them.
The existence question is asked through the same ownership scope, so it can
only ever be answered about a sponsee the caller already sponsors.

### 1.5 The iOS back-fill was public

`check_threads_connections_with_another_account.php` declares

```php
define('REQUIRE_AUTH', false);
define('REQUIRE_HMAC', false);
```

and takes `account_id` from the query string. Anyone could run a back-fill
against anyone: creating threads for them and re-pointing their comments.

**Status:** replaced by a signed v19 endpoint.

### 1.6 A Firebase admin key in the repository, and nearly in public

`server/app/validate/aa-12-step-toolkit-firebase-adminsdk-*.json` is a
service-account key with admin scope. It is committed to the
`12steptoolkit-website` repository's history, and it sits in a directory the
web server serves. The old `PLESK.md` documents

```
curl -sI https://12steptoolkit.com/app/validate/composer.json
```

as a smoke test, which implies that directory hands out files by name.

**Checked on 9 October 2026: that URL returns 404**, so the directory is not
listing or serving its files today. The key is still in git history.

**Still open:** rotate the key, and purge it from the history. The nginx
config in `docs/nginx/` denies `.json`, `.lock`, `.md` and `.original` under
`/app/` for the day the document root moves.

### 1.7 `display_errors` on in production

Every file in `aa/web/1` begins with `ini_set('display_errors', '1')`, so a
query failure returns the statement and the path.

**Still open** on the legacy servers; ends with them.

### 1.8 `revcat_is_subscribed.php` — a subscriber list, one id at a time

Two things in one file, and it is the combination that matters:

```php
//require __DIR__ . '/db.php';
$REVENUECAT_API_KEY = 'goog_…';
$app_user_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
```

`db.php` is what turns the JWT and the HMAC on (`19/db.php:5-6`), and the
include is commented out — so the script is unauthenticated. It then asks
RevenueCat about whatever `account_id` the body carries, and `app_user_id` is
`accounts.id`, which is sequential. The reply names the entitlement and its
expiry date. So anybody who can reach the URL can walk the ids and read who
is subscribed and until when.

On the key itself: the `goog_` prefix is RevenueCat's **public SDK key**, not
a secret REST key (`sk_…`), and a public key ships inside every installed
Android binary — so writing it into this file did not expose anything that
was not already public. Worth confirming in the RevenueCat dashboard rather
than taking from the prefix. What is genuinely exposed is the *endpoint*.

**Fixed here.** Nothing in this application hard-codes a key —
`config/billing.php` reads `REVENUECAT_SECRET_KEY` from the environment — and
the replacement, `BillingController::isSubscribed`, is signed, answers about
the caller and nobody else, and answers from this server's own entitlement
engine rather than by proxying RevenueCat on a request path.

**Still open** on the legacy server: the script stays reachable until `/19`
is retired. If the Play configuration is being touched for the Firebase key
in §1.6, rotate this one in the same sitting.

### 1.9 The newsletter scripts: a key, an injection and a list anybody can fill

Three problems across two files, found while working out what to do with
them.

**`8/newsletter_subscribe.php`** carries a Sendy API key and a list id in the
file, and takes the address to subscribe straight from the POST with no
authentication:

```php
$sEmail = $_POST["sEmail"] ?? '';
'api_key' => 'anU98jr1pVI0QS8NrCfN',
```

So anybody can add any address to the mailing list — the usual use for that
is to point it at somebody else's inbox — and the key is one a Sendy install
accepts for its whole API.

**`8/getnewslettersubscribed.php`** opens a **second database**
(`data_sendy2`, the Sendy install's own) with the same credentials and builds
this:

```php
$sql = "select * from subscribers where email = '$email' and list = $list";
```

`$list` is not quoted, so it is an unescaped integer context in a SELECT
against the subscriber table of a mailing list that is not even this
application's. It is also an oracle: POST an address, learn whether it is
subscribed to an A.A. mailing list. The only authentication in front of it is
the v8 shared secret, which is printed in every App Store binary.

**Not reproduced here.** Nothing in this application holds a Sendy key —
`config/services.sendy` reads one from the environment — nothing opens a
second database, and the v19 subscribe path takes the address from the signed
account rather than from the body, so it can only ever subscribe the person
asking.

**Still open** on the legacy server: rotate the Sendy API key, and retire both
scripts with the rest of `/8`.

---

## 2. Defects fixed in the port

These are cases where copying the old behaviour faithfully would have carried
a bug across, so it was not copied. Each one is pinned by a test.

### 2.1 `hashed_password` survived account deletion

`deleteaccount.php` clears `password` and not `hashed_password`. `password`
is the plaintext column the iOS app compares against; `hashed_password` is
the bcrypt one Android uses. So an "erased" account could still be signed
into from an Android device. `verificationcode` was left set too. Both are
cleared now, along with every token, install secret, device row and
password-reset row.

### 2.2 `accept_new_sponsees` — Apple and Android disagree

Apple's `deleteaccount.php` sets `accept_new_sponsees = 'false'` and
Android's does not, which leaves an erased account advertising itself in the
sponsor directory. The port takes Apple's behaviour.

### 2.3 No transaction around an eleven-statement erasure

A failure on statement six left a member whose inventories were gone and
whose sponsorships were not, and the reply was `error` with nothing said
about how far it got. The port runs the lot in one transaction.

### 2.4 `comment_threads.created` read as null, always

`LegacyTable` casts a `created` column through `legacyDate()`, which is right
for the tables where it holds `2021-04-02 11:30:00` and wrong for
`comment_threads`, where it is a unix epoch. `Carbon::parse('1577836800')`
throws, the accessor swallows it, and `$thread->created` comes back null
however old the thread is. Nothing read it, so nothing was visibly broken.
`CommentThread` now returns the integer.

### 2.5 The website's metadata was quietly rewritten

The first cut of the website port changed the `<title>` on 59 of the 169
indexed pages — all 52 blog posts lost the ` | 12 Step Toolkit` suffix — and
rewrote eight descriptions, several of them to something less specific than
what ranks today. Eight reading pages also lost their `Book` structured-data
node. All restored, and
`tests/Feature/Site/fixtures/indexed-meta.php` now pins the title and
description of every indexed URL against the production build.

### 2.6 `{{ $title }}` double-escaping

A title attribute written with `&amp;` reaches the browser tab — and the
search result — as `&amp;amp;`. It happened on the home page and again on the
literature hub. A test now asserts no page double-escapes an entity in its
head.

### 2.7 Pushes the clients could not route — introduced here, not inherited

This one was not carried across from the old code; it was written into the
port and found while building the gift notification, and it is recorded here
because every push this server sent was being thrown away by both apps.

Both clients route on `data['table']`: the new one by `PushRouter.knownTables`,
which answers `IgnoreLink('unknown_table')` for a value it does not know and
`empty_payload` when the key is absent; the old one by the same string in its
FCM service. Every live v19 script sends it — `['table' => 'COMMENTS']`,
`['table' => 'SUBSCRIPTION_GIFTED']`, `['table' => 'REMINDER']`. The reminder
sender and the comment notifier here sent `['type' => …]` instead, which both
apps ignore.

The same work settled what a reminder push may contain: it is now **data-only**,
with no FCM `notification` block, because both clients compose and display
reminders themselves — the new one suppressing the server's copy when the
device has already armed a local reminder for that slot. A `notification`
block there shows every morning reminder twice. Its `reminder_id` is per
*firing* rather than per subscription, because the new client ignores a repeat
of the last id it handled, and a bare row id would have made every reminder
after the first look like a duplicate of the first.

---

## 3. Improvements made beyond parity

- **One find-or-create for a one-to-one thread.** `Services\Chat\OneToOneThread`,
  shared by the sponsorship flow and the iOS back-fill. Two of these drifting
  apart is how a pair ends up with two threads and the conversation split
  between them.
- **The website is served by the application.** 169 URLs, the JSON-LD, the 27
  redirects and the sitemap, with the metadata pinned per URL.
- **`/my` replaces the web app** on the same domain, with no Tailwind and no
  build step.
- **Sign-in does not confirm an address exists.** The old web page answered
  "No account with that email", which turns a login form into a way of
  confirming somebody is in A.A.
- **`billing:check`** classifies Apple and Google credential failures rather
  than reporting a bare 401.

---

## 4. Still open

| | |
|---|---|
| Rotate the Firebase admin key and purge it from git history | §1.6 |
| Rotate the Sendy API key | §1.9 — it is in `8/newsletter_subscribe.php` |
| Retire `/18`, `/7` and `aa/web/1` from the server | §1.1, §1.2 |
| Apple-only billing scripts: `giftsubscription`, `getsponseepurchases`, `add_orderdata_sponsee` | superseded by the v19 pair, which is built; these matter only if iOS 1.6.6 is pointed here |
| Push: `notification.php`, `notify.php`, `send_user_online_notification_to_all_friends.php` | |
| `get_build_expiry.php` | unreferenced by every client — retire it rather than port it |
| `/my`: the sponsor view and comments | the apps own these today |
| `AllowEncodedSlashes NoDecode` on the server | or the encoded story URLs serve 200 instead of 301 |
