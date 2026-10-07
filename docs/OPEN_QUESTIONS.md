# Open questions

Things that **cannot be answered by reading code** — I have read both script
trees, both app repositories and the security patch set, and the answer is not
in any of them. Each one carries the one command or screen that settles it.

**Section A is now answered**, by the structure-only schema dump of 2026-10-07
(`docs/reference/legacy-schema.sql`). It is kept here rather than deleted
because two of its answers changed the code — and because A6, which the dump
turned up unasked, is a live bug that is corrupting what people write.

Each entry says what this application does *in the meantime*, and in every case
the meantime behaviour is the safe one: nothing is assumed in a direction where
being wrong loses data or access.

---

## A. The schema — **answered**

`docs/reference/legacy-schema.sql` is a structure-only dump of the live
`data_12steptoolkit` (MariaDB 11.4, 2026-10-07, 41 tables, no rows and no
credentials). Everything in this section was open until it arrived.

`tests/Support/LegacySchema.php` is now a **transcription** of that dump rather
than a reconstruction, which changes what the test suite is worth: it was
holding the application to my reading of the old PHP, and now it holds it to the
database.

### A1. Does `nights.sw8` exist? — **No.**

Eleven switches (`sw1`–`sw7`, `sw9`–`sw12`), twelve answers (`desc1`–`desc12`).
So question 8 has an answer column and no switch, and never had one. The
implementation already never wrote it, which was the cautious direction and
turns out to have been the correct one.

### A2. Is `comment_thread_subscribers` unique on `(thread_id, account_id)`? — **Yes.**

`UNIQUE KEY uq_thread_account (thread_id, account_id)`. So joining a thread is
an upsert on that pair: an insert would be a duplicate-key error on the second
reply, and a check-then-insert would race. This unblocks the comments and chat
endpoints.

### A3. Is `comment_thread_subscribers.subscribed_at` an INT or a DATETIME? — **INT, nullable.**

A Unix timestamp. Unblocks the same endpoints.

### A4. Does `install_secrets` exist? — **Yes, and this one found a bug.**

It exists, which settles the biggest question on this list: the 2024 security
patch set's *schema* did ship, whatever happened to the rest of it. `devices`
and `password_resets` are there too. So the Android cutover is not blocked.

But it carries `UNIQUE KEY uq_account_device (account_id, device_id)`, and the
fixture had a plain index. The original `bootstrap_secret.php` rotation — mark
the old row revoked, insert a new one beside it — passed every test here and
**would have thrown a duplicate-key error on the first real rotation in
production**. The secret is now replaced in place, which the table was telling
us all along is the right model: one live signing key per install, and rotating
it ends the old one at the same instant.

`device_id` is `varchar(128)`, not the 191 I had assumed; longer ids are now
refused rather than silently truncated, because a truncated device id signs
requests that verify against the wrong row.

### A5. What else the dump changed

* **`tstamp` is `bigint` in every step-work table — and holds *seconds*.** Not
  milliseconds, which is what "bigint timestamp" reads as. `F.getTStamp()` in
  the Android client is `Date().time / 1_000L` (`extras/F.kt:607`), and
  `MorningsFragment` and `Nights.kt` divide too. The column is simply wider than
  it needs to be. Worth having written down: `CommentVisibility` compares
  `comments.tstamp` against `comment_thread_subscribers.subscribed_at` (an int,
  also seconds), and assuming milliseconds there would widen every chat
  visibility window by a factor of a thousand and let everybody read
  everything. I made that assumption, wrote it into a commit message, and only
  caught it by checking the client.
* **`accounts.password` is `varchar(20)`.** The legacy plaintext column could
  never have held a long password. Worth knowing before anybody treats it as a
  credential store.
* **`accounts` has no index on `email`, `fbid`, `googleid` or `appleid`.** Every
  email and social sign-in is a full table scan, and `LOWER(email) = ?` could
  not use an index even if one existed. Adding one is an `ALTER` on an adopted
  table, so it needs your say-so — see **D1** below.
* **`inventories`, `amends` and `mornings` have no index on `accountid`.** Only
  the primary key. So every read of somebody's Fourth Step scans the table.
  `journals`, `gratitudes` and `nights` do have one.
* **`nights` is indexed on `(accountid, tdate)`, not `tstamp`** — the only
  collection whose index disagrees with the column the reader orders by.
* **`accounttype` is `1 = AA, 2 = NA`.** There is a Narcotics Anonymous mode in
  the data model. Neither shipped app's UI exposes it as far as I can tell.
* **`devicetype` has a third value: `3 = Web`.**
* **There are two settings tables** — `appsettings` (the one-row table of limits
  both apps read) and `app_settings` (key/value, added later, which I cannot
  find a reader for in either client). And the sale appears twice:
  `appsettings.SALE_*` and a `sale` table.
