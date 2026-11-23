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

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Gateway (provider-neutral)
    |--------------------------------------------------------------------------
    |
    | Any provider is configured here; the code never hard-codes one. The
    | `endpoint`, `headers` and `body` templates use {placeholders} that the
    | gateway fills per message:
    |
    |   {to}        recipient phone number
    |   {body}      message text
    |   {type}      text | image | video | document
    |   {media_url} media URL for non-text types
    |   {filename}  document filename
    |
    | Enable with WHATSAPP_ENABLED=true and point the endpoint at your
    | provider. Edit the templates here to match the provider's payload.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway (Midtrans)
    |--------------------------------------------------------------------------
    |
    | Snap charges and webhook signatures. Switch to production by setting
    | MIDTRANS_SNAP_BASE to https://app.midtrans.com/snap/v1 and using the
    | production server key.
    |
    */

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
        'snap_base' => env('MIDTRANS_SNAP_BASE', 'https://app.sandbox.midtrans.com/snap/v1'),
        'timeout' => (int) env('MIDTRANS_TIMEOUT', 15),
    ],

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'endpoint' => env('WHATSAPP_ENDPOINT'),
        'method' => env('WHATSAPP_METHOD', 'POST'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),
        'headers' => [
            'Authorization' => env('WHATSAPP_TOKEN'),
        ],
        'body' => [
            'target' => '{to}',
            'message' => '{body}',
            'url' => '{media_url}',
        ],
    ],

];
