<?php

namespace Sorane\LaravelCloudflare;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

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
     * Get all Cloudflare IP ranges (both IPv4 and IPv6), using cache.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $key = Config::get('laravel-cloudflare.cache.keys.all', 'cloudflare:ips');

        return $this->remember($key, function (): array {
            return array_values(array_unique(array_merge(
                $this->ipv4(),
                $this->ipv6(),
            )));
        });
    }

    /**
     * Get IPv4 ranges using cache.
     *
     * @return array<int, string>
     */
    public function ipv4(): array
    {
        $key = Config::get('laravel-cloudflare.cache.keys.v4', 'cloudflare:ips:v4');

        return $this->remember($key, function (): array {
            return $this->fetchFromEndpoint('ipv4');
        });
    }

    /**
     * Get IPv6 ranges using cache.
     *
     * @return array<int, string>
     */
    public function ipv6(): array
    {
        $key = Config::get('laravel-cloudflare.cache.keys.v6', 'cloudflare:ips:v6');

        return $this->remember($key, function (): array {
            return $this->fetchFromEndpoint('ipv6');
        });
    }

    /**
     * Force refresh of all cached entries by fetching from Cloudflare again.
     */
    public function refresh(): void
    {
        $ttl = Config::get('laravel-cloudflare.cache.ttl');
        $keys = Config::get('laravel-cloudflare.cache.keys', []);

        $keyV4 = $keys['v4'] ?? 'cloudflare:ips:v4';
        $keyV6 = $keys['v6'] ?? 'cloudflare:ips:v6';
        $keyAll = $keys['all'] ?? 'cloudflare:ips';

        $newV4 = $this->fetchFromEndpoint('ipv4');
        $newV6 = $this->fetchFromEndpoint('ipv6');

        // Fallback to existing cached values if fetch failed (empty)
        $currentV4 = $this->cache->get($keyV4, []);
        $currentV6 = $this->cache->get($keyV6, []);

        $finalV4 = $newV4 !== [] ? $newV4 : (is_array($currentV4) ? $currentV4 : []);
        $finalV6 = $newV6 !== [] ? $newV6 : (is_array($currentV6) ? $currentV6 : []);

        $this->put($keyV4, $finalV4, $ttl);
        $this->put($keyV6, $finalV6, $ttl);
        $this->put($keyAll, array_values(array_unique(array_merge($finalV4, $finalV6))), $ttl);
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

        $response = Http::withHeaders([
            'User-Agent' => (string) $userAgent,
        ])->timeout($timeout)
            ->retry((int) Arr::get($retry, 0, 3), (int) Arr::get($retry, 1, 200))
            ->get($endpoint);

        if (! $response->successful()) {
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
     * Helper to remember values in cache based on configured TTL.
     *
     * @param  Closure(): array<int, string>  $resolver
     * @return array<int, string>
     */
    protected function remember(string $key, Closure $resolver): array
    {
        $ttl = Config::get('laravel-cloudflare.cache.ttl');

        if ($ttl === null) {
            return $this->cache->rememberForever($key, $resolver);
        }

        return $this->cache->remember($key, (int) $ttl, $resolver);
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
}
