# Implementation status

Honest state of the Laravel layer as of **2026-10-07**. Written so that the
difference between "built and tested", "deliberately stubbed" and "not started"
is never a guess.

**Tests: 174 passed (600 assertions). Pint clean.**

---

## 1. Built and tested

### The v19 signed path — the security core

This is the part everything else rests on, and it is the part with the most
tests, because if it is wrong every signed request from every install in the
field is wrong with it.

| | |
|---|---|
| `Services/Legacy/CanonicalRequest.php` | pure, 7 unit tests. `METHOD\nPATH[?QUERY]\nSHA256_HEX_LOWER(body)\nTIMESTAMP\nNONCE`, base64 signature. Held to the shape read off `19/auth_checker.php` **and** both shipped clients. |
| `Services/Legacy/HmacVerifier.php` | the whole check, in order: headers present, account matches the token, 300-second window, body hash, active install secret, signature — **then** the nonce is claimed. A bad signature does not spend a nonce; there is a test for exactly that. |
| `Services/Legacy/LegacyJwt.php` | HS256, the shipped secret, accepted for tokens already in the field. |
| `Services/Legacy/LegacyEnvelope.php` | `{status, message, response}`, and the `strings()` helper for the `strval()` behaviour the clients parse. |
| `Models/RequestNonce.php` | `claim()`. Replay protection the live server has in a file and never wired up. |

### Token provenance — the placeholder-secret containment

The live server's JWT secret is the library's template placeholder
(`19/jwt_verify.php:11`), so anybody can mint a bearer token for any account id,
and `bootstrap_secret.php` would then hand them that install's signing key. The
owner's decision is to leave the live server alone, so this application is where
it gets contained:

* it **never issues a legacy-format token** — sign-in returns a Sanctum token,
  which makes "legacy format" mean "did not come from here" with nothing to look
  up. Safe because the shipped Android client treats the token as opaque, which
  was traced rather than assumed (`LoginActivity.kt:184`, `TokenStore.kt:84`,
  and no JWT decoding anywhere in 285 Kotlin files);
* `VerifyLegacyJwt` records `server` or `legacy` on every request;
* `bootstrap_secret.php` refuses to mint or rotate for a `legacy` token, so a
  forged token can only read a device binding that already exists — and it would
  have to guess a real install's device id to do that.

Thirteen tests, with `LegacyJwt::issue()` playing the old server so the rule is
tested against a token indistinguishable from a forged one. One line
(`LEGACY_TOKENS_MAY_BOOTSTRAP`) relaxes it for the cutover window.

Tokens are also capped per account and expire, where the old system had a
296,000,000-second lifetime and no revocation list at all.

### The v19 endpoints

**Auth** — `login_new_account.php`, `login_email.php`, `login_google.php`,
`login_2.php`, `login_upgrade.php`. Covered: anonymous account creation, token
TTL, the plaintext→bcrypt upgrade, the `keep`/`clear` plaintext switch, refusal
of email-only Google sign-in, sealing, and identical answers for an unknown
versus a sealed account id (so the endpoint is not an account-existence oracle).

**Records** — all twelve: `get_inventories` / `inventory_add_update` and the
same pair for amends, nights, mornings, journals and gratitudes. Covered: write,
read back, delete, the ADD/UPDATE/DELETE dispatch on `online_id`, 404 on an
update that matched nothing (the old code reported success), the ownership
refusals, the eleven night switches normalised to `Yes`/`No`, twelve answers,
and `sw8` never written.

**Account** — `get_user_account.php`, `update_account.php`,
`update_account_details.php`.

**Billing** — all four of v19's: `add_order.php`,
`add_sponsee_order_and_gift.php`, `get_sponsee_gift_expiry.php`,
`revcat_is_subscribed.php`. Covered: a gift recording the purchase, assigning
one seat and notifying the member; the sponsee being premium immediately
(including the write-through to `accounts.subscribed`); idempotency on a
retried call; a second member refused on a one-seat order; a posted `quantity`
bigger than what was bought being ignored; gifting refused to somebody the
caller is not connected to, to an erased account, and on a body naming
somebody else as the buyer; the term read off the SKU **by its words** when a
client sends none — `…annual_sponsee1` is twelve months, not one — and refused
outright when it cannot be worked out; the expiry endpoint's bare-array shape;
the longest of two gifts winning rather than the latest; `add_order.php`
granting no access at all; and `revcat_is_subscribed.php` answering about the
caller and nobody else. 31 tests.

