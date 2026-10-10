# Feature parity

What the old system does, what this one does, and where the two differ on
purpose. Generated against the route table and the legacy source, not from
memory — rerun the counts with `php artisan route:list` if this looks stale.

Counts as of the last update: **47** v19 endpoints, **8** `/my` routes,
**169** indexed website URLs.

---

## 1. The app API

The Flutter client names 26 scripts in `lib/core/network/endpoints.dart`.
**All 26 are served.** That is the number that decides whether the app works;
the legacy directory's size does not.

| Client calls | Served | Note |
|---|---|---|
| 26 | 26 | `sync_account.php` is declared but has no call site — `endpoints.dart` says it "cannot be used" |

### v19 is not a rename of /18

The old surface is generic — `getlist.php`, `writerecord.php`,
`inserter_updater.php`, `login.php` — and one script serves many purposes by
switching on a `type` integer posted by the caller. v19 is purpose-named, so
the set of things an endpoint can touch is the endpoint, not a parameter.

| /18 | v19 |
|---|---|
| `getlist.php` (type 1–15) | `get_inventories`, `get_nights`, `get_mornings`, `get_journals`, `get_gratitudes`, `get_amends` |
| `writerecord.php`, `inserter_updater.php` | `*_add_update.php`, one per collection |
| `deleterecord.php` | none — deletion is `is_deleted=1` on the matching `*_add_update.php`, scoped by account |
| `login.php` | `login_email`, `login_google`, `login_new_account`, `login_2`, `login_upgrade` |
| `getaccountdetails.php` | `get_user_account.php` |
| `updatedetails.php`, `updatefield.php`, `writedate.php` | `update_account.php`, `update_account_details.php` |
| `getcounts.php` | `get_counts.php` |
| `getserversettings.php` | `get_app_settings.php` |
| `comment.php` | `comment_add_update`, `get_comments`, `get_comment_threads`, `get_step_comments`, `comment_star`, `comment_update_receipt`, `update_thread_subscriber` |
| `sponsorship.php`, `get_sponsees.php` | `get_friends`, `get_one_friend`, `update_sponsors`, `send_chat_request`, `fetch_sponsor_ids` |
| `reviewed.php` | `mark_reviewed.php` |
| `deleteaccount.php` | `delete_account.php` |
| `add_orderdata.php`, `add_orderdata_sponsee.php`, `giftsubscription.php` | `add_order.php`, `add_sponsee_order_and_gift.php` — the gift's two halves (record the purchase, assign a seat) became one idempotent call |
| none | `get_sponsee_gift_expiry.php`, `revcat_is_subscribed.php` — both newer than /18 |
| `newsletter_subscribe.php` (both trees) | `newsletter_subscribe.php`, `newsletter_status.php` — the address comes from the signed account, so there is no parameter to abuse |

### Legacy endpoints with no v19 equivalent yet

None of these is called by the Flutter client, so none blocks the app.

| Script | What it does |
|---|---|
| `getsponseepurchases.php` | reading a sponsor's gift purchases — the query exists as `SponseeGifts::purchases()` and has no endpoint, because nothing asks |
| `getnewslettersubscribed.php` | answered on the v8 path, from Sendy's API rather than from its database, and only about an address this server holds |
| `notification.php`, `notify.php` | push — replaced by `Services/Push`, which every endpoint that notifies now goes through |
| `send_user_online_notification_to_all_friends.php` | presence push |
| `get_build_expiry.php` | the beta kill switch, from the `builds` table. **Nothing calls it** — not the shipping Android app, not the iOS one, not the new client. A retirement candidate rather than a gap |
| `getrecorddetail.php`, `getlist_pagination_totals.php`, `getcounts_sponsee.php` | superseded by the per-collection reads |

---

## 2. The web app → `/my`

`web.12steptoolkit.com` was a TailAdmin template with about 2,000 lines of
hand-written JavaScript against seven PHP endpoints. `/my` is Blade in this
application.