* **`reviewed` is a table as well as a flag** — `(inventory_id, sponsorid, type,
  tstamp)`. That largely answers C2; see below.
* **`account_details` stores one question three ways**:
  `accept_new_sponsees` is a varchar holding `'true'`/`'false'`, while
  `accept_new_sponsor` and `accept_new_chat` beside it are ints.
* **`sponsors.rejected_tstamp`** is commented "also used to signify accepted by
  id if status = 0" — one column holding a timestamp or an account id depending
  on another column.
* **`notification_texts`** has a STORED generated column
  (`unhex(md5(description))`) carrying its unique key, and a FULLTEXT index.
* **`sample`** is a one-column MyISAM table. It is junk and nothing reads it.

---

### A7. The subscribe-interval history can never have more than one entry

`get_comments.php` walks `comment_thread_subscribers` ordered by
`subscribed_at, id` as a **history** of subscribe and unsubscribe transitions,
and filters a thread's messages to the intervals when the reader was subscribed.
It is a thoughtful design: leave a group and rejoin, and you do not see what was
said while you were away.

But that table is `UNIQUE (thread_id, account_id)`, so there is only ever one
row per person per thread, and the walk always yields a single entry —
`is_subscribed = 1` gives one open interval from the join time, `0` gives none.
`comment_thread_subscriber_history` is presumably where the transitions were
meant to live, and **no reader anywhere touches it**, which answers why that
table exists and nothing reads it.

`Services/Chat/CommentVisibility` implements the interval walk for any number
of rows, so it behaves identically to the old reader today and becomes correct
on its own terms the day something starts writing a real history. Nothing needs
deciding; it is recorded so the next person does not mistake the dead history
table for a bug or delete the interval logic as over-engineering.

### A6. A live content-corruption bug, found in the dump

**This is the most important thing the schema turned up, and it is happening
today.**

`nights` and `mornings` are **latin1** tables. `appsettings` and `quotes` are
too. Everything else that holds writing — `inventories`, `amends`, `journals`,
`gratitudes`, `comments` — is utf8mb4. And `19/db.php` sets the connection to
`utf8mb4`.

A character that utf8mb4 can carry and latin1 cannot is therefore **converted on
the way in and stored as `?`**. That includes every emoji, every curly
apostrophe and quotation mark, every em dash, and every non-Latin script.

iOS replaces a typed apostrophe with U+2019 automatically. So:

> "I didn't drink today" → **"I didn?t drink today"**

in the nightly review and the morning notes, for every iOS user, every day —
while the same sentence typed into the journal is stored perfectly, because
`journals` is utf8mb4. That asymmetry is why nobody has traced it: the app looks
like it works, and only one feature quietly mangles what people write.

**The fix is one statement per table**, and it is correct rather than risky: the
stored bytes are already valid latin1 (the conversion happened at write time),
so `CONVERT TO CHARACTER SET` re-encodes them faithfully.