**Comments and chat** — the twelve endpoints `retrofit/ApiService.kt` actually
calls: `get_comments`, `get_step_comments`, `get_comment_for_account_id`,
`get_comment_threads`, `get_comment_receipts`, `comment_add_update`,
`comment_star`, `comment_update_receipt`, `update_thread_subscriber`,
`block_report_user`, `unblock_user`, `get_blocked_users`. The live directory
holds twenty-odd more, several duplicates of each other (`get_comments 2.php`,
`get_commentsX.php`), which nothing calls.

The part worth knowing about is `Services/Chat/CommentVisibility`. The old
reader does not return a thread's messages to its members — it returns the
messages posted *while that member was subscribed*, so somebody who leaves a
group and rejoins does not see what was said while they were away. That is a
real promise in a recovery app and the easiest thing in this feature to lose by
simplifying, so it has a class and five tests of its own.

Four things changed on the way, each a hole:
`comment_add_update.php` is `UPDATE comments SET comment = ?, deleted = ? WHERE
id = ?` with no account clause, so anybody could rewrite or delete anybody's
message by guessing an id; `block_report_user.php` takes the caller from
`user_id` in the body; its INSERT is a duplicate-key error the second time
somebody blocks the same person; and a receipt could be moved *backwards*, so a
retry carrying a stale value un-read a message.

**Sponsorship and chat relationships** — `get_friends`, `get_one_friend`,
`fetch_sponsor_ids`, `send_chat_request`, `update_sponsors`,
`check_if_has_sponsor_or_old_device`.

One table, `sponsors`, carrying two request flows: 0 → 1 is sponsorship and
5 → 6 is chat. The schema comment documents 0–4 and stops, so 5 and 6 were
traced from the client (`extras/F.kt:684-687`, `FragmentMemberProfile.kt:278`)
rather than guessed, as was `friendType` — 1 for my sponsor, 2 for my sponsee,
3 for a chat — which the old SELECT derives with a CASE relative to whoever is
reading.

`update_sponsors.php` is another `WHERE id = ?` with no account clause, so
anybody could accept, reject or delete anybody's sponsorship by guessing a row
id. Beyond scoping that, two rules are new: **only the other side may accept**
(otherwise whoever sent a request could accept it themselves, which is the whole
of a request), and a blocked pair cannot open a relationship at all. Thread
creation is now idempotent, so accepting twice cannot leave two threads with the
conversation split between them.

**Profile pictures** — `upload_icon`, `delete_icon`, and a streaming
`icon/{account}` that is not a v19 script.

`upload_icon.php` decides the file's type from a request header and its
extension from the client's own filename, with no check that the bytes are an
image: `evil.php` sent as `image/png` is saved as `<id>.php`. The only thing
stopping that being remote code execution is that the icons directory happens to
sit outside the web root — which a future "serve icons from nginx for speed"
change would quietly remove. Here the type comes from the bytes
(`getimagesize`), the extension is derived from that, the size is capped, and
the client's filename is never used for anything.

It also set `REQUIRE_AUTH false`, making it an unauthenticated file write for
any account id. That one needed fixing; `REQUIRE_HMAC false` did not, and could
not — see §4.

Pictures stay outside the web root and are streamed by the application, which is
the only arrangement where "only people who may see this can fetch it" is
enforceable: yours, somebody you have a live relationship with, or somebody in
the same thread.

**Reminders** — `sync_reminders`, which turns the four `varchar(5)` times in
`account_details` into `reminder_subscriptions` rows with a computed
`next_fire_at`. The old script opens `REQUIRE_AUTH false` and takes `account_id`
from the body, so it is an unauthenticated write for any account and an
account-existence oracle besides.

`next_fire_at` is computed in the subscriber's own timezone and stored in UTC,
which is what keeps "half past seven" at half past seven across a clock change
— 07:30 London is 07:30 UTC in winter and 06:30 in summer, and storing UTC alone
drifts an hour twice a year. There is a test for exactly that.

**Other** — `get_counts.php`; `get_app_settings.php`, answering under `data`
rather than `response`, which is the one endpoint in v19 that breaks its own
envelope and which both clients parse that way; `bootstrap_secret.php`.

### Models

The six step-work collections on one abstract `StepRecord` with per-collection
`writableColumns()`, `projection()` and `toLegacyRow()`. Plus `Account`,
`AccountDetail`, `AccountSecurity`, `AppSetting`, `Install`, `InstallSecret`,
`PersonalAccessToken`, `RequestNonce`, `SignIn`, `Setting`, `ConsoleUser`,
`Entitlement`, `SupportTicket`, `SupportMessage`.

