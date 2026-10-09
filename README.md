# 12 Step Toolkit — API, website layer and console

A Laravel 13 application that replaces the two hand-written PHP script trees
behind the 12 Step Toolkit apps, serves the rebuilt Flutter app, and adds the
staff console.

It does three jobs that share nothing but a database:

| Prefix       | What it is                                                                 | Session? |
|--------------|----------------------------------------------------------------------------|----------|
| `/api/v1/*`  | The two legacy PHP APIs — **v19** (Android) and **v8** (Apple) — answered in their own words so the apps already installed keep working | no |
| `/api/v2/*`  | Everything the old contract cannot express: a server-verified subscription, an account deletion, a support thread, a push token | no |
| `/console/*` | The back office: accounts, subscriptions, support, the cutover's own numbers | yes, cookie |

The public website (`12steptoolkit.com`) stays a Next.js static export in
`../Website`. This application serves only the handful of pages a static
export cannot — see `routes/site.php`.

## The one thing to read first

**The new Flutter app already speaks the v19 contract.** Its
`lib/core/network/endpoints.dart` lists `login_new_account.php`,
`inventory_add_update.php`, `get_counts.php` and the rest, and
`lib/core/network/signing.dart` builds the v19 HMAC byte for byte. So v19 is
not a legacy burden wrapped in a shim — it is the contract **two of the three
clients speak**, and it is implemented here as a first-class API with the
security holes closed. `ARCHITECTURE.md` explains why that is the smaller,
more reversible cutover.

The second thing: **this application never alters a legacy table.** The
step-work tables, `accounts` and `account_details` already exist in
`data_12steptoolkit` and have since 2019. Nothing here adds a column to them,
renames one, or changes a type. New state goes in new tables. That rule is
what makes the cutover reversible — the old scripts can be switched back on
at any point and will still find the database they expect.

## Running it

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate          # creates ONLY the new tables; see below
php artisan serve
```

`php artisan migrate` creates the application's own tables and touches none of
the adopted ones:

* framework — `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`,
  `sessions`, `settings`
* auth — `personal_access_tokens`, `installs`, `login_codes`, `sign_ins`,
  `request_nonces`, `account_security`
* console — `console_users`, `console_password_reset_tokens`, `console_audit`,
  `support_tickets`, `support_messages`
* billing — `entitlements`, `store_subscriptions`, `store_orders`,
  `apple_notifications`, `google_notifications`, `revenuecat_events`,
  `complimentary_grants`

`install_secrets` is the exception worth knowing about: it was added by the 2024
Android security patch set, it **is** in production (confirmed from the schema
dump), and this application treats it as adopted rather than as its own.

### The .env values that matter

Nothing in this repository is a secret, and nothing secret may be committed to
it. These three come from the existing server and have no safe default:

| Key                     | Where it comes from |
|-------------------------|---------------------|
| `LEGACY_JWT_SECRET`     | the HS256 secret in `19/config.php`. Tokens already in people's phones were signed with it, so it has to be accepted until those installs are gone |
| `LEGACY_SERVER_SECRET`  | the v8 shared secret. Empty refuses every Apple request, which is the correct default for a secret that proves nothing |
| `DB_*`                  | `data_12steptoolkit`, the same database both old APIs use |

Private keys — the Apple In-App Purchase `.p8` and the Google Play service
account JSON — live outside the web root, `chmod 600`, and only their *paths*
go in `.env`.

## Testing

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
php vendor/bin/pest                  # 88 tests
php vendor/bin/pint                  # formatting
php vendor/bin/pint --test           # formatting, check only
```

The suite runs against an in-memory SQLite database. The adopted tables do not
exist there, so `tests/Support/LegacySchema.php` builds them before each test.
**That file is a test fixture and a reference, never a migration** — and since
2026-10-07 it is a *transcription* of `docs/reference/legacy-schema.sql`, the
real structure-only dump, rather than a reconstruction from the old PHP.

That change paid for itself immediately. `install_secrets` has
`UNIQUE (account_id, device_id)` where the fixture had a plain index, so
`bootstrap_secret.php`'s rotation — revoke the old row, insert a new one —
passed every test and would have failed with a duplicate-key error on the first
real rotation in production. Which is the whole argument for keeping the
reconstruction runnable rather than writing it in prose.

What the 88 cover:

* **the canonical request string** (7 unit tests) — held to the shape read off
  `19/auth_checker.php` *and* both shipped clients. If one of these breaks,
  every signed request from every install in the field breaks with it.
* **the Android signed path end to end** — a good signature, a wrong secret, a
  body that does not match its hash, a stale timestamp, a header account that
  disagrees with the token, an unknown device, a replay, and "a bad signature
  does not spend a nonce".
* **the six record collections** — write, read back, delete, plus the
  ownership refusals the old scripts did not have.
* **the night switches** — eleven normalised to `Yes`/`No`, twelve answers, and
  no `sw8`.
* **auth** — anonymous account creation, token TTL, the plaintext→bcrypt
  upgrade, the `keep`/`clear` plaintext switch, refusal of email-only Google
  sign-in, sealing, and identical answers for an unknown versus a sealed
  account id.
* **counts**, and `get_app_settings.php` answering under `data` rather than
  `response` — the one endpoint in v19 that breaks its own envelope, which both
  clients parse that way.
