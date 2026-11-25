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
        'driver' => env('BANKING_GATEWAY_DRIVER', 'sandbox'),
        'webhook_secret' => env('BANKING_WEBHOOK_SECRET', ''),
        'webhook_tolerance_seconds' => (int) env('BANKING_WEBHOOK_TOLERANCE_SECONDS', 300),
        'otp_ttl_seconds' => (int) env('BANKING_OTP_TTL_SECONDS', 180),
        'otp_resend_cooldown_seconds' => (int) env('BANKING_OTP_RESEND_COOLDOWN_SECONDS', 30),
        'otp_max_attempts' => (int) env('BANKING_OTP_MAX_ATTEMPTS', 5),
        'otp_max_resends' => (int) env('BANKING_OTP_MAX_RESENDS', 3),
        'expose_debug_otp' => filter_var(env('BANKING_EXPOSE_DEBUG_OTP', false), FILTER_VALIDATE_BOOL),
        'auto_provision_demo_card' => filter_var(env('BANKING_AUTO_PROVISION_DEMO_CARD', false), FILTER_VALIDATE_BOOL),
    ],

    'vnpay' => [
        'tmn_code' => env('VNPAY_TMN_CODE', ''),
        'hash_secret' => env('VNPAY_HASH_SECRET', ''),
        'payment_url' => env('VNPAY_PAYMENT_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
        'return_url' => env('VNPAY_RETURN_URL', 'http://localhost:8080/api/v1/payments/vnpay/return'),
        'sandbox_simulator_enabled' => filter_var(env('VNPAY_SANDBOX_SIMULATOR_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'brandname' => env('SMS_BRANDNAME', 'SmartCanteen'),
        'esms' => [
            'endpoint' => env('ESMS_ENDPOINT', 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_post_json/'),
            'api_key' => env('ESMS_API_KEY', ''),
            'secret_key' => env('ESMS_SECRET_KEY', ''),
            'sms_type' => (int) env('ESMS_SMS_TYPE', 2),
            'sandbox' => filter_var(env('ESMS_SANDBOX', true), FILTER_VALIDATE_BOOL),
        ],
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
            'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
            'from' => env('TWILIO_FROM', ''),
        ],
    ],

];
