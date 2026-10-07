# Cutover

The decision (2026-10-07): **full replacement, one cutover.** The v19 and v8
script hosts are pointed at this application and switched off. There is no
long-running dual-write period and no gradual endpoint-by-endpoint migration.

What makes that safe is not confidence — it is that **the database does not
change**. This application adopts the existing tables and never alters one
(`ARCHITECTURE.md` §2), so the rollback at every step below is "point the
document root back" and nothing else. No data is converted, so there is nothing
to convert back.

Read this whole file before starting. Steps 0 and 1 can be done today and are
worth doing whether or not the cutover follows.

---

## Step 0 — today, independent of everything else

Two files on the live server are holes right now. Neither has anything to do
with this application; both close in a minute.

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/<the scripts vhost>/19
```

Delete `Ztest_jwt.php`. It mints a 9.4-year token for any account id, to
anyone who knows the URL, with no authentication. Nothing calls it.

Then find and delete the v8 `test.html` that carries the shared secret in an
`<input value="…">`. The secret is already leaked and cannot be rotated without
bricking iOS 1.6.6, but there is no reason to keep handing it out.

`docs/OPEN_QUESTIONS.md` §B has the full description of both.

## Step 1 — answer the three questions that block the cutover

From a MySQL prompt on the server:

```sql
SHOW TABLES LIKE 'install_secrets';
SHOW COLUMNS FROM nights LIKE 'sw%';
SELECT email, COUNT(*) c FROM accounts WHERE email <> '' GROUP BY email HAVING c > 1;
```

And the one that answers every schema question at once:

```bash
cd ~
mysqldump --no-data --skip-add-drop-table --skip-comments data_12steptoolkit > schema_12steptoolkit.sql
```

Zip that and I will read it.

**`install_secrets` is the blocker.** If it does not exist, every signed Android
request fails closed on day one. It takes one migration to fix and it must be
known *before* the switch, not discovered during it.

---

## Step 2 — deploy alongside, answering nothing

Put the application on the server at its own path, with its own vhost or
subdomain, and leave the old scripts exactly where they are and still serving.
Nothing points at the new application yet.

Build the archive locally:

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
composer install --no-dev --optimize-autoloader
```

```bash
cd /Users/tushar/Work/Websites/12StepToolkit
zip -r api-deploy.zip api -x "api/.env" -x "api/.git/*" -x "api/storage/logs/*" -x "api/tests/*" -x "api/node_modules/*"
```

Upload `api-deploy.zip` through **Plesk → File Manager** into the vhost
directory and extract it there. Then, over SSH:

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
```

```bash
chmod -R 775 storage bootstrap/cache
```

```bash
cp .env.example .env && php artisan key:generate
```

Fill in `.env` — `DB_*` pointing at `data_12steptoolkit`, `LEGACY_JWT_SECRET`
from `19/config.php`, `LEGACY_SERVER_SECRET` from the v8 config — and then:

```bash
chmod 600 .env
```

```bash
php artisan migrate --force
```

That migration creates only this application's own tables. It names no adopted
table and cannot damage one.

```bash
php artisan config:cache && php artisan route:cache
```

Point the vhost's document root at `api/public`, not `api`.

### Prove it before anything depends on it

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
```

```bash
curl -s https://<new-host>/api/v2/health
curl -s https://<new-host>/up
```

Then, with the legacy flags still **off** (`LEGACY_V19_ENABLED=false`,
`LEGACY_V8_ENABLED=false`), check that `get_app_settings.php` answers under
`data` and that a signed `get_counts.php` is refused. That is the shape of the
old contract proven without a single real client touching it.

### The cron entry

Plesk → Scheduled Tasks, every minute:

```
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api && php artisan schedule:run
```

Everything else — the queue worker, the prunes, the nonce sweep — runs inside
that one entry.

**Rollback:** delete the vhost. Nothing was touched.

---

## Step 3 — the new Flutter app, against the new server

The new app is not released, so it is the safest possible first real client:
every install is one you chose.

Point a debug build's `API_BASE_URL` at the new host, turn on
`LEGACY_V19_ENABLED=true` there, and run the whole app against it — sign-in,
all six collections writing and reading back, the sobriety calculator, the
counts, a delete, an offline queue that drains.

