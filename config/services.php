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

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'docusign' => [
        'client_id'        => env('DOCUSIGN_CLIENT_ID'),
        'client_secret'    => env('DOCUSIGN_CLIENT_SECRET'),
        'account_id'       => env('DOCUSIGN_ACCOUNT_ID'),
        'private_key_path' => env('DOCUSIGN_PRIVATE_KEY_PATH', storage_path('app/docusign.key')),
        'base_path'        => env('DOCUSIGN_BASE_PATH', 'https://demo.docusign.net/restapi'),
        'oauth_base'       => env('DOCUSIGN_OAUTH_BASE', 'account-d.docusign.com'),
        'impersonate_user' => env('DOCUSIGN_IMPERSONATE_USER_ID'),
        'webhook_secret'   => env('DOCUSIGN_WEBHOOK_HMAC_SECRET'),
    ],

    'twilio' => [
        'sid'   => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from'  => env('TWILIO_FROM'),
    ],

];
