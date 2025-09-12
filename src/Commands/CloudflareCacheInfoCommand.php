<?php

namespace Sorane\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Sorane\LaravelCloudflare\LaravelCloudflare;

class CloudflareCacheInfoCommand extends Command
{
    public $signature = 'cloudflare:cache-info';

    public $description = 'Display information about the cached Cloudflare IP ranges.';

    public function handle(): int
    {
        $service = app(LaravelCloudflare::class);

        $info = $service->cacheInfo();

        $this->info('Cloudflare IP Cache Information');
        $this->line('Cache Store      : '.($info['store'] ?? 'default'));
        $ttl = $info['configured_ttl'];
        $this->line('Configured TTL   : '.($ttl === null ? 'forever' : ($ttl.'s')));

        $this->newLine();
        $this->line('Entries:');

        foreach (['v4', 'v6', 'all'] as $segment) {
            $segmentInfo = $info['keys'][$segment];
            $status = $segmentInfo['present'] ? 'cached' : 'missing';
            $line = '  '
                .str_pad($segment, 3, ' ', STR_PAD_RIGHT)
                .' key '
                .str_pad($segmentInfo['key'], 25, ' ', STR_PAD_RIGHT)
                .' status: '
                .str_pad($status, 7, ' ', STR_PAD_RIGHT)
                .' count: '
                .$segmentInfo['count'];
            $this->line($line);
        }

        $this->newLine();
        $this->comment('Use cloudflare:refresh to populate or refresh the cache.');

        return self::SUCCESS;
    }
}
