# Implementation status

Honest state of the Laravel layer as of **2026-10-07**. Written so that the
difference between "built and tested", "deliberately stubbed" and "not started"
is never a guess.

**Tests: 38 passed (96 assertions). Pint clean.**

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

### The rest

`/api/v2/health`. The console's login and overview. `ApiExceptionRenderer` with
every content column in `dontFlash`. `SecurityHeaders`, `AssignRequestId`,
`ForceJsonResponse`. The scheduler, with its heartbeat, queue worker and prunes.
`config/legacy.php`, `config/billing.php`, `config/toolkit.php`,
`config/console.php` — each with the reasoning next to the value rather than in
a separate document that will drift.

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

* **mark-as-reviewed** — blocked on `docs/OPEN_QUESTIONS.md` §C2 (`shared`
  versus `reviewed`). I would rather ask than get it backwards.
* **comments / chat** — blocked on §A2 and §A3
  (`comment_thread_subscribers`), which decide whether the subscriber insert is
  an `insertOrIgnore` or a `firstOrCreate`.
* **sponsorship** — the directory, requests, accept/decline, the country facets.
* **icons** — profile pictures, streamed by the application from outside the web
  root exactly as the old scripts do, which is what makes "only people who may
  see this picture can fetch it" enforceable.
* **reminders**, **meetings** (search and the per-day limits), **AI**.
* **account deletion** — the one the stores require. `accounts.deletion_timestamp`
  exists already.

### 3.2 Billing

Everything is configured and nothing is wired:

* Apple **App Store Server Notifications V2** → `/api/v2/webhooks/apple`,
  JWS verified against the Apple Root CA - G3 fingerprint already in
  `config/billing.php`.
* Google **Real-time Developer Notifications** → Pub/Sub →
  `/api/v2/webhooks/google`.
* **RevenueCat, read-only** — the webhook, and `revenuecat:reconcile` for what
  the webhook misses. Nothing here ever writes to RevenueCat.
* `EntitlementService`, with the rule that makes two sources of truth safe:
  **neither source may take away access the other still grants.** A late
  RevenueCat webhook cannot revoke a subscription this server just verified with
  Apple, and vice versa.
* `store_orders` writing, and the sponsee-gift consumables that turn a purchase
  into a slot.

The Google product ids are a guess and marked as one (§C1). Until they are
confirmed, an unlisted product is recorded and grants nothing from this server,
and every current subscriber stays premium through the RevenueCat bridge — so
the unknown costs nobody access.

### 3.3 The console, beyond the skeleton

Login and an overview exist. The surface to build, drawing on the AA Big Book
back office and then going past it:

*From AA Big Book:* accounts, subscriptions, purchases, orders, plans, support
threads, adverts, quotes.

*New here, because this product has them:* sponsorship (requests, pairs,
reported users), chat moderation, gifted subscriptions, and **the cutover's own
numbers** — how many accounts are sealed, how many installs are still on 1.9.0
or 1.6.6, the 401 rate on signed requests, the per-collection write rate against
the same hour yesterday. That last page is what step 4 of `docs/CUTOVER.md` is
watched on, so it is worth building before the switch rather than after.

**The data boundary holds everywhere in that list: counts and dates and sync
state, never content.** No inventory, no amend, no journal line, no chat message
and no note is readable from the console, by anybody, at any permission level.

### 3.4 Artisan commands referenced but not written

Four are named in config comments and in these documents, and do not exist yet:
`app:check` (reads the scheduler heartbeat), `console:user` (creates a staff
account — **needed before the console can be logged into on the server**),
`billing:prices` (prints the real store catalogue, which is how §C1 gets
answered), `revenuecat:reconcile`.

`console:user` is the one with a dependency on it: without it there is no way in
to the console on a fresh deploy.

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
* **`sw8` is never written.** Cautious in the direction that cannot fail (§A1).
