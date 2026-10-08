# Our own entitlement rules

**Decided 2026-10-08 · Tushar.** RevenueCat is being dropped as a dependency
and this document is what replaces it: the complete statement of who is
premium and why, owned by us rather than by a dashboard.

Until now the answer to "is this person premium?" was partly "whatever
RevenueCat says". That was defensible — RevenueCat is the only thing that knows
about every subscription sold before this server existed — but it meant the rule
lived somewhere nobody on this project could read, and it could change without a
commit.

---

## 0. The one thing that must not be misread

**Dropping the SDK is not the same as turning the account off.**

Both shipped apps ask RevenueCat directly for the `subscribed` entitlement
(`RevCatManager.kt:58, 95` on Android; `PaywallSheet` and `SubscriptionVC` on
iOS). Every existing subscriber's premium runs through it **today**. So:

* the **new** app ships without the SDK and this server verifies purchases
  itself;
* the RevenueCat **account stays alive** until 1.9.0 and 1.6.6 are retired,
  however little the new app uses it;
* its **webhook stays wired as an ingest**, because a purchase made in the *old*
  app after cutover happens through RevenueCat and this server would otherwise
  never hear about it;
* and this server stops **granting** from RevenueCat — but **not yet**.

That last point was wrong when first written here, and the correction matters
more than the original line did. "Nothing reads RevenueCat to decide access any
more" is the *end* state. Shipping it today would un-subscribe every current
subscriber, because their entitlement lives in RevenueCat and nowhere else
until the export is imported and verified against the stores. That is exactly
the failure `EntitlementService`'s **no source may take away access** rule
exists to prevent — committed by the deployment instead of by the resolver,
which is the version no test catches.

Two flags, and the order is the whole point:

| Flag | Means | Goes false when |
|---|---|---|
| `REVENUECAT_GRANTS_ACCESS` | a RevenueCat answer may make somebody premium | the export is imported **and** the console's subscriber count has been checked against it |
| `REVENUECAT_ENABLED` | this server listens to RevenueCat at all | 1.9.0 and 1.6.6 are retired — until then a purchase made in the old app arrives only by that webhook |

Turning the account off before the old apps are gone would silently un-subscribe
everybody who has not upgraded.

`php artisan billing:check` prints which combination a server is currently in,
and flags the one that cannot be right — granting on while listening is off,
which grants nothing at all.

---

## 1. Decided

### R1. Premium is binary

One entitlement. Subscribed or not; no tiers, no per-feature unlocks.

This is what both apps already do and what every current subscriber already
has, so nobody's access changes shape at cutover. It also keeps `D-002`
(Step 4 inventory types are subscription-locked) as the only kind of gate the
client needs: a boolean, checked in one place.

### R2. The à-la-carte unlocks grant nothing — **reversed 2026-10-08**

`steps8and9`, `steps10and11` and `otherfeatures` grant nothing.

**This reverses the decision taken earlier the same day**, which was to grant
them full premium permanently, and the reversal is the owner's on better
information than I had. Mine rested on them being a recent cohort who had
quietly lost something; his answer was that the sales are roughly a decade old
— the RevenueCat records date from Nov 2023 because that is when the products
were re-registered there, not when they sold — and that there were only ever a
few.

What the investigation established, and which stands either way:

* **No entitlement was ever attached** to any of the three in RevenueCat; all
  three show "Attach" rather than a count.
* **`19/add_order.php` writes a `subscription_orders` row and nothing else** —
  it never touches `accounts.subscribed`.

So these purchases have granted nothing from either direction since they were
made. That was the argument for granting them now; it is also, read the other
way, the argument against. Nobody has been losing access — there is no access
to lose, and no one has complained in ten years.

**It is a safe call rather than a gamble, because there is recourse.** The
orders are still in `subscription_orders`, the console shows them on the
account, and `ComplimentaryGrant` already exists. If somebody surfaces, they are
granted by hand in a few seconds with an audit row against it. That is a better
answer than a config entry handing lifetime premium to a cohort nobody has
counted.

`grants => 'none'` — **decided to grant nothing**, which is deliberately not the
same value as `unresolved`. See `StoreSubscription::GRANTS_SUBSCRIBER_ACCESS`
for why those two must stay distinguishable.

