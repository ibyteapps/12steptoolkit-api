<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountDetail;
use App\Models\SignIn;
use App\Services\Legacy\LegacyEnvelope;
use App\Services\Legacy\LegacyJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The five ways in.
 *
 * ## What is kept
 *
 * The response shapes, exactly: `{status, message, response: {account_id,
 * access_token, expires_at, ...}}`, with `login_email`, `login_google` and
 * `login_2` answering **HTTP 200 with `status: false`** on a failure rather
 * than a 4xx, because that is what the clients parse.
 *
 * ## What is not
 *
 * **`login_google.php` takes an email and nothing else.** It looks the address
 * up and issues a nine-year token for whatever account it finds. There is no
 * Google token, nothing is verified, and knowing somebody's email address is
 * the whole of the authentication (`SRV-010`). Reproducing that in a
 * replacement would be choosing to ship it again.
 *
 * So: a social sign-in must carry an identity token, which is verified against
 * the provider's published keys — signature, issuer, audience and expiry — and
 * the email is taken from the verified token, never from the request. Email
 * alone is accepted only when `LEGACY_SOCIAL_EMAIL_MATCH` is explicitly true,
 * which it is not by default, and which exists so that a cutover can be rolled
 * forward in two steps rather than one if the old Android build turns out to
 * need it.
 *
 * **The Flutter client currently sends email only** —
 * `auth_repository.dart:168` is `api.loginGoogle(email: identity.email.trim())`.
 * It is not released, so it can be changed to send `id_token`, and it must be
 * before this endpoint is useful. `docs/OPEN_QUESTIONS.md` carries it.
 *
 * **Token lifetime.** The old paths all issue 296,000,000 seconds. These issue
 * `toolkit.auth.token_ttl_days`.
 */
class AuthController extends Controller
{
    /**
     * `login_new_account.php` — an anonymous account, created on first launch.
     *
     * The old script also creates an AI `comment_threads` row and two
     * subscribers inside a transaction with a deadlock retry. That is the chat
     * feature's business and it is done by the chat module when it exists; the
     * account itself is created here and nothing else.
     *
     * Note the defaults in the original: `country` defaults to `"US"` and
     * `country_code` to `"United States"` — the two are swapped. They are
     * written the right way round here, which changes nothing for anybody
     * except that the column finally holds what it says.
     */
    public function newAccount(Request $request): JsonResponse
    {
        $account = DB::transaction(function () use ($request): Account {
            $account = new Account;
            $account->forceFill([
                'verified' => 0,
                'accounttype' => 1,
                'sociallogin' => Account::SOCIAL_ANONYMOUS,
                'devicetype' => Account::DEVICE_ANDROID,
                'software_version' => 2,
                // A single space, as the old code writes it: a client somewhere
                // tells an empty nickname apart from an absent one.
                'nickname' => ' ',
                'icon' => 0,
                'subscribed' => 0,
                'fcm_token' => (string) $request->input('fcmToken', ''),
                'timestamp' => time(),
                'created_timestamp' => time(),
                'last_login_tstamp' => time(),
                'created' => now(),
                'modified' => now(),
            ])->save();

            (new AccountDetail)->forceFill([
                'accountid' => $account->id,
                'language' => (string) $request->input('language', ''),
                'timezone' => (string) $request->input('timeZone', 'UTC'),
                'country' => (string) $request->input('country', 'United States'),
                'countrycode' => (string) $request->input('country_code', 'US'),
                'created' => now(),
            ])->save();

            return $account;
        });

        return $this->signedIn($account, 'anonymous', $request, extra: ['thread_id' => 0]);
    }

    /**
     * `login_email.php` — email and password.
     *
     * The migration from the plaintext `password` column to bcrypt
     * `hashed_password` is kept, because accounts in the field still have only
     * the plaintext one. What is configurable is whether the plaintext column
     * is then blanked: doing so is safer and breaks that person's iOS sign-in,
     * which is live behaviour today and surprises people. See
     * `config/legacy.php` → `plaintext_password`.
     */
    public function email(Request $request): JsonResponse
    {
        $email = Account::normaliseEmail($request->input('email'));
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            return LegacyEnvelope::fail('Email and password are required', 200);
        }

        $account = Account::findByEmail($email);
        if ($account === null) {
            return LegacyEnvelope::fail('No account with that email', 200);
        }

        $hashed = (string) ($account->getRawOriginal('hashed_password') ?? '');
        $plaintext = (string) ($account->getRawOriginal('password') ?? '');

        $ok = $hashed !== ''
            ? Hash::check($password, $hashed)
            : ($plaintext !== '' && hash_equals($plaintext, $password));

        if (! $ok) {
            return LegacyEnvelope::fail('Password Error', 200);
        }

        $changes = ['last_login_tstamp' => time(), 'modified' => now()];
        if ($hashed === '') {
            $changes['hashed_password'] = Hash::make($password);
        }
        if (config('legacy.plaintext_password') === 'clear') {
            $changes['password'] = '';
        }
        $account->forceFill($changes)->save();

