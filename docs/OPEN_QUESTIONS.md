# Open questions

Everything in this file is something that **cannot be answered by reading
code** — I have read both script trees, both app repositories and the security
patch set, and the answer is not in any of them. Each one has the one command or
the one screen that settles it.

None of them block the application from running or the tests from passing. All
of them are things that would be found out the hard way at cutover, so they are
written down instead.

Each entry says what this application does *in the meantime*, and in every case
the meantime behaviour is the safe one: nothing is assumed in a direction where
being wrong loses data or access.

---

## A. The schema

There is **no schema dump of `data_12steptoolkit` anywhere** — not in either
script tree, not in the app repositories, not in the security patch set. Column
*names* are solid: every one is read off an INSERT or UPDATE column list in the
live PHP and cross-checked between the two APIs. Column *types* are inferred
from `bind_param` type strings (`i` or `s`, which cannot tell INT from TINYINT,
or VARCHAR from TEXT from DATETIME) and from the casts the readers apply.

`tests/Support/LegacySchema.php` is the written-down version of what this
application believes the schema to be. One command replaces the belief with
fact:

```bash
mysqldump --no-data --skip-add-drop-table --skip-comments data_12steptoolkit > schema.sql
```

Run it, put the output somewhere I can read, and A1–A3 below are answered in
one pass along with every type in that file.

### A1. Does `nights.sw8` exist?

**Why it matters.** The nightly inventory has twelve questions and the switch
columns are `sw1..sw7, sw9..sw12` — eleven of them. `sw8` is skipped. Both
shipped clients omit it, and both APIs' INSERT lists omit it, which is
consistent with the column having never been created and also consistent with
it existing and being dead.

**What this application does.** `Night::SWITCHES` has eleven entries and
`sw8` is **never written**, even if a client sends it (there is a test for
exactly that). If the column exists it stays at its default for ever, which is
harmless. If it does not exist, writing it would be a fatal error on every
nightly save — so the cautious direction is the one taken.

**What answers it.** `SHOW COLUMNS FROM nights LIKE 'sw%';`

### A2. Is `comment_thread_subscribers` unique on `(thread_id, account_id)`?

**Why it matters.** The comment/chat code inserts a subscriber row without
checking for one. If there is a unique index, the second insert is a duplicate-
key error; if there is not, the table grows a row per reply and notifications go
out twice, three times, *n* times.

**What this application does.** The comments surface is not built yet
(`IMPLEMENTATION_STATUS.md` §3), so nothing depends on the answer today. It has
to be settled before that endpoint is written, because the two cases need
different code (`insertOrIgnore` versus a `firstOrCreate`).

**What answers it.** `SHOW INDEXES FROM comment_thread_subscribers;`

### A3. Is `comment_thread_subscribers.subscribed_at` an INT or a DATETIME?

Both APIs bind it as `s`, which tells us nothing — a Unix timestamp and a
`'2019-06-01 12:00:00'` string are both `s`. Every other timestamp in this
schema is an INT Unix timestamp *except* `created` and `modified`, which are
DATETIME, so there is no pattern to infer from.

**What answers it.** The same `SHOW COLUMNS`.

### A4. Does `install_secrets` exist in production?

**Why it matters.** This is the biggest of the five and it is not really a
schema question. `install_secrets` was introduced by the **2024 Android
security patch set**, which I found in the scripts folder and which — as far as
I can tell from the live scripts — **was never deployed**. `19/Ztest_jwt.php`
is still present. `signature_checker.php` (the one that does replay protection
properly) is still not included by any endpoint.

If the patch set never shipped, then:

* `install_secrets` does not exist, and
* the shipped Android 1.9.0's `bootstrap_secret.php` call has been failing, and
* the HMAC signature has never actually been verified in production.

