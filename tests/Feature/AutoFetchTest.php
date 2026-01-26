<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Sorane\LaravelCloudflare\Events\CloudflareAutoFetchAttempted;
use Sorane\LaravelCloudflare\Events\CloudflareIpsRefreshed;
use Sorane\LaravelCloudflare\LaravelCloudflare;

function fakeCloudflareSuccess(): void
{
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);
}

it('auto-fetches when cache is empty', function (): void {
    fakeCloudflareSuccess();
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);

    $service = app(LaravelCloudflare::class);
    $ips = $service->all();

    expect($ips)->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

it('dispatches CloudflareAutoFetchAttempted event on auto-fetch', function (): void {
    fakeCloudflareSuccess();
    Event::fake([CloudflareAutoFetchAttempted::class, CloudflareIpsRefreshed::class]);
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);

    $service = app(LaravelCloudflare::class);
    $service->all();

    Event::assertDispatched(CloudflareAutoFetchAttempted::class, function ($event): bool {
        return $event->type === 'all'
            && $event->wasRateLimited === false
            && $event->wasSuccessful === true;
    });
});

it('rate-limits auto-fetch attempts', function (): void {
    fakeCloudflareSuccess();
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);
    Config::set('laravel-cloudflare.auto_fetch.rate_limit', 600);

    $service = app(LaravelCloudflare::class);
    $service->all();

    // Get fresh instance to clear memoization
    app()->forgetInstance(LaravelCloudflare::class);

    // Clear all IP caches but keep rate limit key
    $keys = Config::get('laravel-cloudflare.cache.keys');
    foreach ($keys['current'] as $key) {
        Cache::forget($key);
    }
    foreach ($keys['last_good'] as $key) {
        Cache::forget($key);
    }

    Event::fake([CloudflareAutoFetchAttempted::class]);

    $service2 = app(LaravelCloudflare::class);
    $service2->all();

    Event::assertDispatched(CloudflareAutoFetchAttempted::class, function ($event): bool {
        return $event->wasRateLimited === true;
    });
});

it('does not auto-fetch when disabled', function (): void {
    Event::fake([CloudflareAutoFetchAttempted::class]);
    Config::set('laravel-cloudflare.auto_fetch.enabled', false);

    $service = app(LaravelCloudflare::class);
    $service->all();

    Event::assertNotDispatched(CloudflareAutoFetchAttempted::class);
});

it('does not auto-fetch when cache has data', function (): void {
    fakeCloudflareSuccess();
    $service = app(LaravelCloudflare::class);
    $service->refresh();

    Event::fake([CloudflareAutoFetchAttempted::class]);

    // Get fresh instance to clear memoization
    app()->forgetInstance(LaravelCloudflare::class);

    $service2 = app(LaravelCloudflare::class);
    $service2->all();

    Event::assertNotDispatched(CloudflareAutoFetchAttempted::class);
});

it('returns empty array when auto-fetch fails', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response('', 500),
        'www.cloudflare.com/ips-v6' => Http::response('', 500),
    ]);

    Config::set('laravel-cloudflare.auto_fetch.enabled', true);
    Config::set('laravel-cloudflare.cache.throw_on_empty', false);
    Config::set('laravel-cloudflare.logging.failed_fetch', false);

    $service = app(LaravelCloudflare::class);
    $ips = $service->all();

    expect($ips)->toEqual([]);
});

it('auto-fetches for ipv4 method when cache is empty', function (): void {
    fakeCloudflareSuccess();
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);

    $service = app(LaravelCloudflare::class);
    $ips = $service->ipv4();

    expect($ips)->toEqual(['1.1.1.1/32', '10.0.0.0/8']);
});

it('auto-fetches for ipv6 method when cache is empty', function (): void {
    fakeCloudflareSuccess();
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);

    $service = app(LaravelCloudflare::class);
    $ips = $service->ipv6();

    expect($ips)->toEqual(['2606:4700::/32']);
});

it('shows auto_fetch status in cacheInfo', function (): void {
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);
    Config::set('laravel-cloudflare.auto_fetch.rate_limit', 600);

    $service = app(LaravelCloudflare::class);
    $info = $service->cacheInfo();

    expect($info)->toHaveKey('auto_fetch');
    expect($info['auto_fetch'])->toEqual([
        'enabled' => true,
        'rate_limit' => 600,
        'currently_rate_limited' => false,
    ]);
});

it('shows rate_limited status in cacheInfo after auto-fetch', function (): void {
    fakeCloudflareSuccess();
    Config::set('laravel-cloudflare.auto_fetch.enabled', true);

    $service = app(LaravelCloudflare::class);
    $service->all(); // triggers auto-fetch

    $info = $service->cacheInfo();

    expect($info['auto_fetch']['currently_rate_limited'])->toBeTrue();
});
