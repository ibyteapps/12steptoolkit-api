# Architecture

## 1. The finding that shaped everything else

The obvious plan for a rewrite is a clean v2 API with a thin compatibility shim
bolted on the side for the old apps. That is the wrong way round here, and the
reason is in the new client.

**The Flutter app already speaks the v19 contract.**
`lib/core/network/endpoints.dart` lists `login_new_account.php`,
`inventory_add_update.php`, `get_counts.php` and the rest.
`lib/core/network/signing.dart` builds the v19 HMAC byte for byte. The whole
sync engine — the outbox, the reconciliation on `online_id`, the six
collections — is written and tested against those shapes, and has 2,124 tests
holding it there.

So the v19 contract is not a legacy burden to be tolerated. It is the contract
**two of the three clients speak**: the new Flutter app and the shipped Android
1.9.0. The third, iOS 1.6.6, speaks v8. Reimplementing v19 faithfully, over the
same database, with the security holes closed, is the smallest cutover
available and the one where the fewest people's step work can go missing.

`/api/v2` is therefore **additive**. It holds only what the old contract cannot
express:

* a subscription this server verified against Apple or Google
* an account deletion that actually deletes
* a support thread
* an install's push token and platform

Nothing is moved into `/api/v2` because it would be tidier there.

### What this costs

Honesty about it: the v19 shapes are not good. `get_app_settings.php` answers
under `data` while every other endpoint answers under `response`. Write
responses `strval()` every value, so an id comes back as `"7"`. The nightly
inventory's switches are `sw1..sw7, sw9..sw12` because `sw8` was skipped by
accident years ago. `id` and `online_id` mean different things and both appear
in the same request.

Each of those is preserved exactly, with a comment saying why, because the
alternative is a client release and a flag day. They are ugly in a way that is
confined to the controller edge — the models, the services and the console
never see them.

## 2. The rule that keeps the cutover reversible

> **This application never alters a legacy table.**

The step-work tables (`inventories`, `amends`, `nights`, `mornings`,
`journals`, `gratitudes`), `accounts`, `account_details` and `appsettings`
already exist in `data_12steptoolkit` and have since 2019. Both old APIs read
and write them. The application **adopts** them: it reads and writes the
columns that are there, and it does not add one, rename one, change a type or
add a constraint.

New state goes in new tables, keyed by `accounts.id`. That is why there is an
`account_security` table rather than three new columns on `accounts`, and an
`entitlements` table rather than trusting `accounts.subscribed`.

Three consequences follow, and all three are good:

1. **The old scripts can be switched back on at any point** during the cutover
   and will find exactly the database they expect. The rollback is a DNS or
   document-root change, not a migration.
2. **`php artisan migrate` is safe to run against production.** It cannot
   damage a table it never names. This matters because the alternative —
   a migration that reshapes a live table holding people's Fourth Step —
   is the single most dangerous operation in this project.
3. **The two APIs can run side by side.** During the overlap, an Android 1.9.0
   install on the old scripts and a 2.0 install on this application are writing
   to the same rows and both are correct.

### Where this differs from AA Big Book

AA Big Book was re-platformed: new schema, migrated data, one source of truth.
That was right there, because its legacy data was small, well-shaped and
read-mostly.

It is wrong here. This database holds people's inventories, amends and nightly
reviews — writing nobody else has a copy of, because `allowBackup="false"` meant
the OS never saved it and the dirty-flag bug meant free users' writing was never
uploaded. A re-platforming migration is a one-way door with somebody's Fourth
Step on the other side of it. Adoption keeps the door open.

*This is a deliberate departure from the AA Big Book pattern and is called out
here so nobody "fixes" it later by writing the migration that was skipped.*

### The one grey area

`install_secrets` was added by the 2024 Android security patch set, not by the
original schema. It is treated as adopted — read and written, never altered —
because the shipped Android client's `bootstrap_secret.php` call depends on
rows in it. Whether it exists in production is one of the open questions; see
`docs/OPEN_QUESTIONS.md`.

## 3. Three applications, one checkout

`bootstrap/app.php` keeps them apart, and the separation is enforced by
middleware rather than by convention:

```
/api/v2/*    token auth, JSON envelope, no cookie, no session
/api/v1/*    legacy auth per protocol, legacy envelope, no cookie
/console/*   cookie session, CSRF, the only part with a login form
```

Two details that are easy to get wrong:

* **`apiPrefix: ''`.** Laravel's default would prefix every API route with
  `api/`, which mangles the legacy paths. The apps in the field have their base
  URL compiled in — `/12steptoolkit.com/aa/android/19/<script>.php` and
  `/8/<script>.php` — so every prefix in `routes/api.php` is written out in
  full and the automatic one is switched off.