        return $this->signedIn($account, 'password', $request, message: 'Logged In!');
    }

    /** `login_google.php`. */
    public function google(Request $request): JsonResponse
    {
        return $this->social($request, Account::SOCIAL_GOOGLE, createIfMissing: false, message: 'Logged In via Google!');
    }

    /** `login_2.php` — the same, but it creates the account on a miss. */
    public function googleOrCreate(Request $request): JsonResponse
    {
        return $this->social($request, Account::SOCIAL_GOOGLE, createIfMissing: true, message: 'Logged In!');
    }

    /**
     * `login_upgrade.php` — an install that already has an account id from the
     * old world and needs its first token.
     *
     * This is the endpoint the security patch set added a rate-limit ledger for
     * (`upgrade_attempts`), because it will hand a token to anybody who guesses
     * an account id. The limiter here is `throttle:legacy-login`, keyed on the
     * address, and the account must have no 2.0 history: an account that has
     * already been sealed cannot be claimed this way.
     */
    public function upgrade(Request $request): JsonResponse
    {
        $accountId = (int) $request->input('account_id', 0);
        if ($accountId <= 0) {
            return LegacyEnvelope::fail('account_id is required', 400);
        }

        $account = Account::query()->find($accountId);
        if ($account === null || $account->isSealed()) {
            // Deliberately the same answer for both, so this cannot be used to
            // find out which account ids exist.
            return LegacyEnvelope::fail('Unable to upgrade this account', 404);
        }

        $account->forceFill([
            'software_version' => 2,
            'fcm_token' => (string) $request->input('fcmToken', $account->fcm_token ?? ''),
            'last_login_tstamp' => time(),
            'modified' => now(),
        ])->save();

        return $this->signedIn($account, 'legacy', $request, extra: ['thread_id' => 0]);
    }

    // --------------------------------------------------------------- shared

    private function social(Request $request, int $provider, bool $createIfMissing, string $message): JsonResponse
    {
        $email = $this->verifiedEmail($request, $provider);

        if ($email === null) {
            return LegacyEnvelope::fail('This sign-in could not be verified.', 200);
        }

        $account = Account::findByEmail($email);

        if ($account === null && ! $createIfMissing) {
            return LegacyEnvelope::fail('No account with that email', 200);
        }

        $isNew = $account === null;
        if ($isNew) {
            $account = DB::transaction(function () use ($email, $provider, $request): Account {
                $account = new Account;
                $account->forceFill([
                    'email' => $email,
                    'verified' => 1,
                    'accounttype' => 1,
                    'sociallogin' => $provider,
                    'devicetype' => Account::DEVICE_ANDROID,
                    'software_version' => 2,
                    'nickname' => ' ',
                    'icon' => 0,
                    'subscribed' => 0,
                    'timestamp' => time(),
                    'created_timestamp' => time(),
                    'last_login_tstamp' => time(),
                    'created' => now(),
                    'modified' => now(),
                ])->save();

                (new AccountDetail)->forceFill([
                    'accountid' => $account->id,
                    'language' => (string) $request->input('language', ''),
                    'timezone' => (string) $request->input('timeZone', 'UTC'),
                    'created' => now(),
                ])->save();

                return $account;
            });
        } else {
            $account->forceFill(['last_login_tstamp' => time(), 'modified' => now()])->save();
        }

        return $this->signedIn($account, $provider === Account::SOCIAL_APPLE ? 'apple' : 'google', $request, $message, [
            'is_new' => $isNew,
            'nickname' => (string) ($account->nickname ?? ''),
        ]);
    }

    /**
     * The address a verified identity token names, or null.
     *
     * Returns the request's own `email` only when `LEGACY_SOCIAL_EMAIL_MATCH`
     * is explicitly on — which is the old, broken behaviour, kept switchable
     * and off.
     */
    private function verifiedEmail(Request $request, int $provider): ?string
    {
        $token = (string) $request->input('id_token', $request->input('identity_token', ''));

        if ($token !== '') {
            $verifier = app('identity.verifier.'.($provider === Account::SOCIAL_APPLE ? 'apple' : 'google'));
            $identity = $verifier->verify($token);

            return $identity === null ? null : Account::normaliseEmail($identity->email);
        }

        if (! filter_var(env('LEGACY_SOCIAL_EMAIL_MATCH', false), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $email = Account::normaliseEmail($request->input('email'));

        return $email === '' ? null : $email;
    }

    private function signedIn(
        Account $account,
        string $method,
        Request $request,
        string $message = 'ok',
        array $extra = [],
    ): JsonResponse {
        $issued = LegacyJwt::make()->issue((int) $account->id);

        $security = $account->securityRow();
        $changes = [
            'last_login_at' => now(),
            'first_v2_login_at' => $security->first_v2_login_at ?? now(),
        ];
        // Sealing closes the *Apple* door, not this one. This path is properly
        // authenticated — a bearer token, and an install-keyed signature on
        // everything after it — so an account that has been seen here no longer
        // needs the published shared secret to be able to reach it.
        if (config('legacy.seal_on_v2_login') && $security->v1_sealed_at === null) {
            $changes['v1_sealed_at'] = now();
        }
        $security->forceFill($changes)->save();

        SignIn::query()->create([
            'account_id' => $account->id,
            'method' => $method,
            'platform' => $request->header('X-App-Platform'),
            'app_version' => $request->header('X-App-Version'),
            'created_at' => now(),
        ]);

        return LegacyEnvelope::ok([
            'account_id' => (int) $account->id,
            'access_token' => $issued['access_token'],
            'expires_at' => $issued['expires_at'],
        ] + $extra, $message);
    }
}
