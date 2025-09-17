# Laravel Cloudflare

[![Latest Version](https://img.shields.io/packagist/v/usesorane/laravel-cloudflare.svg)](https://packagist.org/packages/usesorane/laravel-cloudflare)
[![Tests](https://img.shields.io/github/actions/workflow/status/usesorane/laravel-cloudflare/laravel-package-tests.yml?branch=main&label=tests)](https://github.com/usesorane/laravel-cloudflare/actions/workflows/laravel-package-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/laravel-cloudflare.svg)](https://packagist.org/packages/usesorane/laravel-cloudflare)

Retrieve the current Cloudflare IP ranges, cache them, automatically update them, and access them through a simple service. 

Use the IP list in your `TrustProxies` middleware to trust all Cloudflare IPs automatically.

## Installation

Install the package via composer:

```bash
composer require usesorane/laravel-cloudflare
```

(Optional) Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-cloudflare"
```

Content of the config file:

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

    'diagnostics' => [
        // Enable the diagnostics route (default: false)
        'enabled' => env('CLOUDFLARE_DIAGNOSTICS_ENABLED', false),

        // Path for the diagnostics route
        'path' => env('CLOUDFLARE_DIAGNOSTICS_PATH', '/cloudflare-diagnose'),
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
        $cloudflareIps = app(\Sorane\LaravelCloudflare\LaravelCloudflare::class)->all();
        $ipsToTrust = [
            ...$cloudflareIps,
            // Add any other IPs you want to trust here
        ];
        $middleware->trustProxies(at: $ipsToTrust);
    });
})
```

Note: Use `app()->booted()` to ensure the application is fully booted and the cache is accessible.

4. Use the `cache-info` command to see information about the currently cached IPs.

```bash
php artisan cloudflare:cache-info
```

5. Enable the diagnostics route (optional) by setting `CLOUDFLARE_DIAGNOSTICS_ENABLED=true` in your `.env` file. Then visit `/cloudflare-diagnose` in your app to see how Cloudflare and your server headers are interpreted by Laravel.

6. If the `laravel_ip` in the diagnostics output from step 5 does not show your real client IP, read section [Determine which proxies to trust besides Cloudflare](#determine-which-proxies-to-trust-besides-cloudflare).

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
* last_good – a permanent copy updated only after a successful refresh. It is not cleared on a failed refresh.

Lookup order for `ipv4()`, `ipv6()`, and `all()`:
1. current list
2. last_good list
3. (logs a warning and returns an empty array only if neither exists – typically only before the very first refresh)

Relevant config options (`config/laravel-cloudflare.php`):
* `cache.ttl` – lifetime for current list (seconds, null = forever).
* `cache.allow_stale` – whether to fall back to last_good when current missing.
* Distinct key sets under `cache.keys.current` and `cache.keys.last_good`.

Operational recommendation:
* Run `cloudflare:refresh` in your deployment pipeline and via the scheduler.
* Keep the TTL of last_good infinite (null) to ensure a fallback is always available.
* Regularly check logs and use `cloudflare:cache-info` to monitor cache status.

## Diagnostics route (optional)

You can expose a small diagnostics endpoint to see how Cloudflare and your server headers are interpreted by Laravel.

- Enable it via env/config:
    - `CLOUDFLARE_DIAGNOSTICS_ENABLED=true`
    - Optional custom path: `CLOUDFLARE_DIAGNOSTICS_PATH=/cloudflare-diagnose` (default is `/cloudflare-diagnose`)
- When enabled, a GET endpoint is registered at the configured path and returns JSON like:

```json
{
    "laravel_ip": "203.0.113.5",
    "remote_addr": "172.16.0.10",
    "x_forwarded_for": "203.0.113.5, 172.16.0.10",
    "cf_connecting_ip": "203.0.113.5",
    "true_client_ip": "203.0.113.5",
    "server_https": "on",
    "is_secure": true
}
```

How to interpret:
- `laravel_ip`: The client IP as seen by Laravel after processing trusted proxies (i.e., the effective client IP).
- `remote_addr`: The direct connection IP (usually your load balancer or Cloudflare).
- `x_forwarded_for`: The full X-Forwarded-For header (may contain multiple IPs).
- `cf_connecting_ip`: The Cloudflare-specific header containing the original client IP (if present).
- `true_client_ip`: The True-Client-IP header (if present).
- `server_https`: The raw HTTPS server variable.
- `is_secure`: Whether Laravel considers the request secure (HTTPS).

If setup correctly, `laravel_ip` should match the actual client IP instead of a Cloudflare IP.

## Why trusting proxies is important

Most production Laravel apps sit behind one or more proxies (CDNs, load balancers, etc.). Those proxies terminate TLS and forward the request to your app, typically attaching standard forwarding headers such as X-Forwarded-For/Proto/Host/Port.

Laravel will only use these headers if the request comes from a proxy you have explicitly trusted. Otherwise, Laravel ignores the headers (to prevent spoofing) and falls back to the direct connection details (REMOTE_ADDR, plain HTTP scheme, internal host/port).

When proxies are not trusted, several things can go wrong:

- Client IP is incorrect
    - `Request::ip()` shows the proxy or 127.0.0.1 instead of the real client.
    - Side effects: rate limiting and abuse protection over/under throttle, allow/deny lists misfire, audit logs and analytics record the wrong IP.

- HTTPS awareness is lost
    - `Request::isSecure()` may be false even when the original request was HTTPS.
    - Side effects: generated links use `http://` (mixed content), “force HTTPS” logic misbehaves, and cookies that require the `Secure` flag (e.g., SameSite=None) may be dropped by browsers, impacting auth / sessions.

- Host and port are wrong
    - Generated URLs (redirects, emails, pagination), signed URLs, and callback URLs may be invalid because they use internal host/port instead of the public ones.
    - Domain / subdomain routing or multi-tenant routing based on host can mis-route.

Trusting your proxies tells Laravel which upstream IPs/CIDRs are allowed to supply forwarding headers and which header set to honor. Then, Laravel normalizes the request's effective IP, scheme, host, and port. Thereby mitigating the above issues.

For security, avoid trusting all proxies unless your app is only reachable through a trusted network perimeter. Trusting the wrong IPs lets attackers spoof forwarding headers.

## Determine which proxies to trust besides Cloudflare

In addition to Cloudflare IPs, it's sometimes necessary to trust other proxies that forward traffic to your app.

- Receiving traffic via Cloudflare? Include the Cloudflare IP ranges from this package.
- Running a local web server in front of your app (e.g., Nginx → Octane)? Also include the local upstreams, commonly `127.0.0.1` and `::1`.
- Using a load balancer or ingress? Include its IP/CIDR (or the local web server that fronts your app).

Quick check: enable `CLOUDFLARE_DIAGNOSTICS_ENABLED=true` and visit `/cloudflare-diagnose`. If `laravel_ip` shows your real client IP and `is_secure` is true for HTTPS, you're set.

Security tip: trust only the proxies that truly forward traffic to your app; avoid `'*'` on public apps.

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