* **`ConsoleAuthenticate` runs before `SubstituteBindings`.** A stranger asking
  for `/console/accounts/7` is sent to the login page, not told whether account
  7 exists.

### Paths

Both legacy hostnames are matched by a wildcard prefix, so the same application
serves either whatever the document root turns out to be on the Plesk vhost:

```php
Route::prefix('api/v1/android/19')->group($android);        // canonical
Route::prefix('{legacyPrefix}/19')->where([...])->group($android);  // live
```

The canonical paths exist for anything new; the wildcard is what the installed
apps hit.

## 4. The legacy layer, hole by hole

Every item below is a live behaviour of the current production scripts. The
replacement's behaviour is on the right.

### v19 — Android

| The old scripts | This application |
|---|---|
| `auth_checker.php` reads `X-Nonce` and throws it away, with a comment saying replay protection is still to do. A captured request is replayable for the whole 300-second window. `signature_checker.php` in the same directory does it properly and no endpoint includes it. | The canonical string comes from `auth_checker.php` (because that is what the clients sign); the semantics come from `signature_checker.php`. The nonce is claimed in `request_nonces`, and **only after a good signature** — so a wrong guess cannot burn a real client's nonce. |
| Every login mints a 296,000,000-second token — about 9.4 years — with no revocation list. One leaked token was a permanent account takeover. | Sanctum tokens, `TOKEN_TTL_DAYS` (60), capped per user, revocable. The legacy JWT is still *accepted* for tokens already in the field. |
| `account_id` comes from the POST body. The only thing stopping one person writing into another's Fourth Step is the `AND accountid = ?` clause. | The account comes from the token. The clause is **still applied as well** — belt and braces, because the clause is one line and the failure mode is somebody else's inventory. A body naming a different account is refused with 403 rather than quietly scoped, so a mis-built client is a visible error instead of a silent no-op. |
| An UPDATE that matched nothing reports success, so a client retries for ever against a row somebody deleted on another device. | 404. |
| No rate limit on `login_email.php` or on verification-code requests. | `throttle:legacy-login`, `throttle:legacy-bootstrap`, plus per-identity daily limits in `config/legacy.php`. |
| `login_google.php` takes an email address and nothing else, and signs in whoever owns it. | An `id_token`, verified against Google's JWKS and the configured client ids. Email-only sign-in is refused. **This needs a one-line change in the Flutter client — see §7.** |

`bootstrap_secret.php` is the weak point that could not be closed: it returns
the signing key to any valid bearer token, because you cannot sign a request
before you hold the key. So the signature gives replay protection and device
binding, not a second factor. That is the old design, it needs a client release
to change, and it is written down in `docs/OPEN_QUESTIONS.md` rather than left
looking fine.

### v8 — Apple

The v8 authentication scheme is one static shared secret, compared with `!=`,
compiled into every App Store build of 1.6.6 — and additionally printed into an
`<input value="…">` in a `test.html` that is served over the web. It proves
nothing.

Accepting it keeps every iOS user working through the cutover. Refusing it
bricks them. So it is accepted, and it is contained:

* it no longer implies *who* you are. Every read and write is scoped by an
  ownership clause. `8/deleterecord.php` runs `delete from $t where id='$id'`
  with no account clause at all.
* `8/updatefield.php` lets the caller name the column to write:
  `UPDATE accounts SET $field = '$value'`. The replacement has an allow-list,
  and `email`, `password`, `hashed_password` and `subscribed` are not on it.
* it is rate-limited per address, which a static secret in a binary needs and
  never had.
* an account is **sealed** the first time it signs in on 2.0; after that the
  legacy paths refuse it entirely. The exposure shrinks with every upgrade
  instead of lasting for ever.
* `LEGACY_V8_ENABLED=false` turns the whole thing off in one line.

What cannot be fixed without breaking the old client: somebody holding the
secret who already knows an account id can still read that account's records.
That is the old app's design, and the only cure is users upgrading. It is the
strongest argument for a short overlap.

Three more v8 facts worth knowing before anybody treats the old behaviour as a
requirement:

* `mail_forgotpassword.php` **emails the user their plaintext password.** It is
  not being ported. The replacement sends a one-time code.
* `login.php`, `getcounts_apple.php`, `sobrietydate.php` and `loguservisit.php`
  use `mysql_*`, removed in PHP 7, and **cannot run at all.** iOS
  email-and-password sign-in has been dead for years.
* three endpoints return the executed SQL to the caller as `"success " . $sql`.
  The literal `"success "` — trailing space included — is kept because clients
  compare against it. The SQL is not.