Every one is `$guarded = ['*']` with `forceFill()` at the write sites. Nothing
can mass-assign into a table holding somebody's recovery.

### Migrations

All five run, and none of them names an adopted table:
framework tables, `account_security`, the auth tables, the console tables, the
billing tables. `README.md` lists what each creates.

### The schema fixture

`tests/Support/LegacySchema.php` is a transcription of
`docs/reference/legacy-schema.sql` — the real structure-only dump of
`data_12steptoolkit`, 41 tables, taken 2026-10-07 — rather than the
reconstruction from the old PHP that it was before. Every column type in this
application is now read off the database instead of inferred from a
`bind_param` string.

It caught one bug on arrival (`install_secrets`' unique key versus the
rotation), corrected `tstamp` to `bigint` across all six collections, and turned
up A6: `nights` and `mornings` are latin1 while the connection is utf8mb4, so
every curly apostrophe iOS inserts into a nightly review is stored as `?`.
`app:check` now reports that on every run.

### Entitlements — the four-source resolver

`Services/Billing/EntitlementService` is the only thing allowed to answer "is
this person premium?", and it exists because during the overlap there are four
legitimate sources: store subscriptions this server verified, RevenueCat
(read-only, and the only source that knows about every subscription sold before
this server existed — which is all of them), grants made in the console, and
months a sponsor gifted (`Services/Billing/SponseeGifts`, added 2026-10-10 —
the engine had no gift source at all, so a sponsee handed a seat was not
premium however many months had been bought for them).

Four sources for one boolean is ordinarily a bug. One rule makes it safe:

> **No source may take away access that another source still grants.**

The resolved entitlement is the most generous of the four. Fourteen tests, each
written in the mean direction — they set up the situation where a naive "last
writer wins" would revoke somebody, and assert that it does not. Covered: a late
RevenueCat webhook versus a fresh Apple verification and the reverse, whichever
source reaches further, a lifetime grant beating every date, immediate
revocation on a refund, access held through a billing-retry grace period, a
cancelled subscription running to its end, an unmapped product granting nothing
while RevenueCat still carries the person, idempotency, and an expiry being a
date passing rather than an event anybody sends.

`StoreSubscription`, `StoreOrder` and `ComplimentaryGrant` are the models under
it. Money is integers in milli-units all the way to the string that renders it.

### The console

Login, the set-password link, and six pages — all behind the guard, all tested:

* **Overview** — what is waiting, then what is wrong, then the week. "What is
  wrong" is the unattached purchases, the unrecognised product ids and the
  pending deletions: money or obligations already outstanding that nobody
  reports, because the person affected does not know what to call it.
* **Accounts** — search by id, email, the app's own identifier or nickname; then
  one page per account answering "is their backup working" first, because that is
  the question support is actually asked.
* **Subscriptions** — saved views for the three ways one goes wrong (no account
  attached, unknown product, ending this week), where premium is coming from, and
  takings for thirty days.
* **Support** — threads, oldest first within a state, with the link to that
  person's account page, which is the point of the screen.
* **Cutover** — the three figures `docs/CUTOVER.md` step 4 says to watch, plus
  the switches. The AA Big Book console has no equivalent; this one exists so
  that the rollback decision is not made from a log file at eleven at night.
* **Audit** — who did what, looks included, readable by everybody who can sign
  in.

`Services/Console/AccountSnapshot` is the only thing the console uses to look at
an account, and it is written so that selecting a content column would have to
be a deliberate act: every query in it is a `count()` or a `max()` on a
timestamp.

**The boundary has its own test.** `tests/Feature/Console/PagesTest.php` writes
a record into each collection carrying a string that appears nowhere else in the
application, then loads all seven console pages and asserts that none of those
strings appears on any of them. If a new panel, a debug dump, an exception, an
eager-loaded relation or a `select *` ever exposes one, that test fails and says
which page and which field.

### Commands

* `php artisan console:user` — the only way a staff account comes into being.
  There is no registration page and no `--password` flag: a new account has a
  null password and is unusable until a 30-minute, single-use, hashed-at-rest
  link is followed. `--reset` clears the old password as well as issuing a link,
  `--deactivate` switches an account off without deleting it (so
  `console_audit` keeps its author), `--list` shows who can get in.
