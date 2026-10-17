<?php

return [

    'hetzner' => [
        'token' => env('HETZNER_API_TOKEN'),
    ],

    'provisioning' => [
        'ssh_binary' => env('SSH_BINARY', PHP_OS_FAMILY === 'Windows'
            ? (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\OpenSSH\\ssh.exe' : '/usr/bin/ssh'),
    ],

    'bridge_development' => [
        'python_binary' => env('BRIDGE_DEV_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'),
        'source' => env('BRIDGE_DEV_SOURCE', dirname(base_path()).'/civicrm_bridge'),
        'game_source' => env('BRIDGE_DEV_GAME_SOURCE'),
        'origin' => 'https://localhost:18443',
        'ca_certificate' => storage_path('app/private/bridge-development/ca.crt'),
    ],

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

];