**What this application does.** It treats `install_secrets` as adopted — reads
and writes `account_id`, `device_id`, `secret` BINARY(32), `status` — and
requires an active row for every signed request. If the table does not exist,
`php artisan migrate` will not create it (adopted tables are never created by a
migration here) and every signed request fails closed. **That is a cutover
blocker and it is the one thing on this list that will stop the Android app
working on day one.**

**What answers it.** `SHOW TABLES LIKE 'install_secrets';` — and if the answer
is "no", say so and I will add a migration that creates it, which is safe
because a table that has never existed is not a legacy table.

---

## B. Security findings in the live system

These are not questions about what to build. They are things I found that you
should know about, each with what I would do.

### B1. `19/Ztest_jwt.php` mints a token for any account id, to anyone

A file left in the live script directory that issues a 9.4-year JWT for any
`account_id` passed to it, with no authentication. Combined with
`bootstrap_secret.php` — which hands the HMAC signing key to any valid bearer
token — that is a complete account takeover for every account, by anyone who
knows the URL.

**It is live now.** This application does not reproduce it. But this
application is not deployed yet, and the file is.

**What I would do today, before any of the rest of this:** delete
`19/Ztest_jwt.php` from the live server. It is a test file; nothing calls it.
That is a one-minute change that closes the hole regardless of when the cutover
happens.

### B2. The v8 shared secret is published

The Apple API's entire authentication is one static string compared with `!=`.
It is compiled into every App Store build of 1.6.6 **and** printed into an
`<input value="…">` in a `test.html` served over the web, so it is readable by
anyone who has ever loaded that page.

It cannot be rotated without bricking iOS 1.6.6. `ARCHITECTURE.md` §4 lists the
five containments. The real fix is users upgrading, which is an argument for
keeping the overlap short.

**What I would do today:** remove that `test.html` from the live server. It
does not make the secret un-leaked, but it stops it being handed out to new
people.

### B3. `bootstrap_secret.php` returns the signing key to any bearer token

You cannot sign a request before you hold the key, so the endpoint that issues
the key cannot itself be signed. The consequence is that the HMAC layer gives
replay protection and device binding, not a second factor: whoever holds a
token can get the key that goes with it.

Closing it needs a client release (the key would have to be derived at install
time from something the server never sees, or delivered through an attested
channel). The new Flutter client *could* do that. **Deciding to change it means
the new app can no longer use the v19 bootstrap unchanged**, which is the one
place where the "v19 is the main contract" decision would start to cost
something. I have left it as the old behaviour and flagged it rather than making
that call.

### B4. `mail_forgotpassword.php` emails the user their plaintext password

Not being ported. The replacement sends a one-time code. Mentioned here because
it means the plaintext `password` column has been readable *and used* recently
enough that someone may still rely on the flow.

---

## C. Product decisions I could not make for you

### C1. The Google product ids are not confirmed

`config/billing.php`'s `google.products` is a guess and is marked as one.

The Android app buys through **RevenueCat packages** — `$rc_weekly`,
`$rc_three_month`, `$rc_annual`, `$rc_lifetime`, plus `sponsee_quarterly` and
`sponsee_annual` — and a package identifier is **not** a Play product id. The
mapping from one to the other lives in the RevenueCat dashboard, not in either
binary, so it cannot be read out of the code at all.

The Apple side *is* confirmed: the ids come from the project's own
`12 Step Toolkit.storekit`, which is the local mirror of App Store Connect. Two
subscription groups exist there, which is history rather than design — group
20509670 "Unlock Premium" holds the original `…annual`, group 20641515 "Unlock
All Features" holds the current `…annual1` and `…quarterly1`. Anyone still on
the old group keeps their subscription; it is simply not sold any more. Both
grant.

**What this application does meanwhile.** A purchase of an unlisted product is
**recorded and grants nothing from this server**, and the console shows it as
unmapped. Nobody loses access, because every current subscriber is granted
through the RevenueCat read-only bridge.

**What answers it.** Play Console → Monetize → Subscriptions, or RevenueCat →
Products. Paste the list and I will fill it in.

