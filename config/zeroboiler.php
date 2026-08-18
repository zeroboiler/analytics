<?php    ],

    'watermark' => [
        'enabled' => env('ANALYTICS_WATERMARK_ENABLED', true),
        'ttl' => (int) env('ANALYTICS_WATERMARK_TTL', 3600), // 1 hour
        'log_size' => (int) env('ANALYTICS_WATERMARK_LOG_SIZE', 1000),
        'gap_window' => (int) env('ANALYTICS_WATERMARK_GAP_WINDOW', 500),
        'lag_warning' => (int) env('ANALYTICS_WATERMARK_LAG_WARNING', 50),
        'lag_critical' => (int) env('ANALYTICS_WATERMARK_LAG_CRITICAL', 200),
        'providers' => [
            // Override with specific provider list if needed
            // Default: all 10 providers tracked automatically
        ],
    ],
];
