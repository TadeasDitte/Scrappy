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

    'netint' => [
        'scrape_url' => env('NETINT_SCRAPE_URL', 'https://netint.xyz/?mode=topd&q=ollama&limit=all&offset=0'),
    ],

    'ollama' => [
        'probe_timeout' => (int) env('OLLAMA_PROBE_TIMEOUT', 8),
        'generate_timeout' => (int) env('OLLAMA_GENERATE_TIMEOUT', 120),
        'probe_concurrency' => (int) env('PROBE_CONCURRENCY', 20),
        'user_agent' => env('OLLAMA_USER_AGENT', 'Scrappy/1.0 (+ollama-endpoint-aggregator)'),
    ],

];
