<?php

// PayDunya exposes one shared "master key" per account, but distinct
// public/private/token credentials for the sandbox and live environments.
// Resolve the active set from PAYDUNYA_MODE so both can live side by side.
$paydunyaMode = strtolower((string) env('PAYDUNYA_MODE', 'test')) === 'live' ? 'live' : 'test';

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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PayDunya (mobile money + cards, West Africa / XOF)
    |--------------------------------------------------------------------------
    |
    | Credentials come from the PayDunya Business dashboard ("Intégrez notre
    | API" -> application details). `mode` selects the sandbox vs live API base
    | URL: test -> /sandbox-api/v1, live -> /api/v1.
    |
    */
    'paydunya' => [
        'mode' => $paydunyaMode,
        'master_key' => env('PAYDUNYA_MASTER_KEY'),
        // Mode-specific credentials, falling back to the generic names for
        // single-environment installs. test -> PAYDUNYA_TEST_*, live -> PAYDUNYA_LIVE_*.
        'public_key' => env("PAYDUNYA_{$paydunyaMode}_PUBLIC_KEY", env('PAYDUNYA_PUBLIC_KEY')),
        'private_key' => env("PAYDUNYA_{$paydunyaMode}_PRIVATE_KEY", env('PAYDUNYA_PRIVATE_KEY')),
        'token' => env("PAYDUNYA_{$paydunyaMode}_TOKEN", env('PAYDUNYA_TOKEN')),
        'store_name' => env('PAYDUNYA_STORE_NAME', 'SunuHotel'),
        'logo_url' => env('PAYDUNYA_LOGO_URL'),
        'mock' => (bool) env('PAYDUNYA_MOCK', false),
    ],

];
