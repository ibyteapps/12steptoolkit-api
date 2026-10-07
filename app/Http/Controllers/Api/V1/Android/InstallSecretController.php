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

        $secret = random_bytes(32);

        InstallSecret::query()
            ->where('account_id', $account->id)
            ->where('device_id', $deviceId)
            ->update(['status' => InstallSecret::REVOKED]);

        InstallSecret::query()->insert([
            'account_id' => $account->id,
            'device_id' => $deviceId,
            'secret' => $secret,
            'status' => InstallSecret::ACTIVE,
        ]);

        return response()->json(['secret' => base64_encode($secret), 'rotated' => true]);
    }
}
