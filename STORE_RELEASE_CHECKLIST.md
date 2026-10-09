# Store release checklist

For the release that puts the Flutter app in front of members. Credentials
and the billing plumbing are `docs/STORE_SETUP.md`; this is what has to be
true before pressing submit, and what to do when it goes wrong.

Tick these against the build you are actually submitting, not the one you
tested last week.

---

## 1. Before the build

- [ ] `php artisan app:check` is clean against production.
- [ ] `php artisan billing:check` is clean against production — both stores.
- [ ] The app points at `/19` on `12steptoolkit.com`, not at the scripts
      host. `lib/core/network/endpoints.dart` and the base URL.
- [ ] Version and build number raised. Apple rejects a duplicate build
      number before a human sees it.
- [ ] The release is signed with the production certificates, not the ones in
      somebody's keychain from 2019.

## 2. Account deletion — **review-blocking**

Apple requires an app that creates accounts to let a member delete theirs
from inside the app, and a support link is not enough.

- [ ] `delete_account.php` is reachable from the signed-in app, in two taps
      or so, and not buried.
- [ ] It asks for confirmation. The endpoint requires `confirm=DELETE`.
- [ ] It actually erases. `AccountEraser` tombstones the account row and
      removes the member's own records, credentials, devices and
      sponsorships — see `AUDIT_AND_IMPROVEMENTS.md` §2.1–2.3 for the three
      things the old script got wrong.
- [ ] The app signs out and returns to the sign-in screen afterwards.

Test it on a real account on a real device before submitting. A reviewer
will.

## 3. Privacy

- [ ] **Apple privacy labels** match what the app collects. It collects an
      email address, a sober date, and free text the member writes. "Data Not
      Collected" is not true.
- [ ] **Google Data safety** form likewise, including "Data is encrypted in
      transit" and whether deletion can be requested — it can, so say so and
      link it.
- [ ] The privacy policy URL resolves and describes what is actually stored.
      `12steptoolkit.com/privacy`.
- [ ] Nothing the member writes — inventories, journals, amends — appears in
      a push notification. Enforced by a test; see
      `CommentNotifierTest::it never puts the message in the notification`.

## 4. Anonymity

This is a recovery app and the eleventh tradition is not a nicety.

- [ ] No real name required to sign up.
- [ ] Screenshots and the preview video contain no real member's nickname,
      sober date, inventory or message. Use a seeded demo account.
- [ ] The store listing does not name A.A. in a way that implies
      endorsement. The site carries "Not affiliated with Alcoholics
      Anonymous World Services, Inc." and the listing should be consistent
      with it.

## 5. Subscriptions

- [ ] Every product in `config/billing.php` exists in both stores, with the
      same identifiers. `billing:check` compares them.
- [ ] Price tiers set in every territory you sell in.
- [ ] Restore purchases works, on a device that has bought before.
- [ ] The paywall says what is free. The home page says the app is
      ad-supported and that everything needed to work the Steps is in the
      free version — the listing must not contradict it. See
      `FEATURE_PARITY.md` §4 on the "Free For Life" claim that was removed.
- [ ] Apple: App Store Server Notifications V2 URL set and answering.
- [ ] Google: Real-time Developer Notifications topic set and answering.

## 6. The server, on release day

- [ ] `PUSH_DRIVER` — if it is still `log`, reminders and message
      notifications are recorded and not delivered. That is the safe default
      and a deliberate one, but ship knowing which it is.
- [ ] The scheduler is running: `app:check` reads the heartbeat
      `reminders:send` depends on.
- [ ] The queue worker is running, or `NotifyThreadOfComment` never runs.
- [ ] `/19` answering on `12steptoolkit.com`, not only on the scripts host.

## 7. Staged rollout

- [ ] **Google**: start at 10%. Halting a staged rollout is the only
      rollback either store gives you.
- [ ] **Apple**: phased release on, which is seven days and can be paused.
- [ ] Watch for 24 hours before going wider: crash-free rate, the sign-in
      funnel, and `/19` error rates by endpoint.

## 8. When it goes wrong

| | |
|---|---|
| Crashes on launch | halt the rollout; pause the phased release |
| Sign-in failing | check `login_email.php` and `bootstrap_secret.php` error rates before assuming the client |
| Reminders not arriving | expected if `PUSH_DRIVER=log`; otherwise `reminders:send` output and the FCM key |
| Messages not arriving | the queue worker |
| A member reports lost data | the database never moved — see `MIGRATION_PLAN.md` §2 — so this is a read path, not a migration |

There is no server-side rollback for an app release. The server keeps
answering `/18` and `/7`, so a member who downgrades still works. That is the
reason those stay up through the overlap.