* `php artisan app:check` — is *this server* wired up. Written for the twenty
  minutes after a cutover: the database and whether it is the right one, every
  adopted table with its row count, `install_secrets` (the cutover blocker), the
  application's own tables, cache, queue, the scheduler heartbeat, storage
  permissions, the icons directory being outside the web root, and every legacy
  switch with what it means. `--legacy` checks the adopted tables column by
  column against `tests/Support/LegacySchema.php`, which is the check to run the
  first time this application meets the real database. Exit code 1 on any
  failure, so it can be the body of a monitor.

### The website, and the member area

The public site is served by this application: 169 URLs — the home page, the
blog, the whole A.A. literature library, the legal pages and the contact page
— with each page's title and description pinned per URL against the
production build, the 27 redirects, `sitemap.xml`, `robots.txt` and the
JSON-LD assembled in one place so no page can describe a breadcrumb trail it
does not show. `docs/WEBSITE_TAKEOVER.md` is the inventory;
`config/site.serves_website` is still **false**, because the document root is
the static export and nginx routes four prefixes here — moving it is a
separate decision with its own checklist.

The contact page writes `support_tickets`, which is the queue the console
already answers, so a message from the website and a message from the app
land in the same place. No session and therefore no CSRF token (the site
routes carry no cookie, deliberately); the defences are a honeypot, an
hour-signed token from `Services/Site/ContactToken` and a rate limit of five
an hour per address. No address and no user agent is stored. 12 tests.

`/my` is the member area that replaces `web.12steptoolkit.com`: email and
4-digit-code sign-in, and read, write and delete across the seven record
types, in Blade, with no Tailwind and no build step.

### The rest

`/api/v2/health`. `ApiExceptionRenderer` with every content column in
`dontFlash`. `SecurityHeaders`, `AssignRequestId`, `ForceJsonResponse`. The
scheduler, with its heartbeat, queue worker and prunes. `config/legacy.php`,
`config/billing.php`, `config/toolkit.php`, `config/console.php` — each with the
reasoning next to the value rather than in a separate document that will drift.

---

## 2. Deliberately stubbed

### The v8 (Apple) layer — started 2026-10-07

Four of the twenty-five endpoints are built, against the **actual PHP** rather
than the analysis of it, which changed two things the analysis did not record.

**Built:** `reachable.php`, `getlist.php` (all nine of its branches),
`deleterecord.php`, and the four `mysql_*` scripts, which stay dead on purpose.
Everything else still answers a logged **503**, and `LEGACY_V8_ENABLED` still
turns the whole group off — now with a **410**, because "this host is not
serving v8" and "your secret is wrong" are different facts and the operator
pointing a DNS record at this application should be able to tell them apart. An
earlier version of this file said the disabled case returned an empty 200; it
did not, and conflating the two would have been worse than either.

**The gate was not wired.** Both v8 route groups carried
`throttle:legacy-apple` and nothing else, so `VerifyAppleSecret` sat in
`bootstrap/app.php` under an alias no route used — the shared-secret check never
ran. It did not matter while the controller was a 503 and would have mattered
enormously the moment anybody implemented one. A 503 is a very effective way to
hide a missing authentication layer from every test you would think to write.
`AppleTest` now asserts the gate from the outside, four ways.

**Two things reading the real `getlist.php` corrected:**

* **`nights` is not `SELECT *`.** Line 56 selects `id, timestamp, thedate,
  reviewed` and nothing else; the twelve answers are fetched one record at a
  time by `getrecorddetail.php`. A faithful-looking `SELECT *` would have put
  every user's whole nightly inventory on the wire on every open of that list.
* **`icon > 40` becomes the string `"0"`** in the output loop (line 163) — a
  product rule (41 bundled avatars, 0–40) hiding in a `while`.

**Three behaviours deliberately not reproduced:**

* the `$tablename = 'inventories'` **default**. In `getlist.php` an
  unrecognised `type` fell through to an `else` that queried it, and in
  `deleterecord.php` it meant a request with a missing type **deleted from
  somebody's Fourth Step**. An unknown type now lists nothing and deletes
  nothing.
* the **hand-assembled JSON**. `echo '['`, a `json_encode` per row, and a comma
  emitted only `if (json_last_error() == JSON_ERROR_NONE)` — so a row that
  fails to encode prints nothing while the comma for the row before it has
  already gone out, and any failure other than on the last row yields
  `[{…},]`. Nothing hits it today and that is luck, not design; the
  commented-out `utf8_encode`/`utf8ize` around the sponsor directory says it did
  not always hold.
