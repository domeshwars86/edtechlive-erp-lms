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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'enquiries' => [
        'recipient' => env('ENQUIRY_RECIPIENT'),
        'admin_emails' => array_values(array_filter(array_map('trim', explode(',', env('ENQUIRY_ADMIN_EMAILS', ''))))),
    ],

    'platform' => [
        'admin_emails' => array_values(array_filter(array_map('trim', explode(',', env('PLATFORM_ADMIN_EMAILS', ''))))),
    ],

    'google_meet' => [
        'client_id' => env('GOOGLE_MEET_CLIENT_ID'),
        'client_secret' => env('GOOGLE_MEET_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_MEET_REDIRECT_URI'),
        'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:4173'),
    ],

    'razorpay' => [
        // Customer payments are deliberately limited to test credentials. Live charges are not enabled.
        'mode' => env('RAZORPAY_MODE', 'disabled'),
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'enabled' => env('RAZORPAY_MODE', 'disabled') === 'test'
            && str_starts_with((string) env('RAZORPAY_KEY_ID', ''), 'rzp_test_')
            && filled(env('RAZORPAY_KEY_SECRET')),
        'brand_name' => env('RAZORPAY_BRAND_NAME', 'edTechLive'),
    ],

];