```sql
ALTER TABLE nights   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE mornings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE quotes   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Three things before you run it:

1. **Back those tables up first.** Data, not just structure.
2. **It is an `ALTER` on an adopted table**, which is the one thing this
   application does not do on its own initiative. So it is your call, not mine.
3. **The `?`s already written are gone for good.** The original characters were
   discarded at write time; nothing can recover them. Converting stops it
   happening again, and that is all it does.

`php artisan app:check` reports this every time it runs until it is fixed.


---

## B. Security findings in the live system

These are not questions about what to build. They are things I found that you
should know about, each with what I would do.

### B0. The live JWT secret is the library's template placeholder

`19/jwt_verify.php:11`

```php
const JWT_SECRET = 'CHANGE_ME_TO_A_…'; // move to env/config
```

`db.php:22` requires that file, so it is the auth path on every live endpoint.
`require_valid_jwt()` validates bearer tokens with it; `issue_jwt()` signs with
it. So anybody can sign `{"sub": <any account id>}` and hold a valid token for
that account, and `bootstrap_secret.php` — token-only auth, because you cannot
sign a request before you hold the key — will then hand over that install's HMAC
signing key. From there every endpoint opens.

That is an unauthenticated takeover of any account, live now. B1 below was only
a convenience on top of it.

**The owner's decision (2026-10-07): leave the live server alone and handle it
properly in the new stack.** Rotating the secret there invalidates every token
in every installed app, and the shipped client swallows 401s (AND-004), so sync
would stop silently with no prompt to sign in again.

**What this application does instead**, so that it does not inherit the hole:

* **it never issues a legacy-format token.** `AuthController::signedIn` issues a
  Sanctum token. That makes "legacy format" mean "did not come from here",
  exactly, with nothing to look up. Safe because the shipped Android client
  treats the token as opaque — traced, not assumed: `ui/LoginActivity.kt:184`
  reads `map["access_token"]` as a string and `map["expires_at"]` separately,
  `auth/TokenStore.kt:84` stores the pair, and nothing on the client decodes it.
* **`VerifyLegacyJwt` records provenance** — `server` or `legacy` — on every
  request.
* **`bootstrap_secret.php` refuses to mint or rotate for a `legacy` token.** It
  may only return a key for an `(account_id, device_id)` pair that already
  exists.

Which leaves a forger needing a real install's `device_id` — a value that only
ever exists on that phone — before the endpoint tells them anything. Without a
key they cannot sign, and every endpoint that touches step work needs a
signature.

The one real cost: somebody on Android 1.9.0 who clears their app data keeps
their old token but loses their device binding, and is refused at bootstrap
until they sign in again. That is a sign-in, not a loss — their writing is on
the server and comes back with them. `LEGACY_TOKENS_MAY_BOOTSTRAP=true` restores
the old behaviour in one line if the refusals turn out to be louder than
expected.

Thirteen tests in `tests/Feature/Legacy/InstallSecretTest.php` hold this,
using `LegacyJwt::issue()` to play the part of the old server — a token
indistinguishable from a real forged one.

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

### B3. `bootstrap_secret.php` returns the signing key to any bearer token — **narrowed**

You cannot sign a request before you hold the key, so the endpoint that issues
the key cannot itself be signed. On the live server the consequence is total:
whoever holds a token — including a forged one, see B0 — can get the key that
goes with it.

In this application it is narrowed to almost nothing by the provenance rule in
B0: only a token this server issued may register a device or rotate a key. What
remains is that a token this server issued can obtain the key for its own
install, which is unavoidable and is what the signature layer is for — replay
protection and device binding, not a second factor.

Closing even that would need the key derived at install time from something the
server never sees, or delivered through an attested channel, which is a client
release and a change to the v19 contract. Not worth it while the provenance rule
holds.

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

### C2. `shared` versus `reviewed` — mostly answered, one bit left

The dump settles the shape. `inventories.shared` has `shareddate` beside it and
is commented *"Step 4 Shared? 0 = No, 1 = Yes"*, so it means **shown to my
sponsor**. `reviewed` is a flag on the record **and** a table of its own:

```
reviewed (inventory_id, sponsorid, type, tstamp)
```

So "reviewed" records *which sponsor* marked it and *when* — which only makes
sense as **my sponsor has been through this**. That is the reading I had, now
with evidence.

The one bit left: **what the `type` values mean.** `inventories`, `amends` and
`nights` all carry a `reviewed` flag, and `reviewed.inventory_id` with a `type`
beside it is a polymorphic reference across them. I need the mapping before the
mark-as-reviewed endpoint can be written, or a sponsor marking an amend will
mark somebody's nightly review instead.

**What answers it.** One query:

```sql
SELECT type, COUNT(*) FROM reviewed GROUP BY type;
```

Three distinct values and their counts, against the row counts of the three
tables, will tell us which is which.

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

## D. Changes to the live database I recommend but will not make

This application never alters an adopted table, which is what keeps the cutover
reversible. These are the three I would nonetheless put to you, in order. Each
is reversible, each needs a backup first, and none of them is urgent except the
first.

### D1. Fix the latin1 tables

A6 above, in full. It is corrupting what people write, today. The other two are
performance; this one is content.

### D2. Index the columns every sign-in searches

`accounts` has no index on `email`, `fbid`, `googleid` or `appleid`, so every
sign-in of every kind is a full table scan. On a table this size that is
survivable and getting slower.

```sql
ALTER TABLE accounts
  ADD KEY ix_accounts_email (email),
  ADD KEY ix_accounts_googleid (googleid),
  ADD KEY ix_accounts_appleid (appleid),
  ADD KEY ix_accounts_fbid (fbid);
```

Adding an index changes no data and no behaviour, and it can be dropped again in
one statement. Note that the application's `LOWER(email) = ?` lookup still will
not use it — matching the old scripts' own comparison is worth more than the
index, so that query stays as it is until the legacy paths are off.

### D3. Index the three step-work tables that have nothing

`inventories`, `amends` and `mornings` have only a primary key, so reading one
person's Fourth Step scans every row of everybody's.

```sql
ALTER TABLE inventories ADD KEY ix_inventories_account_time (accountid, tstamp);
ALTER TABLE amends      ADD KEY ix_amends_account_time (accountid, tstamp);
ALTER TABLE mornings    ADD KEY ix_mornings_account_time (accountid, tstamp);
```

This is the same index `journals`, `gratitudes` and `nights` already have, so it
is a consistency fix as much as a speed one.

---

## E. Confirmed, recorded here so nobody re-opens them

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