* the **executed SQL in the response body**. `"success " . $sql` on three
  endpoints. The literal `"success "` is kept because the client's only test is
  `outputStr.contains("success")`; the statement is not, because a response that
  echoes a query leaks a schema to anybody holding a secret that is printed in a
  `test.html`.

**What `deleterecord.php` still cannot do, and why that is written down rather
than papered over.** The client posts exactly three fields — `id`, `type` and
the shared secret (`_Constants.swift:520`) — so there is **no caller identity to
scope a delete by**. An ownership clause here would have to derive the owner
from the record, which always matches and protects nobody, and the code would
then look safe. What it does instead: binds the id, refuses an unknown type, and
**refuses outright once the owner has signed in on 2.0**. For this endpoint
sealing is the whole of the protection, and it is the reason sealing exists.

The controller's docblock remains a specification of the twenty-one endpoints
still to write, the four multiplexed ones among them, and the behaviours that
must change on the way.

---

## 3. Not started

Listed in the order I would do them.

### 3.1 The rest of the v19 surface

The twelve record endpoints and the auth family are the ones the new Flutter app
and the sync engine need, so they came first. These are the remainder, and each
is a known shape in the old scripts:

* **the rest of chat** — group creation and invites (`create_invite.php`,
  `redeem_invite.php`, `thread_subscribers_insert_csv.php`,
  `update_thread_title_description.php`, `upload_icon_group.php`),
  `send_chat_request.php`, `thread_admin_promote_demote.php`, typing indicators,
  and `comment_reactions` writing. The reads of all of these are already served
  by `get_comment_threads.php`.
* **mark-as-reviewed** — nearly unblocked. `reviewed` turns out to be a table
  as well as a flag: `(inventory_id, sponsorid, type, tstamp)`. The one thing
  left is what the `type` values mean, since `inventory_id` is polymorphic
  across inventories, amends and nights — one `GROUP BY type` settles it
  (§C2).
* **the sponsor directory** — browsing people who are accepting sponsees, with
  the country facets. The relationships themselves are built (§1); this is the
  search that finds somebody to ask.
* **group pictures** — `upload_icon_group.php`, which is the same upload path
  for a thread rather than a person.
* **the reminder sender** — the schedule is built; what reads `next_fire_at` and
  pushes through FCM is not, and it needs `notification_texts` (which has a
  generated column and a FULLTEXT index) and the per-install push tokens.
* **meetings** (search and the per-day limits), **AI**.
* **account deletion** — the one the stores require. `accounts.deletion_timestamp`
  exists already.

### 3.2 Billing — the providers

The resolver and the models are built and tested (§1), and so now is everything
needed to *reach* a store: `Services/Billing/Apple/AppleKey` (which refuses a
key of the wrong curve before anything signs with it),
`Apple/AppStoreServerApi`, `Apple/AppStoreConnectApi`,
`Google/ServiceAccountKey` and `Google/PlayDeveloperApi` — each thin, each
read-only, none of them retrying or logging a key, a token or a transaction id.
`php artisan billing:check` exercises all five against the live APIs and is the
command to run when the credentials are in doubt; `docs/STORE_SETUP.md` is the
matching checklist.

What is not wired is everything that *acts* on what a store says:

* Apple **App Store Server Notifications V2** → `/api/v2/webhooks/apple`,
  JWS verified against the Apple Root CA - G3 fingerprint already in
  `config/billing.php`.
* Google **Real-time Developer Notifications** → Pub/Sub →
  `/api/v2/webhooks/google`.
* **RevenueCat, read-only** — the webhook, and `revenuecat:reconcile` for what
  the webhook misses. Nothing here ever writes to RevenueCat.
* `store_orders` writing — the money figures on the console's subscriptions page
  are correct and will read zero until this exists, which the page says in words
  rather than showing as £0.
* the sponsee-gift consumables that turn a purchase into a slot.

`EntitlementService` is already the thing all of them will call, so each is a
verifier that writes a `store_subscriptions` row and then one `refresh()`.

**Both catalogues are now reconciled** against the RevenueCat product list
(§C1 closed, 2026-10-08): thirteen Apple products with their subscription group
and level, seventeen Google products with their base plans, and the three
retired à-la-carte unlocks mapped to `grants => none` by the owner's decision
(`docs/ENTITLEMENT_RULES.md` R2). `billing:check` diffs each store's live
catalogue against that config, which is the only thing that notices a product
being sold that this server does not honour. An id neither knows is still
recorded and still grants nothing from here, while the person keeps their
access through the RevenueCat bridge — so the unknown costs nobody access.

