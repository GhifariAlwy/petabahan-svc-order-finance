<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'biteship' => [
        'api_key' => env('BITESHIP_API_KEY', 'biteship_test.eyJhbGciOi...'),
        'webhook_secret' => env('BITESHIP_WEBHOOK_SECRET', 'biteship_webhook_secret_dev'),
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com/v1'),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID', 'petabahan-app'),
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
    ],

    'whatsapp' => [
        'api_token' => env('WHATSAPP_API_TOKEN', 'dummy_wa_token'),
        'endpoint' => env('WHATSAPP_API_ENDPOINT', 'https://api.fonnte.com/send'),
    ],

];
