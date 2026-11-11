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

    // Buzón electrónico. SUNAT/SUNAFIL no publican un API REST público del
    // buzón, así que la sincronización se apoya en endpoints configurables por
    // entorno; sin ellos el alta manual sigue funcionando.
    'buzon' => [
        'sunat_endpoint' => env('SUNAT_BUZON_ENDPOINT'),
        'sunafil_endpoint' => env('SUNAFIL_BUZON_ENDPOINT'),
        'token' => env('BUZON_TOKEN'),
        'cert_alerta_dias' => env('BUZON_CERT_ALERTA_DIAS', 30),
    ],

];
