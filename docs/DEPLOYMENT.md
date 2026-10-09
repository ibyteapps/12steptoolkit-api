# Deploying the 12 Step Toolkit API

The first install, then one command for ever after:

```bash
app_update_toolkit
```

Same shape as BohriConnect's `app_update` and AA Big Book's
`app_update_bigbook`: run it as root from anywhere, it switches to the site's
user, pulls `origin/main`, takes the site down behind a 503 only if it has to,
migrates, rebuilds the caches, reloads PHP-FPM, smoke-tests the live URLs and
runs `app:check`. If anything fails before a migration ran, it puts the
previous commit back and brings the site up on it by itself.

---

## 0. Where this goes, and what it does not touch

| | |
|---|---|
| Folder | `/var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api` |
| Host | `12steptoolkit.com` — **no new subdomain** |
| Document root | **unchanged**: `WEBSITES/12steptoolkit.com/out` |
| Reached at | `/console`, `/api/v2/*`, `/up` — routed by nginx |
| Repository | `github.com/ibyteapps/12steptoolkit-api` (private) |
| Database | the existing `data_12steptoolkit` — **adopted, never altered** |

The application sits in `api/`, a **sibling** of the document root, so nothing
in it — `.env` included — is reachable from the web. nginx sends three
prefixes to it and leaves everything else alone.

That is the whole arrangement, and it is what makes this installation
unusually safe to try: **the three prefixes are 404s on this domain today.**
If any of it is wrong they stay 404s. No page the website serves passes
through this application at all.

Three things stay exactly as they are:

* **The website.** `12steptoolkit.com`'s document root is still
  `WEBSITES/12steptoolkit.com/out`, the Next.js export — 169 indexed URLs,
  `sitemap.xml`, `robots.txt`, `app-ads.txt`, `reflections.php` and the
  `/app/reset/*` landing pages — served by the same nginx → Apache proxy, by
  the same rules, from the same files. `docs/WEBSITE_TAKEOVER.md` is what
  would have to be true to change that, and it is separate work you may never
  need.
* **The legacy scripts.** `scripts.12steptoolkit.com/…/19/*` and
  `apple.12stepapp.com/8/*` go on answering the two shipped apps.
  `docs/CUTOVER.md` steps 4 and 5 move them, later, one at a time, each with a
  one-move rollback.
* **The adopted tables.** `php artisan migrate` here creates this
  application's own tables only and names no adopted table, so a deploy cannot
  damage the data the shipped apps are reading and writing.

**`/console` is inside the main website from day one** — at
`12steptoolkit.com/console`, which is what you wanted and what the nginx graft
buys. It needs no subdomain and no change to how a single page of the site is
served.

## 1. Server prerequisites

* PHP **8.4** (8.4.1 or newer — `composer.lock` is resolved for 8.4), CLI at
  `/opt/plesk/php/8.4/bin/php`, with `pdo_mysql`, `mbstring`, `openssl`,
  `curl`, `fileinfo`, `tokenizer`, `xml`, `dom`, `ctype`, `intl`, `zip`,
  `bcmath`. `php artisan app:check` names anything missing.
* Composer 2 as a `.phar` — Plesk's is at
  `/opt/psa/var/modules/composer/composer.phar`, and `deploy.sh` finds it.
  (It refuses Plesk's `composer` shell wrapper on purpose: the wrapper picks
  its own older PHP, and feeding it to PHP prints it as text and exits 0 — a
  deploy that installs nothing, quietly.)
* `git`, `curl`, `flock`. **No Node.js** — the console's CSS is hand-written
  and lives in `public/`; `deploy.sh` stops the deploy if a Blade template ever
  starts using `@vite`.
* MariaDB 10.6+ / MySQL 8, holding `data_12steptoolkit` already.

Find the **site's system user** — the owner of the `ibyteserver.com` webspace.
Everything below runs as that user; `SITEUSER` means whatever this prints:

```bash
stat -c %U /var/www/vhosts/ibyteserver.com/WEBSITES
```

Run server commands from a root SSH session. Plesk's chrooted shell has neither
`git` nor the Plesk PHP binaries. Where a step says *as the site user*, start
that shell with `sudo -u SITEUSER -H bash -l`.

