# Laravel Cloudflare

[![Latest Version on Packagist](https://img.shields.io/packagist/v/usesorane/laravel-cloudflare.svg?style=flat-square)](https://packagist.org/packages/usesorane/laravel-cloudflare)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/usesorane/laravel-cloudflare/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/usesorane/laravel-cloudflare/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/usesorane/laravel-cloudflare/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/usesorane/laravel-cloudflare/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/laravel-cloudflare.svg?style=flat-square)](https://packagist.org/packages/usesorane/laravel-cloudflare)

Retrieve the current Cloudflare IP ranges, cache them, automatically update them when they change, and access them through a simple service. 

Use the list in your `TrustProxies` middleware to trust all Cloudflare IPs automatically.

## Installation

Install the package via composer:

```bash
composer require usesorane/laravel-cloudflare
```

(Optional) Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-cloudflare-config"
```

This is the content of the config file:

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

    'logging' => [
        // Whether to log a warning when a fetch to Cloudflare endpoints fails
        'failed_fetch' => env('CLOUDFLARE_LOG_FAILED_FETCH', true),
    ],
];
```

## What it does

- Fetches Cloudflare IP ranges from:
	- https://www.cloudflare.com/ips-v4
	- https://www.cloudflare.com/ips-v6
- Caches the lists for 24h by default
- Provides a command to keep the list up-to-date: `php artisan cloudflare:refresh`
- Interact with the lists in your code via the `LaravelCloudflare` service:
    - `ipv4()`: get IPv4 addresses
    - `ipv6()`: get IPv6 addresses
    - `all()`: get all addresses (v4 + v6)
    - `refresh()`: fetch and cache immediately

## Quick usage

The most common use case is to trust Cloudflare proxies in your application.

Run the following command to fetch and cache the IPs initially:

```bash
php artisan cloudflare:refresh
```

To keep the list updated, add the command to your application's scheduler (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cloudflare:refresh')->twiceDaily(); // or ->daily(), ->hourly(), etc.
```

Add the list of IPs to the `TrustProxies` middleware in `bootstrap/app.php`:

```php
use Sorane\LaravelCloudflare\LaravelCloudflare;

->withMiddleware(function (Middleware $middleware) {
    $ips = app(LaravelCloudflare::class)->all();
    // Add any other IPs you want to trust to the $ips array here
    $middleware->trustProxies(at: $ips);
})
```

(Optional) Interact with the service directly:

```php
use Sorane\LaravelCloudflare\LaravelCloudflare;

$cloudflare = app(LaravelCloudflare::class);
$cloudflare->refresh(); // fetch and cache immediately
$v4Ips = $cloudflare->ipv4();
$v6Ips = $cloudflare->ipv6();
$allIps = $cloudflare->all();
```

## Using with Laravel Octane

When you use this package to trust Cloudflare proxies via the `TrustProxies` middleware, while running behind Laravel Octane, keep the following in mind:

The proxy IP list you define in `bootstrap/app.php` is loaded into memory and only updates when the Octane workers restart.

Result: after you run `php artisan cloudflare:refresh`, workers do not immediately see the refreshed IP list.

Usually this is fine because:
- Cloudflare IP ranges rarely change.
- Octane workers restart after serving 500 requests by default.
- Octane workers restart when the application is deployed.

It can be a problem when:
- Your Octane workers do not restart soon enough for your needs (e.g., low traffic, high max requests setting, many workers).
- You want to always have the latest IPs in your Octane workers, no matter what.

If either applies:
- Restart the Octane workers with `php artisan octane:restart` after running `php artisan cloudflare:refresh`.

See the Laravel Octane documentation for more details: https://laravel.com/docs/octane

## License

MIT License. See `LICENSE.md`.
