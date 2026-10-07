<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Http\Middleware\VerifyLegacyJwt;
use App\Models\InstallSecret;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `bootstrap_secret.php` — the one endpoint that can be authenticated by token
 * alone, because you cannot sign a request before you hold the key.
 *
 * It returns the existing active secret rather than always minting one, which
 * is what the shipped client expects. That is the weakest point in the Android
 * scheme and it is the old design, not this one: anybody who holds a bearer
 * token can ask for the signing key that goes with it, so the signature adds
 * replay protection and device binding but not a second factor. Changing it
 * needs a client release, and it is written down in `docs/OPEN_QUESTIONS.md`
 * rather than quietly left as if it were fine.
 *
 * ## A forged legacy token cannot get a key out of this
 *
 * The live server's JWT secret is the library's template placeholder
 * (`19/jwt_verify.php:11`), so anybody can sign a token for any account id.
 * This application must accept those tokens — every install in the field holds
 * one — and this endpoint is the single place where holding one could turn into
 * holding a signing key, and therefore into reading somebody's Fourth Step.
 *
 * So the provenance recorded by `VerifyLegacyJwt` decides what may happen:
 *
 *  * **a token this server issued** can create a binding and rotate a key;
 *  * **a token signed with the legacy secret** can only retrieve a key for a
 *    `(account_id, device_id)` pair that **already exists**. It cannot register
 *    a new device and it cannot rotate.
 *
 * Which leaves a forger needing a real install's `device_id` — a value that
 * only ever exists on that phone and is never sent anywhere else — before the
 * endpoint will tell them anything. And without a key they cannot sign, and
 * every endpoint that touches step work requires a signature.
 *
 * The cost is one real case: somebody on Android 1.9.0 who clears their app data
 * keeps their old token but loses their device binding, and will be refused here
 * until they sign in again. That is a sign-in, not a loss — their writing is on
 * the server and comes back with them — and it is the correct trade against
 * leaving the only door a forged token opens unlocked.
 *
 * `LEGACY_TOKENS_MAY_BOOTSTRAP=true` restores the old behaviour in one line, for
 * the cutover window if the refusals turn out to be louder than expected.
 *
 * ## One row per (account, device), because the table says so
 *
 * `install_secrets` carries `UNIQUE KEY uq_account_device (account_id, device_id)`
 * — read off the real schema in `docs/reference/legacy-schema.sql`, not guessed.
 * So a rotation cannot mark the old row revoked and insert a new one beside it;
 * that is a duplicate-key error, and it would have been a rotation that always
 * failed in production while passing every test, because the fixture had a
 * plain index where production has a unique one.
 *
 * The secret is therefore **replaced in place**. Which is also the better
 * security model: there is exactly one live signing key per install, and
 * rotating it ends the old one at the same instant rather than leaving a
 * revoked row that something might still match.
 */
class InstallSecretController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $deviceId = trim((string) $request->input('device_id', ''));

        if ($deviceId === '') {
            return response()->json(['error' => 'device_id is required'], 400);
        }

        // `device_id` is varchar(128). Longer than that would be silently
        // truncated, and a truncated device id signs requests that verify
        // against the wrong row.
        if (mb_strlen($deviceId) > 128) {
            return response()->json(['error' => 'device_id is too long'], 400);
        }

        $rotate = filter_var($request->input('rotate', false), FILTER_VALIDATE_BOOL);

        $active = InstallSecret::query()
            ->where('account_id', $account->id)
            ->where('device_id', $deviceId)
            ->where('status', InstallSecret::ACTIVE)
            ->first();

        // See the note above. A legacy-signed token may read an existing
        // binding and nothing else.
        if ($this->mustNotMint($request) && ($active === null || $rotate)) {
            return LegacyEnvelope::fail('Sign in again to register this device', 403);
        }

        if ($active !== null && ! $rotate) {
            return response()->json([
                'secret' => base64_encode((string) $active->getRawOriginal('secret')),
                'rotated' => false,
            ]);
        }

        // `rotated` means "your previous secret has stopped working", so a
        // first issue must not claim it: a client that clears its outbox on a
        // rotation would throw away writes it had not sent yet.
        $replaced = $active !== null;

        $secret = random_bytes(32);

        // `upsert`, not revoke-then-insert: see the note above about
        // uq_account_device. One statement, so a client that asks twice at once
        // cannot end up with two rows or none.
        InstallSecret::query()->upsert(
            [[
                'account_id' => $account->id,
                'device_id' => $deviceId,
                'secret' => $secret,
                'status' => InstallSecret::ACTIVE,
            ]],
            uniqueBy: ['account_id', 'device_id'],
            update: ['secret', 'status'],
        );

        return response()->json(['secret' => base64_encode($secret), 'rotated' => $replaced]);
    }

    /** True when this request's token identifies an account but may not authorise a new key. */
    private function mustNotMint(Request $request): bool
    {
        if (filter_var(env('LEGACY_TOKENS_MAY_BOOTSTRAP', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $request->attributes->get('legacy_token_provenance') === VerifyLegacyJwt::FROM_LEGACY;
    }
}