## 2. On your Mac: refresh the lock, then make the zip

`composer.json` and `composer.lock` currently disagree about `composer.json`'s
content hash, so every `composer install` prints "the lock file is not up to
date". Harmless — it still installs from the lock — but fix it once so the
deploy log stays readable. Only the hash should change:

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
composer update --lock
git diff --stat composer.lock
```

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
git add composer.lock && git commit -m "Refresh the lock hash" && git push origin main
```

Then the zip. A **fresh clone**, so it carries every committed file and its git
history and nothing else — no `vendor/`, no `.env`, nothing ignored:

```bash
cd /Users/tushar/Work/Websites/12StepToolkit/api
git status
```

```bash
rm -rf /tmp/toolkit-upload
git clone -q /Users/tushar/Work/Websites/12StepToolkit/api /tmp/toolkit-upload
```

```bash
cd /tmp/toolkit-upload
zip -qr ~/Desktop/12steptoolkit-api.zip .
ls -lh ~/Desktop/12steptoolkit-api.zip
```

`git status` should say "up to date with 'origin/main'" with nothing to commit.
The clone copies what is **committed**, so anything uncommitted is simply not
in the zip.

## 3. In Plesk: the folder, then the four prefixes

No new subdomain, and **do not touch Hosting → Document root.** The only
Plesk change is in one text box.

1. **File Manager:** open `WEBSITES/12steptoolkit.com` — the folder that holds
   `out` — and create `api` beside it. Upload `12steptoolkit-api.zip` into
   `api` → tick it → **Extract Files** into this folder (overwrite if asked) →
   delete the zip.

   It must be a sibling of `out`, not inside it. `out` is the document root; a
   folder inside it would put `.env` one URL away from anybody.

2. **Websites & Domains → 12steptoolkit.com → Apache & nginx Settings →
   "Additional nginx directives":** paste the contents of
   **`docs/nginx/12steptoolkit.com.conf`** underneath what is already in the
   box (the gzip settings and the three `/app/*` blocks). Keep those; the new
   blocks go after them.

   Plesk runs `nginx -t` before saving, so a typo is refused rather than
   applied. If it refuses, nothing changed.

3. **PHP:** nothing to change. The application uses the PHP-FPM pool this
   domain already has, on the socket the existing `location ~ \.php` block
   uses. Check it is **8.4** and that `memory_limit` is at least 256M.

4. **SSL:** nothing to do. The certificate already covers this host.

That is the entire server-side configuration. The file you pasted says what
each block is for and names the two details that are the difference between
working and not.

### If `/console` returns 500 rather than a login page

One cause, and it is quick to confirm: PHP's `open_basedir` for this domain.
Plesk's default covers the whole webspace (`/var/www/vhosts/ibyteserver.com/`),
which includes `api/` — but if yours has been narrowed to the document root,
PHP may not read a file outside `out`. The FPM log says so in as many words:

```bash
tail -40 /var/www/vhosts/system/12steptoolkit.com/logs/error_log
```

Fix it in **Websites & Domains → PHP Settings → `open_basedir`**, adding the
webspace root, then run `app_update_toolkit --check` again.

## 4. Make it an exact git checkout

A zip can lose a file's executable bit, and a copy made on a Mac says file
names are case-insensitive. `git reset --hard` rewrites every file exactly as
committed and puts both right.

```bash
stat -c %U /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
```

```bash
sudo -u SITEUSER -H bash -l
```

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
git config core.ignorecase false
git reset --hard HEAD
git status --short
```

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
chmod -R u+rwX storage bootstrap/cache
ls -l artisan deploy.sh bin/app_update_toolkit
```

`git status --short` with no output is what you want. If it lists files
starting `??`, Plesk has put a default page into the new document root — delete
exactly those in File Manager and look again; the deploy refuses to run while
the checkout holds files git does not know about. All three files in the last
command should be `-rwxr-xr-x`.

## 5. The deploy key, so `app_update_toolkit` can pull

Still as the site user:

