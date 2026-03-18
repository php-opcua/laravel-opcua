<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel;

use Gianfriaur\OpcuaLaravel\Commands\SessionCommand;
use Gianfriaur\OpcuaLaravel\Commands\SubscribeCommand;
use Illuminate\Support\ServiceProvider;

class OpcuaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opcua.php', 'opcua');

        $this->app->singleton(OpcuaManager::class, function ($app) {
            return new OpcuaManager($app['config']['opcua']);
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
                SubscribeCommand::class,
            ]);
        }
    }
}