### The plaintext password column

`accounts` carries both `password` (plaintext, which iOS still compares
against) and `hashed_password` (bcrypt, which Android writes). Android's
`login_email.php` blanks the plaintext column on first sign-in, which silently
breaks that person's iOS login. **That is happening in production today.**

`LEGACY_PLAINTEXT_PASSWORD` makes the choice explicit rather than accidental:
`keep` leaves the column alone so both apps work; `clear` blanks it on any
successful sign-in, which is safer and ends iOS email sign-in for that account
until they upgrade. Default `keep` until the cutover is done, then `clear`.

## 5. Inside the application

```
app/
  Http/
    Controllers/Api/V1/Android/    the v19 surface
    Controllers/Api/V1/Apple/      the v8 surface (one controller: see below)
    Controllers/Api/V2/            the new surface
    Controllers/Console/           the back office
    Middleware/                    auth per protocol, request id, security headers
  Models/
    Concerns/LegacyTable.php       zero dates, legacy created/modified columns
    StepRecord.php                 the six collections' shared shape
    Inventory|Amend|Night|Morning|Journal|Gratitude.php
  Services/
    Legacy/CanonicalRequest.php    pure, unit-tested, the signature string
    Legacy/HmacVerifier.php        the whole check, in order
    Legacy/LegacyJwt.php
    Legacy/LegacyEnvelope.php      {status, message, response}
    Auth/IdentityTokenVerifier.php Google/Apple id_token verification
```

### `StepRecord`

Every `*_add_update.php` in v19 is the same forty lines with the column list
changed:

```
online_id == 0                   → INSERT, action: "ADD"
online_id > 0 && is_deleted == 1 → DELETE, action: "DELETE"
online_id > 0                    → UPDATE, action: "UPDATE"
```

So there is one abstract model and six column lists. Three things about it are
load-bearing:

* **`id` is the device's own row id and is echoed back untouched.** The client
  reconciles on it. It is never used for a lookup here.
* **the delete is a hard delete.** The old API has no tombstone on these tables,
  the Flutter client's `is_deleted` is local, and adding a column would alter an
  adopted table. So the row goes, and the client's outbox is what makes that
  idempotent.
* **`$guarded = ['*']` on every model, and `forceFill()` at every write site.**
  Nothing can mass-assign into the tables that hold somebody's recovery. It is
  more typing and it is the right trade.

`projection()` carries the per-column casts because the old readers cast a
specific set to int and leave the rest as strings, and the Flutter and Kotlin
models are written against exactly that.

### Why v8 is one controller

The old API is four multiplexed scripts (`writerecord.php`, `getlist.php`,
`getrecorddetail.php`, `deleterecord.php`) dispatching on a `type` parameter,
plus twenty-one others. Splitting that into twenty-five controllers would
invent a structure it never had and make the mapping back to the original
harder to check. One controller, one dispatch table, the old names in the
comments.

## 6. The console

`/console`, Blade, no build step. Login is a server-created account
(`console:user`); there is no registration page.

**The data boundary, which is a product decision and not a technical one:**

> **Counts and dates and sync state. Never content.**

No inventory, no amend, no journal line, no chat message and no note is
readable from the console, by anybody, at any permission level. A support
person can see that an account has 42 inventories and when the last one was
written — which is everything needed to answer "is my backup working" — and
nothing more.

This is enforced in three places so that one slip does not expose anything:
the console's queries select counts and timestamps only; the models' content
columns are in `dontFlash`; and `console_audit` records every read of an
account page.

## 7. What the Flutter client must change

One item, and it is small: **`login_google.php` must send `id_token`.**

The old endpoint takes an email address and signs in whoever owns it. The
replacement verifies a Google `id_token` against Google's JWKS and refuses
email-only sign-in, because the old behaviour is an account takeover by anyone
who knows an address.

The Flutter app is not released, so this is a change to unreleased code rather
than a compatibility break. Until it is made, Google sign-in in the new app
will be refused with a clear message.

## 8. Operations

One cron entry runs everything: `* * * * * cd <api> && php artisan schedule:run`.
Inside it:

* a heartbeat into the cache, so `app:check` can prove the scheduler runs at all
* the queue worker, one minute of work at a time, never two at once, skipped
  when a real supervisor is configured
* `sanctum:prune-expired`, `queue:prune-failed`, and the nonce sweep

Deployment is `git pull`, `composer install --no-dev --optimize-autoloader`,
`php artisan migrate --force`, `php artisan config:cache route:cache`. No npm.

Secrets live outside the web root; only their paths are in `.env`; `.env` is
not in git and neither is anything in `storage/`.
