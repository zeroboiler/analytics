<?php

declare(strict_types=1);

return [
    'analytics' => [
        'ga4' => [
            'enabled' => env('ANALYTICS_GA4_ENABLED', false),
            'measurement_id' => env('ANALYTICS_GA4_MEASUREMENT_ID', ''),
            'api_secret' => env('ANALYTICS_GA4_API_SECRET', ''),
        ],
        'gtm' => [
            'enabled' => env('ANALYTICS_GTM_ENABLED', false),
            'container_id' => env('ANALYTICS_GTM_CONTAINER_ID', ''),
        ],
        'meta_pixel' => [
            'enabled' => env('ANALYTICS_META_PIXEL_ENABLED', false),
            'id' => env('ANALYTICS_META_PIXEL_ID', ''),
            'access_token' => env('ANALYTICS_META_PIXEL_ACCESS_TOKEN', ''),
        ],

        /*
        |--------------------------------------------------------------------------
        | Consent Mode (GDPR)
        |--------------------------------------------------------------------------
        |
        | Default consent state applied to all trackers on initialization.
        | Set to 'denied' for GDPR-safe defaults (users must explicitly opt-in).
        | Options: 'granted', 'denied'
        |
        */
        'consent' => [
            'default' => env('ANALYTICS_CONSENT_DEFAULT', 'granted'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Auto-Track (Server-Side Event Tracking)
        |--------------------------------------------------------------------------
        |
        | Automatically track Laravel framework events as analytics events.
        | Toggle individual events on/off. Set 'enabled' to false to disable
        | all server-side auto-tracking.
        |
        */
        'auto_track' => [
            'enabled' => env('ANALYTICS_AUTO_TRACK_ENABLED', true),
            'events' => [
                'auth.login' => true,
                'auth.register' => true,
                'auth.logout' => false,
                'subscription.created' => true,
                'subscription.upgraded' => true,
                'subscription.cancelled' => true,
                'trial.started' => true,
                'trial.ended' => false,
                'feature.used' => false,
            ],
            'models' => [
                // Track Eloquent model events as analytics events
                // Example: App\Models\Habit::class => ['created', 'deleted'],
            ],
        ],
    ],
];