* **entitlements** — fourteen tests on the three-source resolver, each written
  in the mean direction: they set up the situation where a naive "last writer
  wins" would revoke somebody who is paying, and assert that it does not.
* **the console** — every page behind the guard, the staff-account flow (a
  single-use hashed link, no `--password` flag anywhere), grants, support
  replies, and the audit trail.
* **the data boundary**, which is the one console property that must never
  regress: a record is written into each collection carrying a string that
  appears nowhere else in the application, all seven console pages are loaded,
  and none of those strings may appear on any of them. A new panel, a debug
  dump, an exception or a `select *` that leaks one fails this test by name.

## Deploying it

```bash
app_update_toolkit              # pull origin/main and deploy it
app_update_toolkit --check      # report only; changes nothing
app_update_toolkit --rollback   # back to the commit before the last deploy
```

The same shape as BohriConnect's `app_update` and AA Big Book's
`app_update_bigbook`: run as root from anywhere, it switches to the site's user,
takes the site down behind a 503 only if it has to, migrates, rebuilds caches,
reloads PHP-FPM, smoke-tests the live URLs and runs `app:check`. A deploy with
nothing to pull never goes down at all. Fail before a migration and the previous
commit goes back by itself; fail after one and it stays down on purpose, because
old code against a new schema is not a decision to make automatically.

`docs/DEPLOYMENT.md` is the first install, step by step.

## Three commands worth knowing about

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
php artisan console:user you@example.com   # the only way a staff account exists
php artisan app:check                      # is THIS server wired up?
php artisan app:check --legacy             # ...and is the database the shape we think?
php artisan billing:check                  # can it actually reach Apple and Google?
php artisan billing:check --offline        # ...and does the catalogue hang together?
```

`console:user` prints a single-use set-password link; there is no registration
page and no `--password` flag. **On a fresh deploy nothing can sign in to the
console until this has been run once.**

`app:check` is for the twenty minutes after a cutover: it checks the database and
whether it is the right one, every adopted table, `install_secrets` (the cutover
blocker — see `docs/OPEN_QUESTIONS.md` A4), the application's own tables, cache,
queue, the scheduler heartbeat, storage permissions, and every legacy switch with
what it means. Exit code 1 on any failure, so it can be the body of a monitor.

`billing:check` is for the afternoon the store credentials are being set up, when
four systems each blame the others. It makes one real read-only call to each store
API and names which half works and why the other does not — telling apart a key
Google refused, an API not enabled in the key's Cloud project, and a service
account not yet granted in the Play Console, which are fixed in three different
places. It also diffs each store's live catalogue against `config/billing.php`,
which is the only thing that notices a product being sold that this server does
not honour. Needs no database, writes nothing anywhere, and never prints a key or
a token. `docs/STORE_SETUP.md` is the matching checklist.

## The documents

| File | What it answers |
|------|-----------------|
| `ARCHITECTURE.md` | why the old contract is the main one, how the three applications are kept apart, what each legacy hole was and what replaced it |
| `AUDIT_AND_IMPROVEMENTS.md` | what was found in the live system — seven security findings with the statement that proves each — what was fixed in the port, and what is still open |
| `FEATURE_PARITY.md` | the old surface mapped onto this one: /18 against v19, the web app against `/my`, the website, and the differences that are decisions rather than gaps |
| `MIGRATION_PLAN.md` | how the people, the data and the traffic move — and why the data does not |
| `STORE_RELEASE_CHECKLIST.md` | what has to be true before submitting an app release, including the account-deletion requirement that blocks review |
| `IMPLEMENTATION_STATUS.md` | what is built, what is stubbed, what is not started |
| `docs/DEPLOYMENT.md` | the first install — `api/` beside the website's document root, three nginx prefixes, then `app_update_toolkit` for ever after |
| `docs/CUTOVER.md` | the order of the switch-over, and how to switch back |
| `docs/WEBSITE_TAKEOVER.md` | what has to be true before `12steptoolkit.com`'s document root can move here — 169 indexed URLs, measured |
| `docs/STORE_SETUP.md` | what to click in App Store Connect, Cloud Console and the Play Console, and how to read `billing:check` |
| `docs/ENTITLEMENT_RULES.md` | who is premium and why — our own rules, replacing RevenueCat's |
| `docs/OPEN_QUESTIONS.md` | the things that cannot be answered by reading code, each with the one command or screen that answers it — section A is now closed by the schema dump, and A6 is a live bug it turned up |
| `docs/reference/legacy-schema.sql` | the real schema of `data_12steptoolkit`, structure only. The authoritative reference for every column type in this application |

## What is deliberately not here

* **No front-end build step.** The console is Blade and hand-written CSS. A
  deployment is `git pull`, `composer install --no-dev`, `php artisan migrate`.
  There is no `npm` anywhere in a release.
* **No secrets, no signing files, no `.env`.** `.gitignore` covers them; if one
  ever appears in `git status`, that is a bug in the commit, not in the file.
* **No user content in logs.** `bootstrap/app.php` lists every content column
  in `dontFlash` — `description`, `invdescription`, `myfault`, `apologynotes`,
  `amendsnotes`, `q6_notes`, `comment` — alongside the credentials. A journal
  line must never reach a log file, an exception report or the console.
