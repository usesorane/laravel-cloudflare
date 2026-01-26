<?php

namespace Sorane\LaravelCloudflare\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CloudflareAutoFetchAttempted
{
    use Dispatchable;

    /**
     * @param  'all'|'ipv4'|'ipv6'  $type
     */
    public function __construct(
        public string $type,
        public bool $wasRateLimited,
        public bool $wasSuccessful,
    ) {}
}
