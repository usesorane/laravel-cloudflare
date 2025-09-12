<?php

namespace Sorane\LaravelCloudflare;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

class LaravelCloudflare
{
    public function __construct(
        public ?CacheContract $cache = null,
    ) {
        // Use configured cache store or default
        $store = Config::get('laravel-cloudflare.cache.store');
        if ($cache === null) {
            $this->cache = $store ? Cache::store($store) : Cache::store();
        }
    }

    /**
     * Get all Cloudflare IP ranges (both IPv4 and IPv6) from cache only.
     * Order: current -> last_good -> [] (logs warning if empty and allowed).
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.all', 'cloudflare:ips:current');
        $lastGoodKey = Arr::get($keys, 'last_good.all', 'cloudflare:ips:last_good');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $current;
        }

        $fallback = $this->fallbackList($lastGoodKey, 'all');
        if ($fallback !== []) {
            return $fallback;
        }

        $this->logEmptyOnce('all');

        return [];
    }

    /**
     * Get IPv4 ranges from cache (current -> last_good -> []).
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    public function ipv4(): array
    {
        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current');
        $lastGoodKey = Arr::get($keys, 'last_good.v4', 'cloudflare:ips:v4:last_good');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $current;
        }

        $fallback = $this->fallbackList($lastGoodKey, 'ipv4');
        if ($fallback !== []) {
            return $fallback;
        }

        $this->logEmptyOnce('ipv4');

        return [];
    }

    /**
     * Get IPv6 ranges from cache (current -> last_good -> []).
     *
     * @return array<int, string>
     */
    public function ipv6(): array
    {
        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current');
        $lastGoodKey = Arr::get($keys, 'last_good.v6', 'cloudflare:ips:v6:last_good');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $current;
        }

        $fallback = $this->fallbackList($lastGoodKey, 'ipv6');
        if ($fallback !== []) {
            return $fallback;
        }

        $this->logEmptyOnce('ipv6');

