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

    'sendy' => [
        'url' => env('SENDY_URL'),
        'api_key' => env('SENDY_API_KEY'),
        // key => Sendy list id. Keys are what the app shows; ids stay on the server.
        'lists' => array_filter([
            'sobriety_10_days' => env('SENDY_LIST_SOBRIETY_10_DAYS'),
            'silkworth_newsletter' => env('SENDY_LIST_SILKWORTH_NEWSLETTER'),
            'daily_reflections' => env('SENDY_LIST_DAILY_REFLECTIONS'),
        ]),
    ],

];