### C2. `inventories.shared` and `inventories.reviewed` — which is which?

Both are tinyints on `inventories` and `amends` and both appear in the old
writers. `shared` has a `shareddate` beside it; `reviewed` does not.

My reading is that `shared` means "shown to my sponsor" (hence the date) and
`reviewed` means "my sponsor has marked this as gone through". But the
mark-as-reviewed endpoint is one of the ones I have not built yet, and the two
apps' UIs use the words differently in their strings, so I would rather ask than
guess — getting it backwards means an inventory showing as reviewed when it has
only been shared, which is exactly the kind of wrong that matters in step work.

**What answers it.** You, in one sentence. Or the sponsor-facing screen in the
shipped app, if you can tell me what the two states look like to a sponsor.

### C3. Should a cancellation clear `accounts.subscribed`?

`accounts.subscribed` is a tinyint that **no code path in either old API ever
clears**. There is no webhook anywhere in the old system, so a cancellation, a
refund or an expiry was never learned — the column therefore answers "has this
person ever paid", not "is this person premium".

Both old apps read it. Which means there are people in the field whose premium
today rests on that flag having been set years ago, for a subscription they
stopped paying for.

**What this application does.** `EntitlementService` writes the column when it
grants access and **never clears it**. Clearing it would take access away from
people who have it right now, which is a product decision rather than a
tidy-up, and not one I am going to make quietly inside a service class.

**What answers it.** You. Three options, and the middle one is what I would do:

* leave it as it is — nobody loses anything, and some non-payers keep premium on
  the old apps until they upgrade;
* clear it **only for accounts that are sealed** (already on 2.0, so the flag is
  no longer what their access depends on) — tidies the data with nobody affected;
* clear it on any lapse — correct, and it will take premium away from an unknown
  number of people on 1.9.0 and 1.6.6 with no warning and no way for them to
  tell what happened.

`SELECT COUNT(*) FROM accounts WHERE subscribed = 1;` against the number of
live subscriptions in RevenueCat says how large the gap is.

### C4. Is `accounts.email` meant to be non-unique?

It is not unique in production — `tests/Support/LegacySchema.php` reproduces
that deliberately, with a comment. There are almost certainly duplicate
addresses in there, because nothing ever stopped one being created.

**What this application does.** Email sign-in matches the *first* row by
`email`, which is what the old scripts do, so behaviour is unchanged. It also
means that if two accounts share an address, one of them can never be signed
into by email.

**What answers it.**
`SELECT email, COUNT(*) c FROM accounts WHERE email <> '' GROUP BY email HAVING c > 1;`
If that returns rows, it is worth a console screen to merge them, and I would
add one.

### C5. The Google product ids, again, but for the console

`console/subscriptions` has a saved view called **Unknown product** whose whole
purpose is to be empty. While C1 is unanswered it may not be, and a row in it is
a purchase that granted nothing from this server. Worth a look after the first
day of real traffic.

---

## D. Confirmed, recorded here so nobody re-opens them

* **Bundle identifiers are unchanged.** `com.ibyteapps.aa12steptoolkit`
  (Android) and `com.12stepapp.recoverybox` (iOS). Changing either would be a
  new app in the stores and would lose every existing subscription.
* **One database, both platforms.** `data_12steptoolkit` is shared by v19 and
  v8 and will be shared by this application. There is no per-platform database.
* **The app user id for RevenueCat is `accounts.id` as a string**, which is what
  the old apps already send, so no identity mapping is needed.
* **Cloud backup is free for everyone** (`DECISIONS.md` D-001). Android cleared
  the six step-work dirty flags for non-subscribers, so a free user's writing
  was never uploaded, and `allowBackup="false"` meant the OS could not save it
  either. There is no entitlement check on the sync path;
  `SYNC_NEEDS_SUBSCRIPTION` exists only so that re-introducing one would be a
  visible act.
