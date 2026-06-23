<?php

declare(strict_types=1);

namespace PhpOpcua\LaravelOpcua;

use PhpOpcua\LaravelOpcua\Commands\SessionCommand;
use PhpOpcua\LaravelOpcua\Events\LaravelPsr14Dispatcher;
use Illuminate\Support\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
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

        // Provide a default PSR-14 dispatcher so OPC UA events reach Laravel
        // listeners out of the box. Laravel's own dispatcher does not formally
        // implement Psr\EventDispatcher\EventDispatcherInterface, so we adapt
        // it. Only when Laravel's event system is present; `singletonIf` lets
        // an application bind its own implementation first and keep it.
        if ($this->app->bound('events')) {
            $this->app->singletonIf(EventDispatcherInterface::class, static function ($app): EventDispatcherInterface {
                return new LaravelPsr14Dispatcher($app->make('events'));
            });
        }

        $this->app->singleton(OpcuaManager::class, function ($app) {
            $logger = $app->bound(LoggerInterface::class)
                ? $app->make(LoggerInterface::class)
                : null;

            $cache = $app->bound(CacheInterface::class)
                ? $app->make(CacheInterface::class)
                : null;

            $eventDispatcher = $app->bound(EventDispatcherInterface::class)
                ? $app->make(EventDispatcherInterface::class)
                : null;

            $loggerResolver = $app->bound('log')
                ? static function (string $channel) use ($app): ?LoggerInterface {
                    $manager = $app['log'];
                    if (!is_object($manager) || !method_exists($manager, 'channel')) {
                        return null;
                    }
                    $resolved = $manager->channel($channel);
                    return $resolved instanceof LoggerInterface ? $resolved : null;
                }
                : null;

            return new OpcuaManager(
                $app['config']['opcua'],
                $logger,
                $cache,
                $eventDispatcher,
                $loggerResolver,
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