```bash
cd ~
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/toolkit_deploy -N "" -C "12steptoolkit-api deploy (ibyteserver)"
cat ~/.ssh/toolkit_deploy.pub
```

GitHub → **ibyteapps/12steptoolkit-api → Settings → Deploy keys → Add deploy
key**: title `ibyteserver`, paste that one line, leave **Allow write access**
unticked → Add key.

```bash
cd ~
cat >> ~/.ssh/config <<'EOF'
Host github-12steptoolkit
    HostName github.com
    User git
    IdentityFile ~/.ssh/toolkit_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

```bash
ssh -T github-12steptoolkit
```

Answer `yes` once; expect "Hi ibyteapps/12steptoolkit-api! You've successfully
authenticated". Then point the checkout at it:

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
git remote set-url origin github-12steptoolkit:ibyteapps/12steptoolkit-api.git
git fetch origin
git status
```

The host alias keeps this key apart from any other GitHub key the user has. Do
**not** attach Plesk's Git extension to this folder: it copies files instead of
keeping a git checkout, and the deploy works with a checkout.

## 6. `.env`

As the site user. `.env.example` is the full list with a comment on each; what
follows is only the lines that are not obvious.

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
cp .env.example .env
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
chmod 600 .env
```

Then edit it (`nano .env` — **not** Plesk's File Manager, which has a habit of
saving an older copy over a fix; `deploy.sh` refuses to run if any key appears
twice):

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://12steptoolkit.com` — the host it answers on, even though it serves only three prefixes of it |
| `SITE_NOINDEX` | `true` — this installation does not serve the website. §9 |
| `SITE_SERVES_WEBSITE` | `false` — the document root is still the static export. §9 |
| `DB_CONNECTION` | `mysql` |
| `DB_DATABASE` | `data_12steptoolkit` |
| `DB_USERNAME` / `DB_PASSWORD` | the existing credentials from `19/config.php` |
| `LEGACY_V19_ENABLED` | **`false`** — the Android scripts still answer where they are |
| `LEGACY_V8_ENABLED` | **`false`** — likewise the Apple ones |
| `LEGACY_JWT_SECRET` | from `19/config.php` |
| `LEGACY_SERVER_SECRET` | from the v8 config |
| `APP_KEY` | set above. **Keep a copy somewhere safe** — losing it makes every encrypted column unreadable |

Keep both legacy flags off for the first deploy. The application answers its
own `/api/v2` surface and the legacy routes exist but stay shut, which is the
whole point of deploying alongside: nothing in the field can reach this yet.

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
php artisan migrate --force
```

Those migrations create this application's own tables — `entitlements`,
`store_subscriptions`, `install_secrets`, the console tables and the rest. They
name no adopted table.

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
php artisan app:check
php artisan console:user you@example.com
```

`console:user` prints a single-use set-password link. There is no registration
page and no `--password` flag, so **nothing can sign in to the console until
this has been run once.**

## 7. Install the root command

As root, from a root-owned copy — never the one in the checkout, which the
site's user can change:

```bash
install -o root -g root -m 755 \
    /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api/bin/app_update_toolkit \
    /usr/local/sbin/app_update_toolkit
```

```bash
sed -i 's|^APP_DIR_INSTALLED=.*|APP_DIR_INSTALLED=/var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api|' \
    /usr/local/sbin/app_update_toolkit
```

```bash
app_update_toolkit --check
```

`--check` changes nothing: it reports what would be pulled, whether migrations
are pending, `app:check`, and the smoke test. Then the real thing:

```bash
app_update_toolkit
```

That first run also writes `/etc/cron.d/12steptoolkit-api` —
`php artisan schedule:run` every minute as the site's user, which is what runs
the queue worker, the prunes and the nonce sweep. Root rewrites it on every
run, so it always points at this checkout and this PHP. Do **not** also add a
Plesk Scheduled Task for it; the command warns you if you have, because the two
together run everything twice a minute.

Expect the smoke test to report:

```
✓ /up → 200
✓ /api/v2/health → 200
✓ /console → 302 → https://12steptoolkit.com/console/login
✓ /console/login → 200
✓ /this-page-does-not-exist → 404
✓ / → 200
✓ /console/login is kept out of search results (X-Robots-Tag: noindex, nofollow)
· / is served by the static export, not by this application — not judged here.
```

