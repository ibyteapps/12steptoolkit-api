<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetLink;
use App\Models\Account;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * `reset_password_for_email.php` — the "I have forgotten it" link.
 *
 * ## Unauthenticated, correctly
 *
 * It is one of the few live scripts that sets `REQUIRE_AUTH false` for a good
 * reason: somebody who has forgotten their password cannot sign in to ask for
 * a new one. The protection that matters here is not a token, it is that the
 * answer never changes — "if that email is registered, a reset link has been
 * sent" — whether or not it is, and whether or not the mail went out. A form
 * that says "no account with that email" turns a login page into a way of
 * asking whether somebody is in A.A.
 *
 * The live script gets that right and this keeps it, with a rate limit added:
 * the generic answer stops an attacker learning anything from one request,
 * and the limit stops them learning it from the timing of ten thousand.
 *
 * ## The link points where it already points
 *
 * `/app/validate2/reset_password.php` is the page that is live today, served
 * by the legacy PHP app, and there are reset emails in people's inboxes
 * pointing at it. The URL is configuration (`toolkit.auth.reset_url`) so it
 * can move to a page here later without a code change.
 *
 * ## What is not logged
 *
 * The address, and the code. A reset code is a credential for the few minutes
 * it lives, and an address is a person. A failure logs that sending failed
 * and nothing else.
 */
class PasswordResetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $email = trim((string) $request->input('email', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return LegacyEnvelope::fail('Please provide a valid email address.', 400);
        }

        // One answer, always, whatever happens below.
        $generic = fn (): JsonResponse => LegacyEnvelope::ok(
            null,
            'If that email is registered, a reset link has been sent.',
        );

        $account = Account::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->where('email', '!=', 'DELETED')
            ->first();

        if ($account === null) {
            return $generic();
        }

        $minutes = max(5, (int) config('toolkit.auth.reset_ttl_minutes', 30));
        $code = bin2hex(random_bytes(16)); // 32 hex characters: `reset_code` is char(32).

        DB::transaction(function () use ($account, $code, $minutes): void {
            // One live code per account, as the live script does: asking
            // again invalidates the link in the older email.
            DB::table('password_resets')->where('account_id', $account->id)->delete();

            DB::table('password_resets')->insert([
                'account_id' => (int) $account->id,
                'reset_code' => $code,
                'expires_at' => now()->addMinutes($minutes)->getTimestamp(),
                'created_at' => now(),
            ]);
        });

        try {
            Mail::to($email)->send(new PasswordResetLink($this->link($code), $minutes));
        } catch (\Throwable $e) {
            // The row is written either way; a person who did not get the
            // email asks again. Never the address, never the code.
            Log::warning('password reset email failed', ['reason' => $e->getMessage()]);
        }

        return $generic();
    }

    private function link(string $code): string
    {
        $base = (string) config('toolkit.auth.reset_url');

        return $base.(str_contains($base, '?') ? '&' : '?').'reset_code='.$code;
    }
}
