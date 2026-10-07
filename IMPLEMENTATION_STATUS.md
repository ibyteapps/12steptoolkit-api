# Implementation status

Honest state of the Laravel layer as of **2026-10-07**. Written so that the
difference between "built and tested", "deliberately stubbed" and "not started"
is never a guess.

**Tests: 149 passed (528 assertions). Pint clean.**

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

### Entitlements — the three-source resolver

`Services/Billing/EntitlementService` is the only thing allowed to answer "is
this person premium?", and it exists because during the overlap there are three
legitimate sources: store subscriptions this server verified, RevenueCat
(read-only, and the only source that knows about every subscription sold before
this server existed — which is all of them), and grants made in the console.

Three sources for one boolean is ordinarily a bug. One rule makes it safe:

> **No source may take away access that another source still grants.**

The resolved entitlement is the most generous of the three. Fourteen tests, each
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

### The rest

`/api/v2/health`. `ApiExceptionRenderer` with every content column in
`dontFlash`. `SecurityHeaders`, `AssignRequestId`, `ForceJsonResponse`. The
scheduler, with its heartbeat, queue worker and prunes. `config/legacy.php`,
`config/billing.php`, `config/toolkit.php`, `config/console.php` — each with the
reasoning next to the value rather than in a separate document that will drift.

---

## 2. Deliberately stubbed

### The v8 (Apple) layer

`AppleScriptController` returns a logged **503**, and the route group is **off
by default** (`LEGACY_V8_ENABLED=false`).

That is a choice, not an omission: pointing `apple.12stepapp.com` at a
half-built v8 layer would be a quiet failure in a 2021 client, which is the
worst possible kind. With the flag off, the middleware answers an empty 200 —
exactly what `8/db.php`'s `exit(0)` does today — so nothing new appears in iOS
1.6.6, and with the flag on before the endpoints exist, the failure is loud.

The controller's docblock is a full specification of the twenty-five endpoints,
the four multiplexed ones, and the six behaviours that must change on the way
(ownership clauses, the `updatefield.php` allow-list, prepared statements, the
`"success " . $sql` responses, the plaintext-password email, and the four
endpoints that use `mysql_*` and cannot run at all). Whoever writes it next does
not have to re-read the old scripts.

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
* **icons** — profile pictures, streamed by the application from outside the web
  root exactly as the old scripts do, which is what makes "only people who may
  see this picture can fetch it" enforceable.
* **reminders**, **meetings** (search and the per-day limits), **AI**.
* **account deletion** — the one the stores require. `accounts.deletion_timestamp`
  exists already.

### 3.2 Billing — the providers

The resolver and the models are built and tested (§1). What is not wired is
everything that talks to a store:

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

The Google product ids are a guess and marked as one (§C1). Until they are
confirmed, an unlisted product is recorded and grants nothing from this server,
and every current subscriber stays premium through the RevenueCat bridge — so
the unknown costs nobody access.

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

`console:user` and `app:check` are built (§1). Two remain, and both depend on
§3.2: `billing:prices` (prints the real store catalogue, which is how §C1 gets
answered) and `revenuecat:reconcile`.

### 3.5 The website layer

`routes/site.php` holds one placeholder. The pages that need a server —
`/sign-in/{token}` and the store-required account-deletion page — are not built.
12steptoolkit.com stays a Next.js static export in `../Website`; this
application serves only what an export cannot.

---

## 4. Known gaps that are not work items

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