The last two lines are the arrangement showing up in the output. `/ → 200` is
still checked, because the commonest way to break that site is an nginx edit
made to route a prefix here — but what `/` *serves* is not this application's
business, so it is not graded.

If you want the first run to skip the URL checks entirely — before the nginx
blocks are in, say — add `SMOKE_TEST=0`:

```bash
SMOKE_TEST=0 app_update_toolkit
```

## 8. Store credentials

Separate, because they are clicks in Apple's and Google's consoles rather than
anything on this server: **`docs/STORE_SETUP.md`**, and

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.com/api
php artisan billing:check
```

which makes one read-only call to each store API and says which half works.
Deliberately **not** part of a deploy: it needs the network, and a store
outage is no reason to fail a deploy.

## 9. The two site flags

```dotenv
SITE_NOINDEX=true
SITE_SERVES_WEBSITE=false
```

`/my` is governed by neither: `MemberSession` sends `noindex, nofollow` and
`no-store` on every one of its pages unconditionally, for the same reason the
console does. Somebody's Step Four is not a page that becomes indexable on the
day the marketing site does.

`SITE_SERVES_WEBSITE` is the one that describes the arrangement: this
application is on `12steptoolkit.com`'s host but does not own `/`. Being on
the website's host is not the same as being the website, and without that
distinction the deploy's smoke test would put a cheerful green tick against a
page the static export serves — which is worse than no check at all. With
`false` it tests what this application actually answers, and reports `/`
without grading it.

`SITE_NOINDEX` keeps this application's own pages out of search results while
that is true. The console is kept out **unconditionally**, by
`SecurityHeaders`, regardless of either flag — the day the website moves here
and `SITE_NOINDEX` goes false must not be the day `/console/login` becomes
indexable.

The one way this bites is the takeover. Move the document root onto this
application and leave `SITE_NOINDEX=true`, and all 169 URLs are de-indexed
within days. So when `SITE_SERVES_WEBSITE=true`, `deploy.sh` **fails the
deploy** rather than warning:

```
✗ 12steptoolkit.com is the public website and it is serving
  X-Robots-Tag: noindex, nofollow — every indexed URL will be dropped.
  Set SITE_NOINDEX=false.
```

Nothing to remember in either direction. `docs/WEBSITE_TAKEOVER.md` has the
rest of that checklist.

## 10. Everyday deploys

```bash
app_update_toolkit              # pull origin/main and deploy it
app_update_toolkit --check      # report only; changes nothing
app_update_toolkit --rollback   # back to the commit before the last deploy
```

A deploy with nothing new to pull, no pending migration and nothing for
composer to do **never takes the site down** — it just rebuilds the caches,
which is what you want after editing `.env`.

When something does fail:

* **before any migration ran** — the previous commit goes back automatically
  and the site comes up on it. Nothing to do but read the error.
* **after a migration ran** — the site stays down **on purpose**, and the
  command prints your three options (fix forward, come up as is, or reverse the
  migration and roll back with `--force`). Old code against a new schema is not
  a decision to make automatically. Note that even here the two shipped apps are
  unaffected: no migration touches a table they use.
* **a smoke test fails** — the deploy is live and the exit code is 1, with the
  rollback command printed.

Every run appends to `storage/logs/deploy.log`, and one line per run to
`storage/logs/deploy-history.log`.

`--rollback` refuses by default if any migration changed between the two
commits, and tells you what to read first. `--force` overrides it.

## 11. Rolling the whole thing back

Two steps, neither of which touches the website:

1. **Plesk → Apache & nginx Settings:** delete the blocks you pasted in §3,
   keeping the gzip settings and the `/app/*` ones. `/console`, `/api/v2` and
   `/up` go back to being 404s, which is what they were.
2. **File Manager:** delete the `api` folder, if you want the disk back.

The document root was never changed, the legacy script hosts were never
touched, and the database has this application's own tables in it which
nothing else reads. That is the entire rollback — and if you only do step 1,
the application is simply unreachable, with everything intact for when you
come back to it.
