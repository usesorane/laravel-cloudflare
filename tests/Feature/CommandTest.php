<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('runs the cloudflare:refresh command and shows counts', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    $result = Artisan::call('cloudflare:refresh');

    expect($result)->toBe(0);
});
