<?php

namespace App\Services\Legacy;

use App\Models\InstallSecret;
use App\Models\RequestNonce;
use Illuminate\Http\Request;

/**
 * The second half of the Android authentication: a signature over the canonical
 * request, keyed with the install's own secret.
 *
 * `19/auth_checker.php` does everything below except the last step, and the
 * last step is the one that matters:
 *
 * > `// (Optional) Replay protection with nonce table (see section C)`
 *
 * — `auth_checker.php:81`. The nonce is required, read, and thrown away, so a
 * captured request can be replayed for the whole 300-second window. The file
 * next to it, `signature_checker.php`, records nonces properly and is included
 * by exactly one demo endpoint. This class uses that file's semantics and
 * `auth_checker.php`'s canonical string, because the canonical string is what
 * the shipped clients sign.
 *
 * The failure reasons are returned rather than thrown so that the middleware
 * can answer 401 with the same body the old scripts used while the real reason
 * goes to the log and nowhere near the response.
 */
final class HmacVerifier
{
    public const OK = 'ok';

    public const MISSING_HEADERS = 'missing_headers';

    public const ACCOUNT_MISMATCH = 'account_mismatch';

    public const CLOCK_SKEW = 'clock_skew';

    public const BODY_MISMATCH = 'body_mismatch';

    public const NO_SECRET = 'no_secret';

    public const BAD_SIGNATURE = 'bad_signature';

    public const REPLAY = 'replay';

    /**
     * @param  int  $accountId  the account the JWT named; the header must agree
     * @return string one of the constants above
     */
    public function verify(Request $request, int $accountId): string
    {
        $headerAccount = (string) $request->header('X-Account-Id', '');
        $deviceId = (string) $request->header('X-Device-Id', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $bodyHash = (string) $request->header('X-Body-SHA256', '');
        $signature = (string) $request->header('X-Signature', '');

        foreach ([$headerAccount, $deviceId, $timestamp, $nonce, $bodyHash, $signature] as $value) {
            if ($value === '') {
                return self::MISSING_HEADERS;
            }
        }

        // The header has to name the account the token names. Without this the
        // signature proves only that *somebody* signed something.
        if ((int) $headerAccount !== $accountId) {
            return self::ACCOUNT_MISMATCH;
        }

        $ts = (int) $timestamp;
        $window = (int) config('legacy.android.hmac_window_seconds', 300);
        if (abs(time() - $ts) > $window) {
            return self::CLOCK_SKEW;
        }

        $calculated = CanonicalRequest::bodyHash($request->getContent());
        if (! hash_equals($calculated, strtolower($bodyHash))) {
            return self::BODY_MISMATCH;
        }

        $secret = InstallSecret::activeSecret($accountId, $deviceId);
        if ($secret === null) {
            return self::NO_SECRET;
        }

        $expected = CanonicalRequest::sign(
            CanonicalRequest::fromRequest($request, $calculated, $ts, $nonce),
            $secret,
        );
        if (! hash_equals($expected, $signature)) {
            return self::BAD_SIGNATURE;
        }

        // Only now: a nonce is spent once the signature is known to be good, so
        // a wrong guess cannot burn a real client's nonce.
        if (! RequestNonce::claim($accountId, $deviceId, $nonce, $ts)) {
            return self::REPLAY;
        }

        return self::OK;
    }
}
