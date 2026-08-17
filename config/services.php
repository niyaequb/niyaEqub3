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

    'equb' => [
        'draw_delay' => env('EQUB_DRAW_DELAY', 30),
        'auto_draw_enabled' => filter_var(env('EQUB_AUTO_DRAW_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'auto_start_enabled' => filter_var(env('EQUB_AUTO_START_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'members_per_draw' => (int) env('EQUB_MEMBERS_PER_DRAW', 50),
        'restrict_draw_frequency' => filter_var(env('EQUB_RESTRICT_DRAW_FREQUENCY', true), FILTER_VALIDATE_BOOLEAN),
        'enforce_draw_schedule' => filter_var(env('EQUB_ENFORCE_DRAW_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),

        // Group Equb: member-created private groups.
        // Groups go live the moment they are created. Admins review by
        // exception from the panel instead of gating every single group, which
        // otherwise left members stuck on "awaiting approval" with no way to
        // invite anyone. Set EQUB_GROUP_REQUIRES_APPROVAL=true to put the
        // manual gate back.
        'group_requires_approval' => filter_var(env('EQUB_GROUP_REQUIRES_APPROVAL', false), FILTER_VALIDATE_BOOLEAN),
        'group_min_members' => (int) env('EQUB_GROUP_MIN_MEMBERS', 2),
        'group_max_members' => (int) env('EQUB_GROUP_MAX_MEMBERS', 100),
        'invitation_ttl_days' => (int) env('EQUB_INVITATION_TTL_DAYS', 14),

        // "My Responsibility People": how many places one member may hold on
        // behalf of others in a single group. Each one is a full contribution
        // every round, paid by the sponsor alone, so the cap exists to stop
        // someone quietly taking on more than they can carry.
        'max_responsibility_people' => (int) env('EQUB_MAX_RESPONSIBILITY_PEOPLE', 10),
    ],

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'), // Kept for legacy if needed
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),
        'service_account_key' => env('FIREBASE_SERVICE_ACCOUNT_KEY'),
        'service_account_path' => env('FIREBASE_SERVICE_ACCOUNT_PATH', storage_path('app/firebase/service-account.json')),
        'use_http_v1' => env('FIREBASE_USE_HTTP_V1', true),
    ],

];