| Web app | `/my` | |
|---|---|---|
| Email + 4-digit code sign-in | ✅ | code hashed in the cache, expires, five tries, rate-limited per address and per IP, and the reply does not say whether the address exists |
| Google sign-in | ❌ | deliberate — see `MIGRATION_PLAN.md` |
| QR "link device" | ❌ | the apps own device linking |
| Journals, gratitude lists | ✅ read, write, edit, delete | |
| Step Four and spot-check inventories | ✅ | including the six "part of self" tags |
| Amends | ✅ | |
| Nightly inventory | ✅ | the twelve questions, `desc1..desc12` / `sw1..sw12`; Q8 has no switch |
| Morning inventory | ✅ | the mood, the four questions, notes |
| Big Book and literature | ✅ | on the website itself, at `/aa-literature` — 105 pages, free, no account |
| Sponsor view of a sponsee's work | ❌ | the apps own this |
| Comments and chat | ❌ | the apps own this |
| Contact form | ⚠️ | the page gives the address; the form lands when `support_tickets` has a rate limit and a honeypot |

**The security difference is the point.** The old endpoints took an
`accountid` from the request body and believed it. `/my` takes the account
from a signed session cookie, and the authorisation tests are mostly about
somebody else's records: cannot read, cannot edit, cannot delete.

---

## 3. The website

| | Old (Next.js export) | New (Blade) |
|---|---|---|
| Indexed URLs | 169 | 169, all 200 |
| `<title>` and description | — | pinned per URL against the production build, 507 assertions |
| JSON-LD | Organization, WebSite, MobileApplication, BreadcrumbList, Article, BlogPosting, Blog, CollectionPage, FAQPage | same |
| Redirects | 27 × 301, 1 × 410, reflections → aa.org | same |
| `sitemap.xml`, `robots.txt` | generated at build | generated by the application |
| Store CTA | all 170 pages | all pages, by the layout rather than by each page remembering |
| Dark mode | none | none — deliberately removed |
| Build step | Node + webpack | none |

---

## 4. Deliberate differences

Things that are not parity gaps but decisions, each with the reasoning where
it is enforced rather than here:

- **No "Free For Life" claim.** The old home page promised unlimited premium
  features free for life, three sections above an FAQ answer saying the app is
  ad-supported and asking members to subscribe. `config/billing.php` maps
  thirty paid products. The page now says what is true — a test pins it.
- **The Big Book section counts "parts", not "chapters".** It holds the eleven
  chapters plus the preface, two forewords, the Doctor's Opinion and two
  stories, so "17 chapters" is wrong by six.
- **`reported_users` survives account erasure.** A report is a safety record
  about somebody else; erasing it on request would mean deleting an account
  wiped the reports against it.
- **`comments.byid` in group threads survives account erasure.** Removing
  those would take content out of other people's conversations. The old
  script leaves them too.
- **A gifted member keeps the longest gift, not the latest.**
  `get_sponsee_gift_expiry.php` is `ORDER BY sou.id DESC LIMIT 1`, so somebody
  holding a twelve-month gift who is then handed a three-month one is answered
  with the three months. `SponseeGifts::accessUntil()` takes the latest expiry
  across every seat held. Nothing may take away access another row still
  grants — `docs/ENTITLEMENT_RULES.md`.
- **A gift needs a connection, and the seat count comes from the row.** The
  live script reads the buyer out of the POST body, so any stranger could
  spend any sponsor's seats on any account id, and it takes the posted
  `quantity` as the seat limit. Here the buyer is the signed account, the
  limit is the stored `quantity`, and the recipient has to be somebody the
  caller is connected to — `status IN (0, 1, 5, 6)`, which is exactly the set
  `get_friends.php` returns, so gifting to a sponsor or a chat contact still
  works as the Upgrade card offers it.
- **A gift's term is resolved when it is bought, not when it is read.**
  `months` is written on the order row; where a client sends none, the term is
  read from the product name in the SKU (`annual` → 12, `quarterly` → 3) and
  recorded. A purchase whose term cannot be worked out is refused rather than
  stored, because `months <= 0` grants nothing and would be a payment for
  nothing. The number in a SKU is **not** the term: `…annual_sponsee1` is
  twelve months.
- **`add_order.php` grants nothing.** It records a receipt in
  `subscription_orders` for the audit trail. A client's word about a purchase
  is not a verified receipt, and if writing there granted premium the paywall
  would be a POST away. Access comes from `EntitlementService` only.
