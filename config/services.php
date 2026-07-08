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

    'solabooks' => [
        'api_base_url' => env('SOLABOOKS_API_BASE_URL', rtrim((string) env('SOLABOOKS_BASE_URL', ''), '/').'/api/v1'),
        'journal_entries_url' => env('SOLABOOKS_JOURNAL_ENTRIES_URL'),
        'api_key' => env('SOLABOOKS_API_KEY'),
        'client_id' => env('SOLABOOKS_CLIENT_ID'),
        'organization_id' => env('SOLABOOKS_ORGANIZATION_ID'),
        'timeout' => env('SOLABOOKS_API_TIMEOUT', 10),
    ],

];
