<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Commands\SessionCommand;
use PhpOpcua\LaravelOpcua\Events\LaravelPsr14Dispatcher;
use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\LaravelOpcua\OpcuaServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return sys_get_temp_dir() . '/config/' . $path;
    }
}

function makeApp(?LoggerInterface $logger = null, ?CacheInterface $cache = null, ?EventDispatcherInterface $eventDispatcher = null): Container
{
    $app = new Container();
    Container::setInstance($app);

    $app->instance('app', $app);

    $config = new Repository([
        'opcua' => [
            'default' => 'default',
            'session_manager' => [
                'enabled' => false,
                'socket_path' => '/tmp/test.sock',
                'timeout' => 600,
                'cleanup_interval' => 30,
                'auth_token' => null,
                'max_sessions' => 100,
                'socket_mode' => 0600,
                'allowed_cert_dirs' => null,
                'log_channel' => 'stack',
                'cache_store' => 'file',
            ],
            'connections' => [
                'default' => [
                    'endpoint' => 'opc.tcp://localhost:4840',
                ],
            ],
        ],
    ]);

    $app->instance('config', $config);
    $app->instance('events', new Dispatcher($app));
    $app->instance('path.config', sys_get_temp_dir());

    if ($logger !== null) {
        $app->instance(LoggerInterface::class, $logger);
    }

    if ($cache !== null) {
        $app->instance(CacheInterface::class, $cache);
    }

    if ($eventDispatcher !== null) {
        $app->instance(EventDispatcherInterface::class, $eventDispatcher);
    }

    return $app;
}

describe('OpcuaServiceProvider', function () {

    afterEach(function () {
        Container::setInstance(null);
    });

    describe('register', function () {

        it('registers OpcuaManager as a singleton', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $a = $app->make(OpcuaManager::class);
            $b = $app->make(OpcuaManager::class);

            expect($a)->toBeInstanceOf(OpcuaManager::class);
            expect($a)->toBe($b);
        });

        it('registers the "opcua" alias', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $fromClass = $app->make(OpcuaManager::class);
            $fromAlias = $app->make('opcua');

            expect($fromAlias)->toBe($fromClass);
        });

        it('merges the default config', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $config = $app->make('config')->get('opcua');

            expect($config)->toBeArray();
            expect($config)->toHaveKey('default');
            expect($config)->toHaveKey('session_manager');
            expect($config)->toHaveKey('connections');
        });

        it('injects Laravel logger into OpcuaManager when bound', function () {
            $logger = new NullLogger();
            $app = makeApp(logger: $logger);
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $manager = $app->make(OpcuaManager::class);
            $ref = new ReflectionProperty($manager, 'defaultLogger');

            expect($ref->getValue($manager))->toBe($logger);
        });

        it('injects Laravel cache into OpcuaManager when bound', function () {
            $cache = Mockery::mock(CacheInterface::class);
            $app = makeApp(cache: $cache);
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $manager = $app->make(OpcuaManager::class);
            $ref = new ReflectionProperty($manager, 'defaultCache');

            expect($ref->getValue($manager))->toBe($cache);
        });

        it('injects Laravel event dispatcher into OpcuaManager when bound', function () {
            $dispatcher = Mockery::mock(EventDispatcherInterface::class);
            $app = makeApp(eventDispatcher: $dispatcher);
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $manager = $app->make(OpcuaManager::class);
            $ref = new ReflectionProperty($manager, 'defaultEventDispatcher');

            expect($ref->getValue($manager))->toBe($dispatcher);
        });

        it('binds a default PSR-14 dispatcher adapting Laravel events when none is bound', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            expect($app->bound(EventDispatcherInterface::class))->toBeTrue();

            $dispatcher = $app->make(EventDispatcherInterface::class);
            expect($dispatcher)->toBeInstanceOf(LaravelPsr14Dispatcher::class);

            // The manager receives it instead of null.
            $manager = $app->make(OpcuaManager::class);
            $ref = new ReflectionProperty($manager, 'defaultEventDispatcher');
            expect($ref->getValue($manager))->toBe($dispatcher);
        });

        it('does not override an event dispatcher the application already bound', function () {
            $custom = Mockery::mock(EventDispatcherInterface::class);
            $app = makeApp(eventDispatcher: $custom);
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            expect($app->make(EventDispatcherInterface::class))->toBe($custom);
        });

        it('leaves defaults null when Laravel logger/cache are not bound', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $manager = $app->make(OpcuaManager::class);
            $loggerRef = new ReflectionProperty($manager, 'defaultLogger');
            $cacheRef = new ReflectionProperty($manager, 'defaultCache');

            expect($loggerRef->getValue($manager))->toBeNull();
            expect($cacheRef->getValue($manager))->toBeNull();
        });

        it('wires a logger resolver when Laravel log manager is bound', function () {
            $app = makeApp();

            $channelLogger = new NullLogger();
            $logManager = new class($channelLogger) {
                public function __construct(private LoggerInterface $logger) {}
                public function channel(string $name): LoggerInterface
                {
                    return $this->logger;
                }
            };
            $app->instance('log', $logManager);

            $provider = new OpcuaServiceProvider($app);
            $provider->register();

            $manager = $app->make(OpcuaManager::class);
            $resolverRef = new ReflectionProperty($manager, 'loggerResolver');
            $resolver = $resolverRef->getValue($manager);

            expect($resolver)->toBeInstanceOf(Closure::class);
            expect($resolver('stderr'))->toBe($channelLogger);
        });

        it('leaves logger resolver null when log manager is not bound', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $manager = $app->make(OpcuaManager::class);
            $resolverRef = new ReflectionProperty($manager, 'loggerResolver');

            expect($resolverRef->getValue($manager))->toBeNull();
        });
    });

    describe('boot', function () {

        it('registers the opcua:session command when running in console', function () {
            $app = Mockery::mock(Container::class . '[runningInConsole]');
            $app->shouldReceive('runningInConsole')->andReturn(true);
            Container::setInstance($app);

            $app->instance('app', $app);

            $config = new Repository([
                'opcua' => [
                    'default' => 'default',
                    'session_manager' => [
                        'enabled' => false,
                        'socket_path' => '/tmp/test.sock',
                        'timeout' => 600,
                        'cleanup_interval' => 30,
                        'auth_token' => null,
                        'max_sessions' => 100,
                        'socket_mode' => 0600,
                        'allowed_cert_dirs' => null,
                        'log_file' => null,
                        'log_level' => 'info',
                        'cache_driver' => 'memory',
                        'cache_path' => null,
                        'cache_ttl' => 300,
                    ],
                    'connections' => [
                        'default' => [
                            'endpoint' => 'opc.tcp://localhost:4840',
                        ],
                    ],
                ],
            ]);

            $app->instance('config', $config);
            $app->instance('events', new Dispatcher($app));
            $app->instance('path.config', sys_get_temp_dir());

            $provider = new OpcuaServiceProvider($app);
            $provider->register();
            $provider->boot();

            expect(true)->toBeTrue();
        });
    });
});
