# Store setup

What has to be clicked, where, before this server can verify a purchase — and
which of the four systems involved is to blame when it cannot.

Everything here is checked by one command, so read this once and then let the
command do the remembering:

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.app/api
php artisan billing:check
```

It makes one real, read-only authenticated call to each store API and names
what works. Nothing in it writes to Apple, to Google or to this database.

---

## Why this is harder than it should be

Four systems, and each one's error message blames another:

| System | What it controls | How it fails |
|---|---|---|
| **App Store Connect → Integrations** | the two Apple `.p8` keys | a bare `401` with no body |
| **Google Cloud Console** | whether the Play API is *enabled* for the key's project | `403`, naming a project *number* |
| **Google Play Console** | whether the service account may read *this app* | `401 UNAUTHENTICATED` or `403 PERMISSION_DENIED` |
| **this server's `.env`** | which key, which bundle id, which package | any of the above |

A Play service account can hold every permission in the Play Console and still
be refused, because the API is disabled in the Cloud project the *key* came
from — which is not necessarily the project another integration uses.

---

## 1. Apple: two keys, and they are not interchangeable

Both are a `.p8` and they look identical on disk.

**The In-App Purchase key** reads purchases: transaction history, subscription
statuses, notification history. App Store Connect → **Users and Access** →
**Integrations** → **In-App Purchase** → **+**. Download it once; Apple will not
show it again.

**An App Store Connect API key** reads the catalogue: product names, prices,
periods, introductory offers. Same screen, the **App Store Connect API** tab,
role Admin or App Manager. This server uses it for the console's plans page and
for nothing else.

Neither works for the other's API. `billing:check` probes both separately and
says which one your key is, because asking is the only way to find out.

```dotenv
APPLE_BUNDLE_ID=com.12stepapp.recoverybox
APPLE_ISSUER_ID=        # the one UUID at the top of the Integrations page
APPLE_KEY_ID=           # the KEYID out of AuthKey_KEYID.p8
APPLE_PRIVATE_KEY_PATH=/var/www/vhosts/ibyteserver.com/secrets/12steptoolkit/AuthKey_XXXXXXXXXX.p8

# Only if the catalogue key is a different one. Left empty, the three above
# are used, which is right when one Team key does both jobs.
APPLE_CONNECT_ISSUER_ID=
APPLE_CONNECT_KEY_ID=
APPLE_CONNECT_KEY_PATH=
```

`APPLE_APP_APPLE_ID` is the app's numeric id. Nothing about verifying a purchase
needs it; run `billing:check` and it prints the value to paste in.

## 2. Apple: App Store Server Notifications V2

App Store Connect → the app → **App Information** → **App Store Server
Notifications** → Production Server URL:

```
https://12steptoolkit.app/api/v2/webhooks/apple
```

Until this is set, `billing:check` reports `4040007` — **which means the key is
working** and only the URL is missing. Read as a plain `404` it looks like an
authentication failure and sends you off to re-issue a key that was never the
problem.

While installed copies of 1.6.6 still exist, set `APPLE_NOTIFICATIONS_FORWARD_URL`
to RevenueCat's own notification URL: this server verifies each notification and
passes it on, so one URL serves both.

## 3. Google: the service account

**Create it in the Cloud project you are going to keep.** Cloud Console → IAM
and Admin → Service Accounts → Create. It needs **no** Cloud IAM roles at all —
Play access is granted in the Play Console, not here. Create a JSON key and
download it.

**Enable the API in that same project.** Cloud Console → APIs and Services →
Library → **Google Play Android Developer API** → Enable. This is the step that
does not follow from any other integration working: a different project's being
enabled does nothing for this key.

**Grant it in the Play Console.** Play Console → **Users and permissions** →
Invite new user → the service account's `client_email` → **add this app** →
grant **View financial data, orders, and cancellation survey responses**. App
permissions, not account permissions. A fresh grant takes a few minutes to
propagate, so a refusal immediately after granting is worth one retry.

```dotenv
GOOGLE_PACKAGE_NAME=com.ibyteapps.aa12steptoolkit
GOOGLE_PLAY_CREDENTIALS=/var/www/vhosts/ibyteserver.com/secrets/12steptoolkit/your-service-account.json
```

`billing:check` reads `project_id` and `client_email` out of the file itself and
prints them, so the project a call will be authorised against is never a guess.

## 4. Google: Real-time Developer Notifications

Play Console → the app → **Monetisation setup** → Google Play Billing →
Real-time developer notifications → a Pub/Sub topic name. Then in Cloud Console
give `google-play-developer-notifications@system.gserviceaccount.com` the
**Pub/Sub Publisher** role on that topic, and add a **push** subscription
pointing at:

```
https://12steptoolkit.app/api/v2/webhooks/google
```

---

## Getting the keys onto the server

The keys live **outside the web root**, `chmod 600`. `billing:check` fails if a
key is inside `public/`, and cautions if it is readable by other users on the
box.

Zip them locally, upload through Plesk's File Manager, then extract and fix the
permissions:

```bash
cd /Users/tushar/Work/Websites/12StepToolkit
zip -j store-keys.zip secrets/12steptoolkit/AuthKey_XXXXXXXXXX.p8 secrets/12steptoolkit/your-service-account.json
```

Upload `store-keys.zip` in Plesk's File Manager to
`/var/www/vhosts/ibyteserver.com/secrets/12steptoolkit/`, extract it there, then:

```bash
cd /var/www/vhosts/ibyteserver.com/secrets/12steptoolkit
chmod 700 .
chmod 600 *.p8 *.json
rm -f store-keys.zip
```

```bash
cd /var/www/vhosts/ibyteserver.com/WEBSITES/12steptoolkit.app/api
php artisan config:clear
php artisan billing:check
```

---

## Reading the output

| Line | Means |
|---|---|
| `the key works (production)` | the App Store Server API accepted the key |
| `the key works … no App Store Server Notifications V2 URL` | §2 is outstanding; the key is fine |
| `Apple rejected the token … carries no body` | key id / issuer mismatch, the wrong *kind* of `.p8`, or a revoked key |
| `this key cannot read the App Store Connect API` | §1's second key is missing. Only the console's plans page needs it — never a failure |
| `refused the key itself … invalid_grant` | the key is deleted, revoked, disabled, or this server's clock is out. Not a permissions problem |
| `not enabled in the Cloud project this key came from` | §3, step two. A Cloud Console setting |
| `Play refused the call … Users and permissions` | §3, step three, or it has not propagated yet |
| `Play has N product ids this config does not map` | somebody added a product in a store console and nobody mapped it in `config/billing.php`. **A purchase of one grants nothing** |

The last line is the one worth a recurring look: it is the only thing that
notices a product being sold that this server does not honour.

Cautions never change the exit code. Failures exit 1, so the command can be the
body of a monitor.

---

## What is deliberately not automated

Every step above is a click in somebody else's console, by design. A server
that could grant itself store access would be a server whose compromise granted
store access. The command's job is to say precisely which click is missing.
