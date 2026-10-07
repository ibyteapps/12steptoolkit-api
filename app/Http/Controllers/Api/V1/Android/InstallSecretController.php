<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\InstallSecret;
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

        $existing = $rotate ? null : InstallSecret::query()
            ->where('account_id', $account->id)
            ->where('device_id', $deviceId)
            ->where('status', InstallSecret::ACTIVE)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'secret' => base64_encode((string) $existing->getRawOriginal('secret')),
                'rotated' => false,
            ]);
        }

        // Did this install already hold a key? That is what `rotated` means to
        // the client — "your previous secret has stopped working" — so a first
        // issue must not claim it, or a client that clears its outbox on a
        // rotation would throw away writes it had not sent yet.
        $replaced = InstallSecret::query()
            ->where('account_id', $account->id)
            ->where('device_id', $deviceId)
            ->where('status', InstallSecret::ACTIVE)
            ->exists();

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
}
