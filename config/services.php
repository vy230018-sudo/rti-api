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

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN'),
    ],

     // Firebase Cloud Messaging (push notifications)
    // FIREBASE_CREDENTIALS .env me na ho to storage/app/firebase-credentials.json use hota hai.
    'fcm' => [
        'enabled' => env('FCM_ENABLED', true),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/rti-news-653de-firebase-adminsdk-fbsvc-a5d7e68e03.json')),
    ],

    // Registration OTP: fixed OTP jab tak SMTP kaam nahi karta. Khali chhodo to real email OTP chalega.
    'registration_static_otp' => env('REGISTRATION_STATIC_OTP', '183232'),

    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
    ],

    // Absolute path may be supplied on shared hosting (for example
    // C:\\ffmpeg\\bin\\ffmpeg.exe).  The PATH binary remains the default.
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    ],

];