### R3. Existing subscribers come across two ways, not one

1. **A one-time export from RevenueCat, taken before the dependency is
   dropped.** It is the only place holding `original_transaction_id` and Google
   purchase tokens for the current subscriber base. With those this server can
   verify every one of them against Apple and Google directly, without waiting
   for anybody to open the new app.
2. **On-device restore at first launch.** StoreKit 2's
   `Transaction.currentEntitlements` and Play Billing's `queryPurchasesAsync`
   report what the person owns; the client sends them up and the server
   verifies.

Both, because they fail in different directions. The export goes stale the
moment it is taken and covers people who are slow to upgrade; the device path is
always current and covers nobody who has not upgraded yet.

**The export is on the critical path and it expires.** It cannot be taken after
the account is closed.

### R4. A gifted sponsee keeps the full term

A sponsor buys a consumable that grants one sponsee 3 or 12 months. If the
sponsor's own subscription then lapses, **the sponsee keeps every remaining
month**.

The gift was bought outright. It is not a seat on the sponsor's plan, and
revoking it would take away something already paid for from the person least
able to do anything about it.

---

## 2. Proposed, and proceeding on unless corrected

Each of these has a safe default and I am building to it. Say the word on any
one and it changes.

| # | Rule | Default | Why this way |
|---|---|---|---|
| R5 | **Billing-retry grace period** | Access continues to the end of the store's grace period | Both stores expect it, and the alternative locks out somebody whose card expired while they are still trying to pay |
| R6 | **Refunds** | Revoke immediately | The store telling us the money went back is the one unambiguous signal, and the only thing that revokes faster than an expiry |
| R7 | **Free trials and intro offers** | Grant full premium for the trial | A trial that does not unlock the thing is not a trial. Whether somebody may trial twice is enforced by the stores, not by us |
| R8 | **Lifetime held alongside an active subscription** | Most generous wins, and the console flags it | Nobody loses access to a billing oddity. Flagged rather than silently absorbed so you can offer the refund |
| R9 | **Cross-platform** | Premium follows the **account**, not the store | One account, one answer. Somebody who subscribed on Android and signs in on an iPad is the same paying person — RevenueCat did this by app user id and it would be a regression to lose it |
| R10 | **An unassigned gift slot** | Never expires; the term starts when it is assigned | Otherwise a gift bought in advance is a ticking clock, and the sponsor gets nothing for their money if they take a fortnight to find the right sponsee |
| R11 | **Reassigning a gift** | Not reassignable once assigned | The months belong to that person from then on. Simpler, and fairer to the sponsee than letting a sponsor take it back |
| R12 | **`accounts.subscribed`** | Set, never cleared; read as a legacy signal only | No code path in the old system ever cleared it, so people in the field have premium today resting on it having been set years ago. `v8/giftsubscription.php` still writes it. Clearing it is the one change that could un-subscribe somebody invisibly |

---

## 3. Answered from the RevenueCat product list, 2026-10-08

* **The Apple catalogue is complete** — twelve live products, plus
  `…profeatures_non_consumable` awaiting its first submission alongside a build.
* **Family Sharing is off** on the new non-consumable, and staying off (R13).
* **`annual_3` / `quarterly_3` are not in RevenueCat at all.** The Android app
  buys through RevenueCat packages, so a product that is not there was never
  purchasable in-app. The sponsor-bundle question (`OPEN_QUESTIONS.md` C6) is
  closed as unsold; the ids stay mapped as plain subscriptions so that if one
  ever does arrive it grants access rather than nothing.

### R13. Family Sharing stays off

Enabling it is effectively one-way — customers who gain shared access keep it —
and it means an entitlement can arrive for an account that never bought
anything, which every path in the resolver would then have to expect. Off until
there is a reason for it, not off by accident.

### The one naming defect to fix in the consoles

Apple product **`Quarterly`** (the flash-sale quarterly, Level 4, created
28 Nov 2025) carries the *reference name* `com.12stepapp.recoverybox.annual1999`
— another product's id — in both App Store Connect and RevenueCat. Every sale of
it is therefore reported under the annual product's name.

The id cannot be changed, ever. The display names can, in both consoles, and
should be **before the RevenueCat export is taken**, or the export inherits the
mislabelling.
