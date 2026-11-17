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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // API pública de MINEDU / ESCALE (comando sync:locations).
    'escale' => [
        'url' => env('ESCALE_URL', 'https://escale.minedu.gob.pe/padron/rest'),
        'retries' => (int) env('ESCALE_RETRIES', 3),
        'retry_delay_ms' => (int) env('ESCALE_RETRY_DELAY_MS', 500),
        'timeout' => (int) env('ESCALE_TIMEOUT', 30),
    ],

];