**One client change is needed first:** `login_google.php` must send `id_token`
rather than an email address. The replacement verifies the token against
Google's JWKS and refuses email-only sign-in, because the old behaviour signs in
whoever claims an address. See `ARCHITECTURE.md` §7.

**Rollback:** change the build's base URL back. No server change.

---

## Step 4 — move the Android script host

This is the real switch, and it is the reversible one.

`scripts.12steptoolkit.com/12steptoolkit.com/aa/android/19/*` is what every
shipped Android 1.9.0 calls. Point that path at this application — in Plesk,
change the document root for that host, or add an NGINX rule — with
`LEGACY_V19_ENABLED=true`.

Before: copy the `19/` directory somewhere safe so the old scripts can be put
back with a move rather than a restore.

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/<the scripts vhost>
```

```bash
cp -a 19 19.before-cutover
```

Watch, in this order:

1. `/api/v2/health`
2. the application log for `v19 endpoint requested before it exists`
3. the 401 rate on signed requests. **A spike here means `install_secrets` is
   wrong or missing** — that is the failure this cutover has, and it fails
   closed, so nobody's data is lost while it is happening.
4. the write rate across the six collections, against the same hour yesterday

Give it a full day before step 5. Android 1.9.0 syncs on app open, so a day is
roughly one pass through the active users.

**Rollback:** point the document root back at `19.before-cutover`'s contents.
The database is unchanged and the old scripts will find it exactly as they left
it. Any row written through the new application in the meantime is a normal row
in a normal table and the old scripts read it fine — **that is the whole point
of never altering a legacy table.**

---

## Step 5 — move the Apple script host

**Not yet possible.** The v8 layer is a documented 503 and the route group is
off by default, so pointing `apple.12stepapp.com` at this application today
would break iOS 1.6.6. `IMPLEMENTATION_STATUS.md` §3 has what is left.

When it is built, the same pattern applies: copy `8/` aside, switch the root,
turn on `LEGACY_V8_ENABLED`, watch the 401 and write rates, and keep the
rollback one move away.

Two things to decide at that point and not before:

* `LEGACY_PLAINTEXT_PASSWORD` moves from `keep` to `clear` once iOS email
  sign-in no longer matters. While it is `keep`, both apps work and the
  plaintext column survives; `clear` is safer and ends iOS email sign-in for
  each account as it signs in.
* `legacy.seal_on_v2_login` is already `true`, so every upgrade to 2.0 shrinks
  the v8 exposure by one account. The console's cutover page is where that
  number should be read off.

---

## Step 6 — release 2.0, and watch the overlap shrink

Three populations exist during the overlap and all three are correct:

| Population | Talks to | Authenticated by |
|---|---|---|
| Android 1.9.0 | this application, v19 paths | JWT + per-install HMAC |
| iOS 1.6.6 | the old v8 scripts, then this application | the published shared secret |
| 2.0, both platforms | this application, v19 paths + `/api/v2` | Sanctum, properly |

Sealing is what ends it: the first time an account signs in on 2.0, the legacy
paths stop answering for it. So the exposure is a number that only goes down,
and the console should show it.

Switch `LEGACY_V8_ENABLED=false` when the iOS population is small enough that
you are willing to tell the rest to upgrade. Switch `LEGACY_V19_ENABLED=false`
when the sealed count is close enough to the account count. Neither needs a
deploy — both are one line in `.env` and `php artisan config:cache`.

---

## What would make me stop and ask you

Written down now, so the answer is not improvised at 11pm:

* **the 401 rate on signed requests does not settle within an hour of step 4.**
  Roll back. It means `install_secrets` or the canonical string is wrong, and
  guessing at it with live traffic is how a day's writing goes missing.
* **the write rate across the six collections drops by more than a tenth**
  against the same hour the day before. Roll back and find out why before
  anything else.
* **any request produces a 500 mentioning a column.** That is the schema
  reconstruction being wrong, which is exactly what `docs/OPEN_QUESTIONS.md` §A
  is about. Roll back; it is a one-line fix once the dump is in hand.
