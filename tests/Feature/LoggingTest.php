<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sorane\LaravelCloudflare\LaravelCloudflare;

it('logs a warning when fetch fails and logging enabled', function (): void {
    Http::fake([
        'www.cloudflare.com/*' => Http::response('', 500),
    ]);

    Log::spy();

    $service = app(LaravelCloudflare::class);
    $service->ipv4();

    Log::shouldHaveReceived('warning')->once();
});

it('does not log a warning when disabled via config', function (): void {
    Config::set('laravel-cloudflare.logging.failed_fetch', false);

    Http::fake([
        'www.cloudflare.com/*' => Http::response('', 500),
    ]);

    Log::spy();

    $service = app(LaravelCloudflare::class);
    $service->ipv6();

    Log::shouldNotHaveReceived('warning');
});
