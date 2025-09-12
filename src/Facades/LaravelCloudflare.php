<?php

namespace Sorane\LaravelCloudflare\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sorane\LaravelCloudflare\LaravelCloudflare
 *
 * @method static array all()
 * @method static array ipv4()
 * @method static array ipv6()
 * @method static void refresh()
 * @method static array cacheInfo()
 */
class LaravelCloudflare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sorane\LaravelCloudflare\LaravelCloudflare::class;
    }
}