        return [];
    }

    /**
     * Force refresh: fetch new lists and write to current + last_good only if successful.
     */
    public function refresh(): void
    {
        $keys = Config::get('laravel-cloudflare.cache.keys');
        $ttl = Config::get('laravel-cloudflare.cache.ttl');

        $currentV4Key = Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current');
        $currentV6Key = Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current');
        $currentAllKey = Arr::get($keys, 'current.all', 'cloudflare:ips:current');

        $lastGoodV4Key = Arr::get($keys, 'last_good.v4', 'cloudflare:ips:v4:last_good');
        $lastGoodV6Key = Arr::get($keys, 'last_good.v6', 'cloudflare:ips:v6:last_good');
        $lastGoodAllKey = Arr::get($keys, 'last_good.all', 'cloudflare:ips:last_good');

        $newV4 = $this->fetchFromEndpoint('ipv4');
        $newV6 = $this->fetchFromEndpoint('ipv6');

        if ($newV4 === [] || $newV6 === []) {
            if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                Log::warning('laravel-cloudflare: refresh aborted due to empty fetch', [
                    'ipv4_empty' => $newV4 === [],
                    'ipv6_empty' => $newV6 === [],
                ]);
            }

            return;
        }

        $merged = array_values(array_unique(array_merge($newV4, $newV6)));

        // Write current with TTL
        $this->put($currentV4Key, $newV4, $ttl);
        $this->put($currentV6Key, $newV6, $ttl);
        $this->put($currentAllKey, $merged, $ttl);

        // Always update last_good (forever)
        $this->cache->forever($lastGoodV4Key, $newV4);
        $this->cache->forever($lastGoodV6Key, $newV6);
        $this->cache->forever($lastGoodAllKey, $merged);
    }

    /**
     * Fetch and parse the given endpoint type.
     *
     * @param  'ipv4'|'ipv6'  $type
     * @return array<int, string>
     */
    protected function fetchFromEndpoint(string $type): array
    {
        $endpoint = Config::get("laravel-cloudflare.http.endpoints.$type");
        if (! is_string($endpoint) || $endpoint === '') {
            return [];
        }

        $timeout = (int) Config::get('laravel-cloudflare.http.timeout', 10);
        $retry = Config::get('laravel-cloudflare.http.retry', [3, 200]);
        $userAgent = Config::get('laravel-cloudflare.http.user_agent', 'usesorane/laravel-cloudflare');

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $userAgent,
            ])->timeout($timeout)
                ->retry((int) Arr::get($retry, 0, 3), (int) Arr::get($retry, 1, 200))
                ->get($endpoint);

            if (! $response->successful()) {
                if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                    Log::warning('laravel-cloudflare: failed to fetch IP ranges', [
                        'type' => $type,
                        'endpoint' => $endpoint,
                        'status' => $response->status(),
                    ]);
                }

                return [];
            }
        } catch (Throwable $e) {
            if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                Log::warning('laravel-cloudflare: exception while fetching IP ranges', [
                    'type' => $type,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }

        $lines = preg_split('/\r?\n/', trim($response->body())) ?: [];

        // Filter comments and blank lines
        $ips = array_values(array_filter(array_map(static function (string $line): string {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                return '';
            }

            return $line;
        }, $lines), static fn (string $v): bool => $v !== ''));

        return $ips;
    }

    /**
     * Helper to put arrays in cache with configured TTL.
     *
     * @param  array<int, string>  $value
     */
    protected function put(string $key, array $value, ?int $ttl): void
    {
        if ($ttl === null) {
            $this->cache->forever($key, $value);

            return;
        }

        $this->cache->put($key, $value, $ttl);
    }

    /**
     * Provide introspection details about current cache state without triggering network fetches.
     */
    public function cacheInfo(): array
    {
        $configKeys = Config::get('laravel-cloudflare.cache.keys', []);

        $segments = [
            'current' => [
                'v4' => Arr::get($configKeys, 'current.v4', 'cloudflare:ips:v4:current'),
                'v6' => Arr::get($configKeys, 'current.v6', 'cloudflare:ips:v6:current'),
                'all' => Arr::get($configKeys, 'current.all', 'cloudflare:ips:current'),
            ],
            'last_good' => [
                'v4' => Arr::get($configKeys, 'last_good.v4', 'cloudflare:ips:v4:last_good'),
                'v6' => Arr::get($configKeys, 'last_good.v6', 'cloudflare:ips:v6:last_good'),
                'all' => Arr::get($configKeys, 'last_good.all', 'cloudflare:ips:last_good'),
            ],
        ];

        $details = [];
        foreach ($segments as $segmentName => $keys) {
            foreach ($keys as $label => $key) {
                $present = $this->cache->has($key);
                $count = 0;
                if ($present) {
                    $value = $this->cache->get($key);
                    if (is_array($value)) {
                        $count = count($value);
                    }
                }
                $details[$segmentName][$label] = [
                    'key' => $key,
                    'present' => $present,
                    'count' => $count,
                ];
            }
        }

        return [
            'store' => Config::get('laravel-cloudflare.cache.store'),
            'configured_ttl' => Config::get('laravel-cloudflare.cache.ttl'),
            'allow_stale' => Config::get('laravel-cloudflare.cache.allow_stale'),
            'segments' => $details,
        ];
    }

    /**
     * Attempt to get a fallback list from last_good respecting allow_stale.
     *
     * @return array<int,string>
     *
     * @throws InvalidArgumentException
     */
    protected function fallbackList(string $key, string $label): array
    {
        if (! Config::get('laravel-cloudflare.cache.allow_stale', true)) {
            return [];
        }

        $value = $this->cache->get($key);
        if (! is_array($value)) {
            return [];
        }

        // Presence alone is considered acceptable; no age enforcement.
        return $value;
    }

    /**
     * Log a warning only once per request for an empty list situation.
     */
    protected function logEmptyOnce(string $type): void
    {
        static $logged = [];
        if (isset($logged[$type])) {
            return;
        }
        $logged[$type] = true;
        Log::warning('laravel-cloudflare: no Cloudflare IP list available for '.$type.' (both current and last_good empty)');
    }
}
