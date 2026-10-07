<?php

/**
 * The compatibility layer for the two PHP APIs this application replaces.
 *
 * The owner's decision was a **full replacement in one cutover**: the v19
 * (Android) and v8 (Apple) script hosts are pointed at this application and
 * switched off. The apps in the field do not know that, so this application has
 * to answer them in their own words — byte for byte, including the trailing
 * space in `"success "` — while refusing the things those scripts allowed.
 *
 * Everything here is a switch, because every one of these behaviours is a
 * liability that should be able to be turned off the day the last old install
 * is gone.
 *
 * ## The hard part, stated plainly
 *
 * The Apple API's whole authentication scheme is one static shared secret,
 * compared with `!=`, that is compiled into every App Store build of 1.6.6 and
 * is additionally printed in an `<input value="…">` in a `test.html` that is
 * served over the web. It proves nothing. Accepting it keeps every iOS user
 * working through the cutover; refusing it bricks them.
 *
 * So it is accepted, and it is contained:
 *
 *  * it no longer implies *who* you are. Every read and every write is scoped
 *    with an ownership clause, which the old scripts did not have —
 *    `8/deleterecord.php` ran `delete from $t where id='$id'` with no account
 *    clause at all;
 *  * `8/updatefield.php` let the caller name the column to write. The
 *    replacement has an allow-list, and `email`, `password`, `hashed_password`
 *    and `subscribed` are not on it;
 *  * it is rate-limited per address, which a static secret in a binary needs
 *    and never had;
 *  * an account is **sealed** the first time it signs in on 2.0 — after that
 *    the legacy paths refuse it entirely. The exposure therefore shrinks with
 *    every upgrade instead of lasting for ever;
 *  * and `legacy.apple.enabled` turns the whole thing off in one line.
 *
 * What cannot be fixed without breaking the old client: somebody holding the
 * secret who already knows an account id can still read that account's records.
 * That is the old app's design, and the only cure is the users upgrading.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | The Android API (v19) — JWT + per-install HMAC
    |--------------------------------------------------------------------------
    |
    | This one is genuinely authenticated: a bearer JWT plus an HMAC-SHA256
    | signature over `METHOD\n PATH?QUERY\n SHA256(body)\n timestamp\n nonce`,
    | keyed with a per-install secret from `install_secrets`. It is kept as-is
    | because the shipped Android app signs exactly that string.
    |
    | Two things are added that the live scripts have and do not use:
    |
    |  * the nonce is **recorded**. `auth_checker.php` reads `X-Nonce` and
    |    throws it away, with a comment saying replay protection is still to do,
    |    so a captured request is replayable for the whole 300-second window.
    |    `signature_checker.php` in the same directory does it properly and no
    |    endpoint includes it. This application uses the second one's semantics
    |    and the first one's canonical string;
    |  * the JWT's lifetime. Every legacy login minted a 296,000,000-second
    |    token — about 9.4 years — with no revocation list.
    */
    'android' => [
        'enabled' => filter_var(env('LEGACY_V19_ENABLED', true), FILTER_VALIDATE_BOOL),

        // The HS256 secret the shipped Android app's tokens are signed with.
        // Tokens already in the field were signed with it, so it has to be
        // accepted until those installs are gone.
        'jwt_secret' => (string) env('LEGACY_JWT_SECRET', ''),
        'jwt_issuer' => (string) env('LEGACY_JWT_ISSUER', '12steptoolkit'),

        // How far out of step a signed request may be. The scripts allow 300.
        'hmac_window_seconds' => (int) env('LEGACY_HMAC_WINDOW', 300),

        // Nonces are kept for a little longer than the window, then swept.
        'nonce_retention_seconds' => (int) env('LEGACY_NONCE_RETENTION', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | The Apple API (v8) — the published shared secret
    |--------------------------------------------------------------------------
    */
    'apple' => [
        'enabled' => filter_var(env('LEGACY_V8_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Compared with hash_equals, not `!=`. An empty value refuses everything,
        // which is the right default for a secret that proves nothing.
        'server_secret' => (string) env('LEGACY_SERVER_SECRET', ''),

        // The columns `updatefield.php` is allowed to write. The old script had
        // no list at all: `UPDATE accounts SET $field = '$value'`.
        'updatable_account_fields' => [
            'nickname', 'icon', 'sobrietydate', 'sobrietytime', 'hp',
            'step2', 'step3', 'step6', 'step7', 'newsletter_subscribed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sealing
    |--------------------------------------------------------------------------
    |
    | Once an account has signed in on 2.0, the legacy protocols stop answering
    | for it. The user has a client that authenticates properly; there is no
    | reason to keep a weaker door open behind them.
    */
    'seal_on_v2_login' => filter_var(env('LEGACY_SEAL_ON_V2_LOGIN', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Per-identity limits on the legacy paths
    |--------------------------------------------------------------------------
    |
    | The old scripts had none. A verification code could be requested in a loop
    | and `login_email.php` could be tried for ever.
    */
    'limits' => [
        'code_requests_per_day' => (int) env('LEGACY_CODE_REQUESTS_PER_DAY', 10),
        'code_checks_per_day' => (int) env('LEGACY_CODE_CHECKS_PER_DAY', 20),
        'password_attempts_per_hour' => (int) env('LEGACY_PASSWORD_ATTEMPTS_PER_HOUR', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | The plaintext password column
    |--------------------------------------------------------------------------
    |
    | `accounts` carries both `password` (plaintext, what iOS still compares
    | against) and `hashed_password` (bcrypt, what Android writes). Android's
    | `login_email.php` blanks the plaintext column on first sign-in — which
    | silently breaks that person's iOS login, because iOS matches on
    | `password = '…'`. That is live today.
    |
    |  * `keep`  — leave the plaintext column alone, so both apps keep working.
    |              Weaker, and honest about why.
    |  * `clear` — blank it on any successful sign-in. Safer, and it ends iOS
    |              email sign-in for that account until they upgrade.
    |
    | Default `keep` until the cutover is done, then `clear`.
    */
    'plaintext_password' => (string) env('LEGACY_PLAINTEXT_PASSWORD', 'keep'),
];
