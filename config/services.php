<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Sign in with Google / Apple — identity tokens are verified server-side against
    | the providers' published keys. List every client id that may appear as `aud`.
    */
    'google' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
    ],

    'apple' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_CLIENT_IDS', 'com.12stepapp.recoverybox'))))),
        'jwks_url' => 'https://appleid.apple.com/auth/keys',
        'issuer' => 'https://appleid.apple.com',
    ],

    'revenuecat' => [
        'api_base' => env('REVENUECAT_API_BASE', 'https://api.revenuecat.com/v1'),
        'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
        'webhook_auth' => env('REVENUECAT_WEBHOOK_AUTH'),
        'entitlement' => env('REVENUECAT_ENTITLEMENT', 'subscribed'),
    ],

    /*
     | Sendy, the mailing list.
     |
     | Nothing here is hard-coded, and that is the point: `8/newsletter_subscribe.php`
     | carries its API key and its list id in the file (AUDIT_AND_IMPROVEMENTS.md
     | §1.9), and `8/getnewslettersubscribed.php` opens the Sendy install's own
     | database with the application's credentials. This talks to Sendy over its
     | HTTP API, with a key from the environment, or — when the key is not set —
     | does nothing and says so.
     */
    'sendy' => [
        'url' => env('SENDY_URL'),
        'api_key' => env('SENDY_API_KEY'),
        // key => Sendy list id. Keys are what the app shows; ids stay on the server.
        'lists' => array_filter([
            'sobriety_10_days' => env('SENDY_LIST_SOBRIETY_10_DAYS'),
            'silkworth_newsletter' => env('SENDY_LIST_SILKWORTH_NEWSLETTER'),
            'daily_reflections' => env('SENDY_LIST_DAILY_REFLECTIONS'),
            /*
             | The list both old apps subscribe to. iOS posts `list=5`, which is
             | the row id inside Sendy's own database and not something its API
             | accepts; the API wants the encrypted list id from "View all
             | lists". So the posted number is ignored and this is used.
             */
            'toolkit' => env('SENDY_LIST_TOOLKIT'),
        ]),
        'default_list' => env('SENDY_DEFAULT_LIST', 'toolkit'),
        'timeout' => (int) env('SENDY_TIMEOUT', 10),
    ],

];
