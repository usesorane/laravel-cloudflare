<?php

return [
    'cache' => [
        // Cache store to use (null = default store)
        'store' => env('CLOUDFLARE_CACHE_STORE', null),

        // Primary cache keys ("current" – refreshed list) and fallback ("last_good" – permanent)
        'keys' => [
            'current' => [
                'all' => 'cloudflare:ips:current',
                'v4' => 'cloudflare:ips:v4:current',
                'v6' => 'cloudflare:ips:v6:current',
            ],
            'last_good' => [
                'all' => 'cloudflare:ips:last_good',
                'v4' => 'cloudflare:ips:v4:last_good',
                'v6' => 'cloudflare:ips:v6:last_good',
            ],
        ],

        // Time to live in seconds for the "current" list (null = forever). Default: 7 days.
        'ttl' => env('CLOUDFLARE_CACHE_TTL', 60 * 60 * 24 * 7),

        // Allow falling back to the last known good list when current is missing/expired.
        'allow_stale' => env('CLOUDFLARE_ALLOW_STALE', true),

        // Throw exception when cache is empty (both current and last_good) instead of returning empty array
        'throw_on_empty' => env('CLOUDFLARE_THROW_ON_EMPTY', false),
    ],

    // HTTP client settings for fetching IP ranges from Cloudflare
    'http' => [
        'timeout' => env('CLOUDFLARE_HTTP_TIMEOUT', 10), // seconds
        // [attempts, sleepMilliseconds]
        'retry' => [env('CLOUDFLARE_HTTP_RETRY_ATTEMPTS', 3), env('CLOUDFLARE_HTTP_RETRY_SLEEP', 200)],
        'user_agent' => env('CLOUDFLARE_HTTP_USER_AGENT', 'Laravel-Cloudflare-IP-Fetcher/1.0 (+https://github.com/usesorane/laravel-cloudflare)'),
        'endpoints' => [
            'ipv4' => 'https://www.cloudflare.com/ips-v4',
            'ipv6' => 'https://www.cloudflare.com/ips-v6',
        ],
    ],

    'logging' => [
        // Whether to log a warning when a fetch to Cloudflare endpoints fails
        'failed_fetch' => env('CLOUDFLARE_LOG_FAILED_FETCH', true),
    ],

    'auto_fetch' => [
        // Enable automatic fetching when cache is empty (both current and last_good)
        'enabled' => env('CLOUDFLARE_AUTO_FETCH_ENABLED', true),

        // Minimum interval between auto-fetch attempts (seconds)
        // Prevents hammering Cloudflare if their endpoints are unreachable
        'rate_limit' => env('CLOUDFLARE_AUTO_FETCH_RATE_LIMIT', 600), // 10 minutes

        // Cache key for tracking last auto-fetch attempt
        'rate_limit_key' => 'cloudflare:autofetch:last_attempt',
    ],

    'diagnostics' => [
        // Enable the diagnostics route (default: false)
        'enabled' => env('CLOUDFLARE_DIAGNOSTICS_ENABLED', false),

        // Path for the diagnostics route
        'path' => env('CLOUDFLARE_DIAGNOSTICS_PATH', '/cloudflare-diagnose'),

        // Middleware to apply to the diagnostics route (e.g., ['auth', 'can:view-diagnostics'])
        // IMPORTANT: For security, add authentication middleware in production
        'middleware' => ['web'],
    ],
];
