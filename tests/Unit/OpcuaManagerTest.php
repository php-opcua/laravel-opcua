<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ClientBuilderInterface;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\SessionManager\Client\ManagedClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

function makeConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'default' => 'default',
        'session_manager' => [
            'enabled' => false,
            'socket_path' => '/tmp/nonexistent.sock',
            'timeout' => 600,
            'cleanup_interval' => 30,
            'auth_token' => null,
            'max_sessions' => 100,
            'socket_mode' => 0600,
            'allowed_cert_dirs' => null,
        ],
        'connections' => [
            'default' => [
                'endpoint' => 'opc.tcp://localhost:4840',
                'security_policy' => null,
                'security_mode' => null,
                'username' => null,
                'password' => null,
                'client_certificate' => null,
                'client_key' => null,
                'ca_certificate' => null,
                'user_certificate' => null,
                'user_key' => null,
                'trust_store_path' => null,
                'trust_policy' => null,
                'auto_accept' => false,
                'auto_accept_force' => false,
                'auto_detect_write_type' => null,
                'read_metadata_cache' => null,
            ],
        ],
    ], $overrides);
}

describe('OpcuaManager', function () {

    describe('getDefaultConnection', function () {

        it('returns the configured default connection name', function () {
            $manager = new OpcuaManager(makeConfig(['default' => 'plc-1']));

            expect($manager->getDefaultConnection())->toBe('plc-1');
        });

        it('falls back to "default" when not configured', function () {
            $config = makeConfig();
            unset($config['default']);

            $manager = new OpcuaManager($config);

            expect($manager->getDefaultConnection())->toBe('default');
        });
    });

    describe('connection (direct mode)', function () {

        it('returns an OpcUaClientInterface instance', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')
                ->with('opc.tcp://localhost:4840')
                ->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->andReturn($mockBuilder);

            $client = $manager->connection('default');

            expect($client)->toBeInstanceOf(OpcUaClientInterface::class);
        });

        it('returns the same instance on repeated calls', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->once()->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->once()->andReturn($mockBuilder);

            $a = $manager->connection('default');
            $b = $manager->connection('default');

            expect($a)->toBe($b);
        });

        it('returns the default connection when name is null', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->once()->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->once()->andReturn($mockBuilder);

            $a = $manager->connection();
            $b = $manager->connection('default');

            expect($a)->toBe($b);
        });

        it('throws for an unconfigured connection name', function () {
            $manager = new OpcuaManager(makeConfig());

            $manager->connection('nonexistent');
        })->throws(InvalidArgumentException::class, 'OPC UA connection [nonexistent] is not configured.');

        it('returns different instances for different connection names', function () {
            $mockClientA = Mockery::mock(Client::class);
            $mockClientB = Mockery::mock(Client::class);
            $mockBuilderA = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilderA->shouldReceive('connect')
                ->with('opc.tcp://host-a:4840')
                ->andReturn($mockClientA);
            $mockBuilderB = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilderB->shouldReceive('connect')
                ->with('opc.tcp://host-b:4840')
                ->andReturn($mockClientB);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://host-a:4840'],
                    'second' => ['endpoint' => 'opc.tcp://host-b:4840'],
                ],
            ])])->makePartial()->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')
                ->twice()
                ->andReturn($mockBuilderA, $mockBuilderB);

            $a = $manager->connection('default');
            $b = $manager->connection('second');

            expect($a)->not->toBe($b);
        });

        it('auto-connects to configured endpoint via ClientBuilder', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')
                ->once()
                ->with('opc.tcp://my-server:4840')
                ->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://my-server:4840'],
                ],
            ])])->makePartial()->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->andReturn($mockBuilder);

            $client = $manager->connection('default');

            expect($client)->toBe($mockClient);
        });
    });

    describe('connection (managed mode)', function () {

        it('creates a ManagedClient when session manager socket exists', function () {
            $sockPath = sys_get_temp_dir() . '/opcua-test-' . uniqid() . '.sock';
            touch($sockPath);

            try {
                $manager = new OpcuaManager(makeConfig([
                    'session_manager' => [
                        'enabled' => true,
                        'socket_path' => $sockPath,
                    ],
                ]));

                $client = $manager->connection('default');

                expect($client)->toBeInstanceOf(ManagedClient::class);
            } finally {
                @unlink($sockPath);
            }
        });
    });

    describe('disconnect', function () {

        it('removes the connection so a new one is created next time', function () {
            $manager = new OpcuaManager(makeConfig());

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('disconnect')->once();

            $ref = new ReflectionProperty($manager, 'connections');
            $ref->setValue($manager, ['default' => $mock]);

            $manager->disconnect('default');

            expect($ref->getValue($manager))->not->toHaveKey('default');
        });

        it('does nothing when disconnecting an unknown connection', function () {
            $manager = new OpcuaManager(makeConfig());

            $manager->disconnect('nonexistent');

            expect(true)->toBeTrue();
        });
    });

    describe('disconnectAll', function () {

        it('disconnects all tracked connections', function () {
            $manager = new OpcuaManager(makeConfig());

            $mockA = Mockery::mock(OpcUaClientInterface::class);
            $mockA->shouldReceive('disconnect')->once();
            $mockB = Mockery::mock(OpcUaClientInterface::class);
            $mockB->shouldReceive('disconnect')->once();

            $ref = new ReflectionProperty($manager, 'connections');
            $ref->setValue($manager, ['default' => $mockA, 'second' => $mockB]);

            $manager->disconnectAll();

            expect($ref->getValue($manager))->toBeEmpty();
        });
    });

    describe('connect', function () {

        it('connects a ManagedClient to the configured endpoint', function () {
            $sockPath = sys_get_temp_dir() . '/opcua-test-' . uniqid() . '.sock';
            touch($sockPath);

            try {
                $mockClient = Mockery::mock(ManagedClient::class)->makePartial();
                $mockClient->shouldReceive('isConnected')->andReturn(false);
                $mockClient->shouldReceive('connect')
                    ->once()
                    ->with('opc.tcp://my-server:4840');

                $manager = new OpcuaManager(makeConfig([
                    'session_manager' => [
                        'enabled' => true,
                        'socket_path' => $sockPath,
                    ],
                    'connections' => [
                        'default' => ['endpoint' => 'opc.tcp://my-server:4840'],
                    ],
                ]));

                $ref = new ReflectionProperty($manager, 'connections');
                $ref->setValue($manager, ['default' => $mockClient]);

                $result = $manager->connect('default');

                expect($result)->toBe($mockClient);
            } finally {
                @unlink($sockPath);
            }
        });

        it('returns already-connected direct mode client without reconnecting', function () {
            $mockClient = Mockery::mock(Client::class);

            $manager = new OpcuaManager(makeConfig());

            $ref = new ReflectionProperty($manager, 'connections');
            $ref->setValue($manager, ['default' => $mockClient]);

            $result = $manager->connect('default');

            expect($result)->toBe($mockClient);
        });

        it('connects the default connection when name is null', function () {
            $mockClient = Mockery::mock(Client::class);

            $manager = new OpcuaManager(makeConfig());

            $ref = new ReflectionProperty($manager, 'connections');
            $ref->setValue($manager, ['default' => $mockClient]);

            $result = $manager->connect(null);

            expect($result)->toBe($mockClient);
        });
    });

    describe('connectTo (direct mode)', function () {

        it('creates a builder and connects to an arbitrary endpoint', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')
                ->once()
                ->with('opc.tcp://10.0.0.50:4840')
                ->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->once()->andReturn($mockBuilder);

            $result = $manager->connectTo('opc.tcp://10.0.0.50:4840');

            expect($result)->toBe($mockClient);
        });

        it('stores the connection under the ad-hoc name by default', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->andReturn($mockBuilder);

            $manager->connectTo('opc.tcp://host:4840');

            $ref = new ReflectionProperty(OpcuaManager::class, 'connections');
            expect($ref->getValue($manager))->toHaveKey('ad-hoc:opc.tcp://host:4840');
        });

        it('stores the connection under a custom name when "as" is provided', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->andReturn($mockBuilder);

            $manager->connectTo('opc.tcp://host:4840', as: 'my-plc');

            $ref = new ReflectionProperty(OpcuaManager::class, 'connections');
            $connections = $ref->getValue($manager);
            expect($connections)->toHaveKey('my-plc');
            expect($connections)->not->toHaveKey('ad-hoc:opc.tcp://host:4840');
        });

        it('can be retrieved by name after connectTo', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->andReturn($mockBuilder);

            $manager->connectTo('opc.tcp://host:4840', as: 'temp');

            expect($manager->connection('temp'))->toBe($mockClient);
        });

        it('applies inline config to the builder', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->andReturn($mockBuilder);
            $manager->shouldReceive('configureBuilder')->once()->with($mockBuilder, [
                'username' => 'admin',
                'password' => 'pass',
            ]);

            $manager->connectTo('opc.tcp://host:4840', config: [
                'username' => 'admin',
                'password' => 'pass',
            ]);
        });
    });

    describe('connectTo (managed mode)', function () {

        it('creates a ManagedClient and connects to an arbitrary endpoint', function () {
            $sockPath = sys_get_temp_dir() . '/opcua-test-' . uniqid() . '.sock';
            touch($sockPath);

            try {
                $mockClient = Mockery::mock(ManagedClient::class)->makePartial();
                $mockClient->shouldReceive('connect')
                    ->once()
                    ->with('opc.tcp://10.0.0.50:4840');

                $manager = Mockery::mock(OpcuaManager::class, [makeConfig([
                    'session_manager' => [
                        'enabled' => true,
                        'socket_path' => $sockPath,
                    ],
                ])])->makePartial()->shouldAllowMockingProtectedMethods();
                $manager->shouldReceive('createManagedClient')->once()->andReturn($mockClient);

                $result = $manager->connectTo('opc.tcp://10.0.0.50:4840');

                expect($result)->toBe($mockClient);
            } finally {
                @unlink($sockPath);
            }
        });
    });

    describe('isSessionManagerRunning', function () {

        it('returns false when socket does not exist', function () {
            $manager = new OpcuaManager(makeConfig([
                'session_manager' => [
                    'socket_path' => '/tmp/nonexistent-' . uniqid() . '.sock',
                ],
            ]));

            expect($manager->isSessionManagerRunning())->toBeFalse();
        });

        it('returns true when socket file exists', function () {
            $sockPath = sys_get_temp_dir() . '/opcua-test-' . uniqid() . '.sock';
            touch($sockPath);

            try {
                $manager = new OpcuaManager(makeConfig([
                    'session_manager' => [
                        'socket_path' => $sockPath,
                    ],
                ]));

                expect($manager->isSessionManagerRunning())->toBeTrue();
            } finally {
                @unlink($sockPath);
            }
        });

        it('returns false when socket_path is null', function () {
            $manager = new OpcuaManager(makeConfig([
                'session_manager' => [
                    'socket_path' => null,
                ],
            ]));

            expect($manager->isSessionManagerRunning())->toBeFalse();
        });
    });

    describe('session manager auto-detection', function () {

        it('uses direct mode when session manager is disabled', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig([
                'session_manager' => ['enabled' => false],
            ])])->makePartial()->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->once()->andReturn($mockBuilder);

            $client = $manager->connection('default');

            expect($client)->toBe($mockClient);
        });

        it('uses direct mode when socket does not exist', function () {
            $mockClient = Mockery::mock(Client::class);
            $mockBuilder = Mockery::mock(ClientBuilderInterface::class);
            $mockBuilder->shouldReceive('connect')->andReturn($mockClient);

            $manager = Mockery::mock(OpcuaManager::class, [makeConfig([
                'session_manager' => [
                    'enabled' => true,
                    'socket_path' => '/tmp/nonexistent-' . uniqid() . '.sock',
                ],
            ])])->makePartial()->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('createBuilder')->once()->andReturn($mockBuilder);

            $client = $manager->connection('default');

            expect($client)->toBe($mockClient);
        });

        it('uses managed mode when session manager socket exists', function () {
            $sockPath = sys_get_temp_dir() . '/opcua-test-' . uniqid() . '.sock';
            touch($sockPath);

            try {
                $manager = new OpcuaManager(makeConfig([
                    'session_manager' => [
                        'enabled' => true,
                        'socket_path' => $sockPath,
                    ],
                ]));

                $client = $manager->connection('default');

                expect($client)->toBeInstanceOf(ManagedClient::class);
            } finally {
                @unlink($sockPath);
            }
        });
    });

    describe('configureBuilder', function () {

        it('applies timeout when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setTimeout')->once()->with(15.0)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['timeout' => 15.0]);
        });

        it('does not call setTimeout when timeout is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setTimeout');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['timeout' => null]);
        });

        it('applies auto_retry when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setAutoRetry')->once()->with(3)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_retry' => 3]);
        });

        it('does not call setAutoRetry when auto_retry is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setAutoRetry');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_retry' => null]);
        });

        it('applies batch_size when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setBatchSize')->once()->with(100)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['batch_size' => 100]);
        });

        it('applies batch_size=0 to disable batching', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setBatchSize')->once()->with(0)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['batch_size' => 0]);
        });

        it('does not call setBatchSize when batch_size is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setBatchSize');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['batch_size' => null]);
        });

        it('applies browse_max_depth when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setDefaultBrowseMaxDepth')->once()->with(20)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['browse_max_depth' => 20]);
        });

        it('does not call setDefaultBrowseMaxDepth when browse_max_depth is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setDefaultBrowseMaxDepth');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['browse_max_depth' => null]);
        });

        it('applies all options together', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setTimeout')->once()->with(10.0)->andReturnSelf();
            $mock->shouldReceive('setAutoRetry')->once()->with(2)->andReturnSelf();
            $mock->shouldReceive('setBatchSize')->once()->with(50)->andReturnSelf();
            $mock->shouldReceive('setDefaultBrowseMaxDepth')->once()->with(15)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'timeout' => 10.0,
                'auto_retry' => 2,
                'batch_size' => 50,
                'browse_max_depth' => 15,
            ]);
        });

        it('applies explicit logger from config', function () {
            $logger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setLogger')->once()->with($logger)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['logger' => $logger]);
        });

        it('applies default logger when no explicit logger in config', function () {
            $logger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultLogger: $logger);
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setLogger')->once()->with($logger)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, []);
        });

        it('does not call setLogger when no logger available', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setLogger');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, []);
        });

        it('explicit config logger takes precedence over default', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);
            $explicitLogger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultLogger: $defaultLogger);
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setLogger')->once()->with($explicitLogger)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['logger' => $explicitLogger]);
        });

        it('applies explicit cache from config', function () {
            $cache = Mockery::mock(CacheInterface::class);
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setCache')->once()->with($cache)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['cache' => $cache]);
        });

        it('applies null cache from config to disable caching', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setCache')->once()->with(null)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['cache' => null]);
        });

        it('applies default cache when no explicit cache in config', function () {
            $cache = Mockery::mock(CacheInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultCache: $cache);
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setCache')->once()->with($cache)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, []);
        });

        it('does not call setCache when no cache available', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setCache');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, []);
        });

        it('explicit config cache takes precedence over default', function () {
            $defaultCache = Mockery::mock(CacheInterface::class);
            $explicitCache = Mockery::mock(CacheInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultCache: $defaultCache);
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setCache')->once()->with($explicitCache)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['cache' => $explicitCache]);
        });

        it('applies event dispatcher from config', function () {
            $dispatcher = Mockery::mock(EventDispatcherInterface::class);
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['event_dispatcher' => $dispatcher]);
        });

        it('applies default event dispatcher when no explicit in config', function () {
            $dispatcher = Mockery::mock(EventDispatcherInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultEventDispatcher: $dispatcher);
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, []);
        });

        it('does not call setEventDispatcher when no dispatcher available', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setEventDispatcher');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, []);
        });

        it('applies trust store path by creating FileTrustStore', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setTrustStore')
                ->once()
                ->with(Mockery::type(FileTrustStore::class))
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['trust_store_path' => '/tmp/trust']);
        });

        it('does not call setTrustStore when trust_store_path is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setTrustStore');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['trust_store_path' => null]);
        });

        it('applies trust policy when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setTrustPolicy')
                ->once()
                ->with(TrustPolicy::Full)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['trust_policy' => 'full']);
        });

        it('does not call setTrustPolicy when trust_policy is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setTrustPolicy');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['trust_policy' => null]);
        });

        it('applies auto_accept when enabled', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('autoAccept')
                ->once()
                ->with(true, false)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_accept' => true, 'auto_accept_force' => false]);
        });

        it('applies auto_accept with force when both enabled', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('autoAccept')
                ->once()
                ->with(true, true)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_accept' => true, 'auto_accept_force' => true]);
        });

        it('does not call autoAccept when auto_accept is false', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('autoAccept');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_accept' => false]);
        });

        it('applies auto_detect_write_type when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setAutoDetectWriteType')->once()->with(true)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_detect_write_type' => true]);
        });

        it('does not call setAutoDetectWriteType when null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setAutoDetectWriteType');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['auto_detect_write_type' => null]);
        });

        it('applies read_metadata_cache when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setReadMetadataCache')->once()->with(true)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['read_metadata_cache' => true]);
        });

        it('does not call setReadMetadataCache when null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setReadMetadataCache');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['read_metadata_cache' => null]);
        });
    });

    describe('configureBuilder security options', function () {

        it('calls setSecurityPolicy when security_policy is configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setSecurityPolicy')
                ->once()
                ->with(SecurityPolicy::Basic256Sha256)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['security_policy' => 'Basic256Sha256']);
        });

        it('does not call setSecurityPolicy when security_policy is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setSecurityPolicy');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['security_policy' => null]);
        });

        it('calls setSecurityMode when security_mode is configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setSecurityMode')
                ->once()
                ->with(SecurityMode::SignAndEncrypt)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['security_mode' => 'SignAndEncrypt']);
        });

        it('does not call setSecurityMode when security_mode is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setSecurityMode');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['security_mode' => null]);
        });

        it('calls setUserCredentials when username is set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setUserCredentials')
                ->once()
                ->with('admin', 'secret')
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'username' => 'admin',
                'password' => 'secret',
            ]);
        });

        it('calls setUserCredentials with empty password when password is absent', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setUserCredentials')
                ->once()
                ->with('admin', '')
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['username' => 'admin']);
        });

        it('does not call setUserCredentials when username is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setUserCredentials');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, ['username' => null]);
        });

        it('calls setClientCertificate when both cert and key are set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setClientCertificate')
                ->once()
                ->with('/path/cert.pem', '/path/key.pem', null)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'client_certificate' => '/path/cert.pem',
                'client_key'         => '/path/key.pem',
            ]);
        });

        it('passes ca_certificate as third argument when provided', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setClientCertificate')
                ->once()
                ->with('/path/cert.pem', '/path/key.pem', '/path/ca.pem')
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'client_certificate' => '/path/cert.pem',
                'client_key'         => '/path/key.pem',
                'ca_certificate'     => '/path/ca.pem',
            ]);
        });

        it('does not call setClientCertificate when cert or key is absent', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setClientCertificate');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'client_certificate' => '/path/cert.pem',
                'client_key' => null,
            ]);
        });

        it('calls setUserCertificate when user_certificate and user_key are set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldReceive('setUserCertificate')
                ->once()
                ->with('/path/user-cert.pem', '/path/user-key.pem')
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'user_certificate' => '/path/user-cert.pem',
                'user_key'         => '/path/user-key.pem',
            ]);
        });

        it('does not call setUserCertificate when only user_certificate is set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ClientBuilderInterface::class);
            $mock->shouldNotReceive('setUserCertificate');

            $method = new ReflectionMethod($manager, 'configureBuilder');
            $method->invoke($manager, $mock, [
                'user_certificate' => '/path/user-cert.pem',
                'user_key'         => null,
            ]);
        });
    });

    describe('configureManagedClient', function () {

        it('applies timeout when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('setTimeout')->once()->with(15.0)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, ['timeout' => 15.0]);
        });

        it('applies trust store path', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('setTrustStorePath')
                ->once()
                ->with('/tmp/trust')
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, ['trust_store_path' => '/tmp/trust']);
        });

        it('applies auto_detect_write_type', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('setAutoDetectWriteType')->once()->with(false)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, ['auto_detect_write_type' => false]);
        });

        it('applies read_metadata_cache', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('setReadMetadataCache')->once()->with(true)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, ['read_metadata_cache' => true]);
        });

        it('applies event dispatcher', function () {
            $dispatcher = Mockery::mock(EventDispatcherInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultEventDispatcher: $dispatcher);
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('setEventDispatcher')->once()->with($dispatcher)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, []);
        });

        it('applies auto_accept with force', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('autoAccept')
                ->once()
                ->with(true, true)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, ['auto_accept' => true, 'auto_accept_force' => true]);
        });

        it('applies trust policy', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(ManagedClient::class)->makePartial();
            $mock->shouldReceive('setTrustPolicy')
                ->once()
                ->with(TrustPolicy::FingerprintAndExpiry)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureManagedClient');
            $method->invoke($manager, $mock, ['trust_policy' => 'fingerprint+expiry']);
        });
    });

    describe('__call proxy', function () {

        it('proxies method calls to the default connection', function () {
            $manager = new OpcuaManager(makeConfig());

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('getEndpoints')
                ->once()
                ->with('opc.tcp://localhost:4840')
                ->andReturn([]);

            $ref = new ReflectionProperty($manager, 'connections');
            $ref->setValue($manager, ['default' => $mock]);

            $result = $manager->getEndpoints('opc.tcp://localhost:4840');

            expect($result)->toBe([]);
        });
    });

    describe('resolveSecurityPolicyUri', function () {

        $method = fn() => new ReflectionMethod(OpcuaManager::class, 'resolveSecurityPolicyUri');

        it('resolves "None" to the full URI', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'None');
            expect($result)->toBe(SecurityPolicy::None->value);
        });

        it('resolves "Basic128Rsa15" to the full URI', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'Basic128Rsa15');
            expect($result)->toBe(SecurityPolicy::Basic128Rsa15->value);
        });

        it('resolves "Basic256" to the full URI', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'Basic256');
            expect($result)->toBe(SecurityPolicy::Basic256->value);
        });

        it('resolves "Basic256Sha256" to the full URI', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'Basic256Sha256');
            expect($result)->toBe(SecurityPolicy::Basic256Sha256->value);
        });

        it('resolves "Aes128Sha256RsaOaep" to the full URI', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'Aes128Sha256RsaOaep');
            expect($result)->toBe(SecurityPolicy::Aes128Sha256RsaOaep->value);
        });

        it('resolves "Aes256Sha256RsaPss" to the full URI', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'Aes256Sha256RsaPss');
            expect($result)->toBe(SecurityPolicy::Aes256Sha256RsaPss->value);
        });

        it('passes through an already-full URI unchanged', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $uri = 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256';
            $result = $method()->invoke($manager, $uri);
            expect($result)->toBe($uri);
        });

        it('passes through an unknown string unchanged', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            $result = $method()->invoke($manager, 'UnknownPolicy');
            expect($result)->toBe('UnknownPolicy');
        });
    });

    describe('resolveSecurityMode', function () {

        $method = fn() => new ReflectionMethod(OpcuaManager::class, 'resolveSecurityMode');

        it('resolves string "None" to SecurityMode::None', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 'None'))->toBe(SecurityMode::None);
        });

        it('resolves string "Sign" to SecurityMode::Sign', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 'Sign'))->toBe(SecurityMode::Sign);
        });

        it('resolves string "SignAndEncrypt" to SecurityMode::SignAndEncrypt', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 'SignAndEncrypt'))->toBe(SecurityMode::SignAndEncrypt);
        });

        it('resolves integer 1 to SecurityMode::None', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 1))->toBe(SecurityMode::None);
        });

        it('resolves integer 2 to SecurityMode::Sign', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 2))->toBe(SecurityMode::Sign);
        });

        it('resolves integer 3 to SecurityMode::SignAndEncrypt', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 3))->toBe(SecurityMode::SignAndEncrypt);
        });

        it('resolves numeric string "2" to SecurityMode::Sign', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, '2'))->toBe(SecurityMode::Sign);
        });
    });

    describe('resolveTrustPolicy', function () {

        $method = fn() => new ReflectionMethod(OpcuaManager::class, 'resolveTrustPolicy');

        it('resolves "fingerprint" to TrustPolicy::Fingerprint', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 'fingerprint'))->toBe(TrustPolicy::Fingerprint);
        });

        it('resolves "fingerprint+expiry" to TrustPolicy::FingerprintAndExpiry', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 'fingerprint+expiry'))->toBe(TrustPolicy::FingerprintAndExpiry);
        });

        it('resolves "full" to TrustPolicy::Full', function () use ($method) {
            $manager = new OpcuaManager(makeConfig());
            expect($method()->invoke($manager, 'full'))->toBe(TrustPolicy::Full);
        });
    });
});
