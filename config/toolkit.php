<?php

/**
 * 12 Step Toolkit — the application's own settings.
 *
 * Framework configuration stays in the stock files; everything that is a
 * decision about *this* product lives here, with the reason next to it.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Accounts and sign-in
    |--------------------------------------------------------------------------
    |
    | The legacy system has four ways in — anonymous, email + password, Google
    | and Apple — and all four have to keep working, because all four are in
    | the field. What changes is how they are checked, not that they exist.
    */
    /*
    |--------------------------------------------------------------------------
    | Support
    |--------------------------------------------------------------------------
    |
    | The address on the public contact page, and where a reply comes from.
    | The static site put a form here that posted off-site; this application
    | already has `support_tickets` and a console screen for answering them,
    | so the eventual form opens a ticket. Until then the address is the page.
    */
    'support' => [
        'email' => env('SUPPORT_EMAIL', 'support@12steptoolkit.com'),
    ],

    'auth' => [
        // Sanctum personal access tokens. The legacy JWT had a 296,000,000-second
        // life (about 9.4 years) and no revocation list, which is why a single
        // leaked token from `Ztest_jwt.php` was a permanent account takeover.
        'token_ttl_days' => (int) env('TOKEN_TTL_DAYS', 60),
        'max_tokens_per_user' => (int) env('MAX_TOKENS_PER_USER', 20),

        // Email verification codes.
        'otp_length' => (int) env('OTP_LENGTH', 6),
        'otp_ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'otp_max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'otp_resend_seconds' => (int) env('OTP_RESEND_SECONDS', 30),

        // The old apps' verification code is 4 digits and their UI cannot take
        // six, so the legacy path keeps its own length.
        'legacy_otp_length' => 4,

        // Accounts the stores' reviewers use. Compared in constant time.
        'review_accounts' => array_values(array_filter(array_map('trim', explode(',', (string) env('REVIEW_ACCOUNTS', ''))))),
        'review_code' => (string) env('REVIEW_CODE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Step work
    |--------------------------------------------------------------------------
    |
    | The limits live in the `appsettings` row, which both apps already read and
    | which can be changed without a release. These are only the ceilings this
    | server will accept regardless of what that row says, so a bad row cannot
    | turn into a 50 MB insert.
    */
    'records' => [
        'max_text_bytes' => (int) env('RECORD_MAX_TEXT_BYTES', 65535),
        'max_batch' => (int) env('RECORD_MAX_BATCH', 200),
        // Collections the sync endpoint knows about, in the order the client
        // sends them. The names are the table names, which are also the names
        // the Flutter app's `SyncCollection` uses.
        'collections' => [
            'inventories', 'amends', 'nights', 'mornings', 'journals', 'gratitudes',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup
    |--------------------------------------------------------------------------
    |
    | `DECISIONS.md` D-001: cloud backup is free for everyone. Android cleared
    | the six step-work dirty flags for non-subscribers, so a free user's
    | writing was never uploaded, and `allowBackup="false"` meant the OS could
    | not save it either. There is no entitlement check on the sync path and
    | this flag exists so that re-introducing one is a visible act.
    */
    'sync' => [
        'subscription_required' => filter_var(env('SYNC_NEEDS_SUBSCRIPTION', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | What the app is told at launch
    |--------------------------------------------------------------------------
    */
    'app' => [
        'min_supported_version' => [
            'ios' => (string) env('MIN_VERSION_IOS', '1.0.0'),
            'android' => (string) env('MIN_VERSION_ANDROID', '1.0.0'),
        ],
        'store' => [
            'ios_app_id' => (string) env('IOS_APP_ID', ''),
            'ios_bundle_id' => (string) env('IOS_BUNDLE_ID', 'com.12stepapp.recoverybox'),
            'android_package' => (string) env('ANDROID_PACKAGE', 'com.ibyteapps.aa12steptoolkit'),
        ],
        'links' => [
            'website' => 'https://12steptoolkit.com',
            'privacy' => 'https://12steptoolkit.com/privacy',
            'terms' => 'https://12steptoolkit.com/terms',
            'delete_account' => 'https://12steptoolkit.com/delete-account',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Icons (profile pictures)
    |--------------------------------------------------------------------------
    |
    | Kept outside the web root, exactly as the PHP scripts do, and streamed by
    | the application rather than served by the web server — that is what makes
    | "only people who may see this picture can fetch it" enforceable.
    */
    'icons' => [
        'path' => (string) env('ICONS_PATH', '/var/www/vhosts/ibyteserver.com/ICONS/12steptoolkit.com'),
        'max_bytes' => (int) env('ICON_MAX_BYTES', 4 * 1024 * 1024),
        'built_in_count' => 40, // `accounts.icon` 1..40 is a bundled avatar; above that it is an `icons.id`.
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations
    |--------------------------------------------------------------------------
    */
    'ops' => [
        'queue_worker_in_scheduler' => filter_var(env('QUEUE_WORKER_IN_SCHEDULER', true), FILTER_VALIDATE_BOOL),
    ],
];
