# Migration plan

How the people, the data and the traffic move from the old system to this
one. The server sequence — which host changes when, and in what order — is
`docs/CUTOVER.md` and is not repeated here. This is the part about not losing
anybody.

---

## 1. What is actually being migrated

Four things, and they move independently:

| | From | To | Moves when |
|---|---|---|---|
| **The data** | `data_12steptoolkit` | the same database | never — see §2 |
| **The website** | the Next.js export at the document root | this application | the document root moves |
| **The web app** | `web.12steptoolkit.com` | `/my` | the subdomain redirects |
| **The apps** | `/18`, `/7` and the scripts host | `/19` on this application | each member upgrades |

The first line is the important one.

## 2. The data does not move

This application reads and writes the same MySQL database the live apps use.
There is no export, no import, no dual-write window and no cutover moment for
member data. `tests/Support/LegacySchema.php` is the schema as it is, warts
included, and the models are built to it — `accounts.password` is still
plaintext beside `hashed_password`, `nights.sw8` still does not exist,
`comment_threads.created` is still an epoch where everything else is a
datetime.

The migrations add 25 new tables and alter none. Checked against the live
schema dump: no name collides with the 41 that are there. Back up anyway.

**What this buys:** a member whose phone still talks to `/18` and a member on
the new client are looking at the same rows. There is no window in which one
of them is wrong, because there is no second copy to be wrong.

**What it costs:** the legacy scripts cannot be switched off until the apps
that call them are gone. See §5.

## 3. Members

Nobody signs up again, nobody loses their sober date, and nobody is asked to
set a password.

- **The apps**: `login_upgrade.php` mints a token for an install that has an
  account id but no usable session, which is every upgrading iOS user.
- **iOS back-fill**: `check_threads_connections_with_another_account.php`
  rebuilds the threads iOS never had, once per account, from the comments
  that are already there. Idempotent — it is written to survive being run
  twice, because a migration step that must run exactly once eventually
  will not.
- **The web app**: `/my` signs in with the same email and a code. The
  accounts are the same accounts. A member who used
  `web.12steptoolkit.com` yesterday signs in at `/my` today with nothing new
  to remember.

### What is deliberately not carried over

- **Google sign-in on the web.** The old web app had it. `/my` is email and a
  code only: Google sign-in needs an OAuth client against the new host and a
  rule for what happens when a Google address matches an existing account,
  and neither is worth holding the retirement of 38 unauthenticated PHP files
  for. Every member can still sign in.
- **The QR "link device" flow.** The apps own device linking.
- **Anything that depended on `random_device_token`.** It was never checked
  by the server — see `AUDIT_AND_IMPROVEMENTS.md` §1.2 — so there is nothing
  to preserve.

## 4. The website

169 indexed URLs, all answering from this application at the same addresses,
with the title and description of every one pinned against the production
build (`tests/Feature/Site/fixtures/indexed-meta.php`, 507 assertions).

The rollback is pointing the document root back at `out/`. The database is
not involved, so there is nothing to undo.

`docs/WEBSITE_TAKEOVER.md` §4 is the checklist for the day it moves, and §5
the order. The one that bites: `SITE_SERVES_WEBSITE=true` with
`SITE_NOINDEX=true` de-indexes all 169 within days. `deploy.sh` fails the
deploy rather than warning.

## 5. Retiring the old endpoints

This is the end state, and it is reached by waiting rather than by switching.

| Surface | Retired when | Blocked by |
|---|---|---|
| `aa/web/1` (38 files) | `web.12steptoolkit.com` redirects to `/my` | nothing — this can happen as soon as `/my` is deployed |
| `/18`, `/7` | the apps calling them are off the field | members upgrading |

**The web one should not wait.** Those 38 files authorise nothing:
`deleterecord.php` deletes any row by id and `getlist_working.php` returns
any member's inventories to anyone who asks. Nothing on the mobile side has
to happen first.

`/18` and `/7` have the same `deleterecord.php` defect, so the same urgency
applies to them — but they cannot be withdrawn without breaking the apps in
people's pockets. The options, in the order I would take them:

1. Patch `deleterecord.php` and `deleteaccount.php` on the legacy hosts to
   require an account match. Two files, a WHERE clause each.
2. Watch the share of traffic still hitting `/18` and `/7` after the 2.0
   release, and set a date.
3. Withdraw.

Step 1 is worth doing whatever the timetable for 2 and 3.

## 6. Order

1. Deploy this application alongside the export. Nothing about search
   changes. `/console`, `/api/v2` and `/my` answer on the live domain.
2. Test `/my` by hand, against real accounts.
3. Redirect `web.12steptoolkit.com` to `/my`; remove `aa/web/1`.
4. Move the document root; flip both site flags; submit the sitemap.
5. Release the Flutter app against `/19`.
6. Watch `/18` and `/7` traffic fall; set a date; withdraw.

Steps 1–4 are independent of 5. The apps on the field do not notice any of
them, because the database never moves.

## 7. Rollback

| Step | Rollback |
|---|---|
| 1 | remove the four nginx `location` blocks |
| 3 | point the subdomain back; the old files are still there until step 3 removes them, so restore from the backup taken then |
| 4 | document root back to `out/` |
| 5 | staged rollout halt in the stores — see `STORE_RELEASE_CHECKLIST.md` |

None of these touches member data, because none of them moves it.
