# Laravel Cloudflare

[![Latest Version](https://img.shields.io/packagist/v/usesorane/laravel-cloudflare.svg)](https://packagist.org/packages/usesorane/laravel-cloudflare)
[![Tests](https://img.shields.io/github/actions/workflow/status/usesorane/laravel-cloudflare/laravel-package-tests.yml?branch=main&label=tests)](https://github.com/usesorane/laravel-cloudflare/actions/workflows/laravel-package-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/laravel-cloudflare.svg)](https://packagist.org/packages/usesorane/laravel-cloudflare)

Retrieve the current Cloudflare IP ranges, cache them, automatically update them when they change, and access them through a simple service. 

Use the list in your `TrustProxies` middleware to trust all Cloudflare IPs automatically.

## Installation

Install the package via composer:

```bash
composer require usesorane/laravel-cloudflare
```

(Optional) Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-cloudflare"
```

This is the content of the config file:

```php
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
- Caches the lists (default 7 days) and keeps a permanent fallback copy
- Provides a command to keep the list up-to-date: `php artisan cloudflare:refresh`
- Interact with the lists in your code via the `LaravelCloudflare` service:
    - `ipv4()`: get IPv4 addresses
    - `ipv6()`: get IPv6 addresses
    - `all()`: get all addresses (v4 + v6)
    - `refresh()`: fetch and cache immediately
    - `cacheInfo()`: get info about the cached lists

## Quick usage

The most common use case is to trust Cloudflare proxies in your application.

1. Run the following command to fetch and cache the IPs initially:

```bash
php artisan cloudflare:refresh
```

2. Register the refresh command to your application's scheduler (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cloudflare:refresh')->twiceDaily(); // or ->daily(), ->hourly(), etc.
```

3. Trust Cloudflare proxies in `bootstrap/app.php`:

```php
use Sorane\LaravelCloudflare\LaravelCloudflare;

->withMiddleware(function (Middleware $middleware) {
    // Your other middleware interactions here...

    app()->booted(function () use ($middleware) {
        $cloudflareIps = app(LaravelCloudflare::class)->all();
        $ipsToTrust = [
            ...$cloudflareIps,
            // Add any other proxies you want to trust here
        ];
        $middleware->trustProxies(at: $ipsToTrust);
    });
})
```

Note: Use `app()->booted()` to ensure the application is fully booted and the cache is accessible.

Note 2: The `all()` method can never return an empty array, if you've at least once successfully run `cloudflare:refresh`. Read more about the caching design below.

4. Use the `cache-info` command to see information about the currently cached IPs.

```bash
php artisan cloudflare:cache-info
```

## The LaravelCloudflare service

```php
use Sorane\LaravelCloudflare\LaravelCloudflare;

$cloudflare = app(LaravelCloudflare::class);
$cloudflare->refresh(); // fetch and cache immediately
$v4Ips = $cloudflare->ipv4();
$v6Ips = $cloudflare->ipv6();
$allIps = $cloudflare->all();
$cacheInfo = $cloudflare->cacheInfo();
```

## Caching design (current + last_good)

To avoid network calls during request handling and still remain resilient if Cloudflare is temporarily unreachable, the package maintains two cache layers:

* current – the actively refreshed list with a configurable TTL (default 7 days).
* last_good – a permanent (forever) copy updated only after a fully successful refresh (both IPv4 and IPv6 lists fetched). It is never cleared on a failed refresh.

Lookup order for `ipv4()`, `ipv6()`, and `all()`:
1. current list
2. last_good list (when `allow_stale` is true)
3. (logs a warning and returns an empty array only if neither exists – typically only before the very first refresh)

Advantages:
* No request latency spikes from on-demand fetching.
* Transient network failures do not drop trusted proxy IPs – the last_good list continues to be served.
* Safe refresh semantics: last_good updates only after a fully successful fetch of both families.

Relevant config options (`config/laravel-cloudflare.php`):
* `cache.ttl` – lifetime for current list (seconds, null = forever).
* `cache.allow_stale` – whether to fall back to last_good when current missing.
* Distinct key sets under `cache.keys.current` and `cache.keys.last_good`.

Operational recommendation:
* Run `cloudflare:refresh` in your deployment pipeline and via the scheduler. A single success seeds both caching layers.
* Keep the TTL of last_good infinite (null) to ensure a fallback is always available.
* Regularly check logs and use `cloudflare:cache-info` to monitor cache status.

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

Licensed under the MIT License. See `LICENSE.md`.
