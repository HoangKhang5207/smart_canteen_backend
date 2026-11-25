<?php

return [

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

    'zalo' => [
        'app_id' => env('ZALO_APP_ID', ''),
        'app_secret' => env('ZALO_APP_SECRET', ''),
    ],

    'demo' => [
        'login_enabled' => filter_var(env('DEMO_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'banking' => [
        'driver' => env('BANKING_GATEWAY_DRIVER', 'mock'),
        'otp_ttl_seconds' => (int) env('BANKING_OTP_TTL_SECONDS', 180),
        'otp_resend_cooldown_seconds' => (int) env('BANKING_OTP_RESEND_COOLDOWN_SECONDS', 30),
        'otp_max_attempts' => (int) env('BANKING_OTP_MAX_ATTEMPTS', 5),
        'otp_max_resends' => (int) env('BANKING_OTP_MAX_RESENDS', 3),
        'expose_debug_otp' => filter_var(env('BANKING_EXPOSE_DEBUG_OTP', false), FILTER_VALIDATE_BOOL),
        'auto_provision_demo_card' => filter_var(env('BANKING_AUTO_PROVISION_DEMO_CARD', false), FILTER_VALIDATE_BOOL),
    ],

];
