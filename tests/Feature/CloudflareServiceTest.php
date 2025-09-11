<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Sorane\LaravelCloudflare\LaravelCloudflare;

it('fetches and caches ipv4 and ipv6 lists', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n# comment\n\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response("2606:4700::/32\n# comment", 200),
    ]);

    // Ensure small TTL for test
    Config::set('laravel-cloudflare.cache.ttl', 60);

    $service = app(LaravelCloudflare::class);

    expect($service->ipv4())->toEqual(['1.1.1.1/32', '10.0.0.0/8']);
    expect($service->ipv6())->toEqual(['2606:4700::/32']);
    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);

    // Second call should hit cache (no additional HTTP requests)
    Http::preventStrayRequests();
    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

it('refresh uses fallback when fetch fails', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    $service = app(LaravelCloudflare::class);
    $service->refresh();

    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);

    // Now fail HTTP and call refresh; it should keep existing cached values
    Http::fake([
        'www.cloudflare.com/*' => Http::response('', 500),
    ]);

    $service->refresh();

    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});
