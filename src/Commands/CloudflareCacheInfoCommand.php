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
        $this->line('Segments:');

        foreach (['current', 'last_good'] as $group) {
            if (! isset($info['segments'][$group])) {
                continue;
            }
            $this->line("  {$group}:");
            foreach (['v4', 'v6', 'all'] as $label) {
                $segmentInfo = $info['segments'][$group][$label] ?? null;
                if ($segmentInfo === null) {
                    continue;
                }
                $status = $segmentInfo['present'] ? 'cached' : 'missing';
                $line = '    '
                    .str_pad($label, 3, ' ', STR_PAD_RIGHT)
                    .' key '
                    .str_pad($segmentInfo['key'], 30, ' ', STR_PAD_RIGHT)
                    .' status: '
                    .str_pad($status, 7, ' ', STR_PAD_RIGHT)
                    .' count: '
                    .$segmentInfo['count'];
                $this->line($line);
            }
            $this->newLine();
        }

        $this->newLine();
        $this->comment('Use cloudflare:refresh to populate or refresh the current cache (last_good updates on successful refresh).');

        return self::SUCCESS;
    }
}