### 3.3 The console, the rest of it

Six pages are built (§1). What is left, in the order I would add it:

* **plans** — what the paywall offers, edited without an app release. The data
  is already in `config/billing.php`'s `offered`; the screen moves it into
  `settings` so it is not a deploy.
* **orders** — one row per payment, once §3.2 is writing them.
* **sponsorship** — requests, pairs, and **reported users**. This one matters:
  the old system's entire moderation surface was `19/admin/reported.php`, an
  unauthenticated HTML page listing every report with the reporter's id, the
  reported person's id and the free-text reason. Anyone who found the URL could
  read it.
* **chat moderation** — blocked on the same open questions as the comments
  endpoints (§A2, §A3).
* **gifted subscriptions** — the sponsee consumables, once they grant anything.
* **adverts and quotes** — the AA Big Book console has both; they are content
  tables, not member content, and are straightforward.

**The data boundary already holds and is tested** (§1), and it applies to
everything in that list: counts and dates and sync state, never content.

### 3.4 Artisan commands still to write

`console:user`, `app:check` and `billing:check` are built (§1). Three remain:

* `revenuecat:import` — the one-time export, which is the only place holding
  `original_transaction_id` and Google purchase tokens for the current
  subscriber base. On the critical path and it expires with the account
  (`docs/ENTITLEMENT_RULES.md` R3).
* `revenuecat:reconcile` — for what the webhook misses during the overlap.
* `billing:prices` — the live prices for the console's plans page. Narrower than
  it was: `billing:check` already proves the catalogue calls work and reports
  what each store holds, so this is the formatting rather than the plumbing.

### 3.5 The website layer, the rest of it

The site itself is built (§1). What is left is small and each piece is
waiting on something outside this repository:

* **`/sign-in/{token}`** and the store-required **account-deletion page** —
  both need the mail credentials that `/my` is already waiting on.
* **The newsletter form** — `config/services.sendy` is read and nothing posts
  to it yet.
* **Moving the document root** onto this application, which is what
  `config/site.serves_website` and `docs/WEBSITE_TAKEOVER.md` are for. Until
  that happens the static export answers `/` and nginx routes the four
  prefixes here.

---

## 4. Two constraints worth knowing before changing anything

**A multipart upload cannot be HMAC-signed under PHP.** The client's
`HmacInterceptor` hashes the request body, multipart included. PHP cannot read
the raw body of a `multipart/form-data` request at all — it consumes it into
`$_POST` and `$_FILES` and leaves `php://input` empty — so the hash the client
computes and the hash the server can compute are never the same bytes. That is
why `upload_icon.php` and `upload_icon_group.php` are the only two scripts in
the v19 tree with `REQUIRE_HMAC false`: it was forced, not chosen. Uploads here
are authenticated by token, with a tighter rate limit, and the reason is written
above the route so nobody "fixes" it by adding the signature middleware back.

**`accounts.icon` above 40 is an `icons.id`, and iOS coerces it to 0.** So a
picture uploaded on Android shows as no avatar on iOS. That is a client bug in a
shipped app, not a server one, and worth knowing before somebody reports it as
missing data.

## 5. Known gaps that are not work items

Things that are *as intended* and might look like oversights:

* **`bootstrap_secret.php` hands the signing key to any bearer token.** The old
  design; closing it needs a client release and a decision I have not made for
  you (§B3).
* **A v8 caller holding the published secret who knows an account id can read
  that account's records.** The old app's design. Contained five ways, cured only
  by users upgrading.
* **`accounts.email` is not unique**, and email sign-in matches the first row,
  as the old scripts do (§C3).
* **No entitlement check on the sync path.** Deliberate — D-001, cloud backup is
  free for everyone. `SYNC_NEEDS_SUBSCRIPTION` exists so that changing that
  would be a visible act.
* **`sw8` is never written.** Confirmed correct: the column does not exist
  (§A1). Eleven switches, twelve answers.
* **`accounts.subscribed` is set and never cleared.** Writing it keeps somebody
  who buys on 2.0 from being told they are not subscribed when they open 1.9.0.
  Clearing it would take access away from people who have it today, which is a
  decision for the owner and is §C3.
* **The console has roles but does not yet enforce them.** `console_users.role`
  is `support | billing | admin` and every page is open to all three. The
  boundary that matters — no member content, at any level — is enforced in
  `AccountSnapshot` and does not depend on roles, so this is a convenience
  rather than a hole; it is listed so nobody assumes otherwise.
