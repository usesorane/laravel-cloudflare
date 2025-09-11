<?php

namespace Sorane\LaravelCloudflare;

use Illuminate\Support\ServiceProvider;
use Sorane\LaravelCloudflare\Commands\LaravelCloudflareCommand;

class LaravelCloudflareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-cloudflare.php', 'laravel-cloudflare');

        // Bind the main service
        $this->app->singleton(LaravelCloudflare::class, static function (): LaravelCloudflare {
            return new LaravelCloudflare;
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/laravel-cloudflare.php' => config_path('laravel-cloudflare.php'),
        ], 'config');

        // Register the command
        if ($this->app->runningInConsole()) {
            $this->commands([
                LaravelCloudflareCommand::class,
            ]);
        }
    }
}
