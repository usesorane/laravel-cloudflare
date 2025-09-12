<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('shows empty cache info when not populated', function (): void {
    $exitCode = Artisan::call('cloudflare:cache-info');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Cloudflare IP Cache Information')
        ->and($output)->toContain('status: missing');
});

it('shows populated cache info after refresh', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    Artisan::call('cloudflare:refresh');

    $exitCode = Artisan::call('cloudflare:cache-info');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('status: cached')
        ->and($output)->toContain('count: 2') // v4 count
        ->and($output)->toContain('count: 1'); // v6 count
});
