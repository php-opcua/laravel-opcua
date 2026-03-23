<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel;

use Gianfriaur\OpcuaLaravel\Commands\SessionCommand;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Registers OPC UA services into the Laravel container.
 */
class OpcuaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opcua.php', 'opcua');

        $this->app->singleton(OpcuaManager::class, function ($app) {
            $logger = $app->bound(LoggerInterface::class)
                ? $app->make(LoggerInterface::class)
                : null;

            $cache = $app->bound(CacheInterface::class)
                ? $app->make(CacheInterface::class)
                : null;

            return new OpcuaManager(
                $app['config']['opcua'],
                $logger,
                $cache,
            );
        });

        $this->app->alias(OpcuaManager::class, 'opcua');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/opcua.php' => config_path('opcua.php'),
            ], 'opcua-config');

            $this->commands([
                SessionCommand::class,
            ]);
        }
    }
}
