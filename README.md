# Laravel Cloudflare

[![Latest Version on Packagist](https://img.shields.io/packagist/v/usesorane/laravel-cloudflare.svg?style=flat-square)](https://packagist.org/packages/usesorane/laravel-cloudflare)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/usesorane/laravel-cloudflare/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/usesorane/laravel-cloudflare/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/usesorane/laravel-cloudflare/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/usesorane/laravel-cloudflare/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/laravel-cloudflare.svg?style=flat-square)](https://packagist.org/packages/usesorane/laravel-cloudflare)

Fetch and cache all IP addresses of Cloudflare to use in your Laravel application.
Common use case is to trust Cloudflare proxies in `TrustProxies` middleware.

## Installation

You can install the package via composer:

```bash
composer require usesorane/laravel-cloudflare
```

(Optional) You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-cloudflare-config"
```

This is the contents of the published config file:

```php
return [
    'cache' => [
        // Cache store to use (null = default store)
        'store' => env('CLOUDFLARE_CACHE_STORE', null),

        // Cache keys used to store IP ranges
        'keys' => [
            'all' => 'cloudflare:ips',
            'v4' => 'cloudflare:ips:v4',
            'v6' => 'cloudflare:ips:v6',
        ],

        // Time to live in seconds (null = forever)
        'ttl' => env('CLOUDFLARE_CACHE_TTL', 60 * 60 * 24), // 24 hours
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
];
```

## What it does

- Fetches Cloudflare IP ranges from:
	- https://www.cloudflare.com/ips-v4
	- https://www.cloudflare.com/ips-v6
- Caches the lists for 24h by default
- Provides a command to refresh the cache: `php artisan cloudflare:refresh`
- Next, add the IPs to the `TrustProxies` middleware:

## Quick usage

Trust Cloudflare proxies in your `bootstrap/app.php`:

```php
use Sorane\LaravelCloudflare\LaravelCloudflare;

->withMiddleware(function (Middleware $middleware) {
    $ips = app(LaravelCloudflare::class)->all();
    $middleware->trustProxies(at: $ips);
})
```

### Scheduling

Add the command to your application's scheduler (e.g., `app/Console/Kernel.php`):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('cloudflare:refresh')->twiceDaily(); // or ->daily(), ->hourly(), etc.
}
```

Or fetch explicitly via the command:

```bash
php artisan cloudflare:refresh
```

Or use it anywhere in code:

```php
use Sorane\LaravelCloudflare\LaravelCloudflare;

$cloudflare = app(LaravelCloudflare::class);
$v4Ips = $cloudflare->ipv4();
$v6Ips = $cloudflare->ipv6();
$allIps = $cloudflare->all();
```

## Configuration

Key options in `config/laravel-cloudflare.php`:

- `cache.store`: which cache store to use (null uses default)
- `cache.ttl`: seconds to keep the lists (null = forever)
- `schedule.enabled` and `schedule.expression`: package self-scheduling

## Notes

- If Cloudflare is temporarily unreachable, the refresh will keep the last cached values instead of wiping the cache.
- You're free to use these lists however you like; TrustProxies is just a common use case.

## License

MIT License. See `LICENSE.md`.
