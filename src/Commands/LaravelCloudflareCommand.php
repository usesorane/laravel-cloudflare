<?php

namespace Sorane\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Sorane\LaravelCloudflare\LaravelCloudflare;

class LaravelCloudflareCommand extends Command
{
    public $signature = 'cloudflare:refresh';

    public $description = 'Fetch and cache the latest Cloudflare IPv4/IPv6 ranges.';

    public function handle(): int
    {
        $service = app(LaravelCloudflare::class);

        $this->info('Refreshing Cloudflare IP ranges...');
        $service->refresh();

        $v4 = $service->ipv4();
        $v6 = $service->ipv6();
        $all = $service->all();

        $this->line('IPv4 (current or fallback): '.count($v4).', IPv6 (current or fallback): '.count($v6).', All (current or fallback): '.count($all));
        $this->comment('Cloudflare IP ranges refreshed (current + last_good updated on success).');

        return self::SUCCESS;
    }
}
