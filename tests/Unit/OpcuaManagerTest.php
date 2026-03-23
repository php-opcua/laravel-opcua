<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

    describe('connection', function () {

        it('returns an OpcUaClientInterface instance', function () {
            $manager = new OpcuaManager(makeConfig());

            $client = $manager->connection('default');

            expect($client)->toBeInstanceOf(OpcUaClientInterface::class);
        });

        it('returns a direct Client when session manager is disabled', function () {
            $manager = new OpcuaManager(makeConfig([
                'session_manager' => ['enabled' => false],
            ]));

            $client = $manager->connection('default');

            expect($client)->toBeInstanceOf(Client::class);
        });

        it('returns the same instance on repeated calls', function () {
            $manager = new OpcuaManager(makeConfig());

            $a = $manager->connection('default');
            $b = $manager->connection('default');

            expect($a)->toBe($b);
        });

        it('returns the default connection when name is null', function () {
            $manager = new OpcuaManager(makeConfig());

            $a = $manager->connection();
            $b = $manager->connection('default');

            expect($a)->toBe($b);
        });

        it('throws for an unconfigured connection name', function () {
            $manager = new OpcuaManager(makeConfig());

            $manager->connection('nonexistent');
        })->throws(InvalidArgumentException::class, 'OPC UA connection [nonexistent] is not configured.');

        it('returns different instances for different connection names', function () {
            $manager = new OpcuaManager(makeConfig([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://host-a:4840'],
                    'second' => ['endpoint' => 'opc.tcp://host-b:4840'],
                ],
            ]));

            $a = $manager->connection('default');
            $b = $manager->connection('second');

            expect($a)->not->toBe($b);
        });
    });

    describe('disconnect', function () {

        it('removes the connection so a new one is created next time', function () {
            $manager = new OpcuaManager(makeConfig());

            $first = $manager->connection('default');

            // We can't actually call disconnect() because the Client isn't connected,
            // so we test via reflection that the connection is tracked and removed.
            $ref = new ReflectionProperty($manager, 'connections');
            expect($ref->getValue($manager))->toHaveKey('default');

            // Simulate: replace with a mock that won't error on disconnect
            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('disconnect')->once();
            $ref->setValue($manager, ['default' => $mock]);

            $manager->disconnect('default');

            expect($ref->getValue($manager))->not->toHaveKey('default');
        });

        it('does nothing when disconnecting an unknown connection', function () {
            $manager = new OpcuaManager(makeConfig());

            // Should not throw
            $manager->disconnect('nonexistent');

            expect(true)->toBeTrue();
        });
    });

    describe('disconnectAll', function () {

        it('disconnects all tracked connections', function () {
            $manager = new OpcuaManager(makeConfig([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://host-a:4840'],
                    'second' => ['endpoint' => 'opc.tcp://host-b:4840'],
                ],
            ]));

            // Create both connections
            $manager->connection('default');
            $manager->connection('second');

            $ref = new ReflectionProperty($manager, 'connections');
            expect($ref->getValue($manager))->toHaveCount(2);

            // Replace with mocks
            $mockA = Mockery::mock(OpcUaClientInterface::class);
            $mockA->shouldReceive('disconnect')->once();
            $mockB = Mockery::mock(OpcUaClientInterface::class);
            $mockB->shouldReceive('disconnect')->once();
            $ref->setValue($manager, ['default' => $mockA, 'second' => $mockB]);

            $manager->disconnectAll();

            expect($ref->getValue($manager))->toBeEmpty();
        });
    });

    describe('connect', function () {

        it('calls connect on the client with the configured endpoint', function () {
            $manager = new OpcuaManager(makeConfig([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://my-server:4840'],
                ],
            ]));

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('connect')->once()->with('opc.tcp://my-server:4840');

            $ref = new ReflectionProperty($manager, 'connections');
            $ref->setValue($manager, ['default' => $mock]);

            $result = $manager->connect('default');

            expect($result)->toBe($mock);
        });
    });

    describe('connectTo', function () {

        it('creates a client and connects to an arbitrary endpoint', function () {
            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('connect')->once()->with('opc.tcp://10.0.0.50:4840');

            $manager->shouldReceive('createClient')->once()->andReturn($mock);

            $result = $manager->connectTo('opc.tcp://10.0.0.50:4840');

            expect($result)->toBe($mock);
        });

        it('stores the connection under the ad-hoc name by default', function () {
            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('connect');

            $manager->shouldReceive('createClient')->andReturn($mock);

            $manager->connectTo('opc.tcp://host:4840');

            $ref = new ReflectionProperty(OpcuaManager::class, 'connections');
            expect($ref->getValue($manager))->toHaveKey('ad-hoc:opc.tcp://host:4840');
        });

        it('stores the connection under a custom name when "as" is provided', function () {
            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('connect');

            $manager->shouldReceive('createClient')->andReturn($mock);

            $manager->connectTo('opc.tcp://host:4840', as: 'my-plc');

            $ref = new ReflectionProperty(OpcuaManager::class, 'connections');
            $connections = $ref->getValue($manager);
            expect($connections)->toHaveKey('my-plc');
            expect($connections)->not->toHaveKey('ad-hoc:opc.tcp://host:4840');
        });

        it('can be retrieved by name after connectTo', function () {
            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('connect');

            $manager->shouldReceive('createClient')->andReturn($mock);

            $manager->connectTo('opc.tcp://host:4840', as: 'temp');

            expect($manager->connection('temp'))->toBe($mock);
        });

        it('applies inline config to the client', function () {
            $manager = Mockery::mock(OpcuaManager::class, [makeConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $mock = Mockery::mock(OpcUaClientInterface::class);
            $mock->shouldReceive('connect');

            $manager->shouldReceive('createClient')->andReturn($mock);
            $manager->shouldReceive('configureClient')->once()->with($mock, [
                'username' => 'admin',
                'password' => 'pass',
            ]);

            $manager->connectTo('opc.tcp://host:4840', config: [
                'username' => 'admin',
                'password' => 'pass',
            ]);
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

        it('creates a direct Client when session manager is disabled', function () {
            $manager = new OpcuaManager(makeConfig([
                'session_manager' => ['enabled' => false],
            ]));

            $client = $manager->connection('default');

            expect($client)->toBeInstanceOf(Client::class);
        });

        it('creates a direct Client when socket does not exist', function () {
            $manager = new OpcuaManager(makeConfig([
                'session_manager' => [
                    'enabled' => true,
                    'socket_path' => '/tmp/nonexistent-' . uniqid() . '.sock',
                ],
            ]));

            $client = $manager->connection('default');

            expect($client)->toBeInstanceOf(Client::class);
        });

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

                expect($client)->toBeInstanceOf(
                    \Gianfriaur\OpcuaSessionManager\Client\ManagedClient::class,
                );
            } finally {
                @unlink($sockPath);
            }
        });
    });

    describe('configureClient certificate behavior', function () {

        it('does not call setClientCertificate when both cert and key are absent', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setClientCertificate');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, [
                'client_certificate' => null,
                'client_key' => null,
            ]);
        });

        it('does not call setClientCertificate when only client_certificate is set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setClientCertificate');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, [
                'client_certificate' => '/path/to/cert.pem',
                'client_key' => null,
            ]);
        });

        it('does not call setClientCertificate when only client_key is set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setClientCertificate');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, [
                'client_certificate' => null,
                'client_key' => '/path/to/key.pem',
            ]);
        });

        it('calls setClientCertificate when both client_certificate and client_key are set', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setClientCertificate')
                ->once()
                ->with('/path/cert.pem', '/path/key.pem', null)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, [
                'client_certificate' => '/path/cert.pem',
                'client_key'         => '/path/key.pem',
            ]);
        });

        it('passes ca_certificate as third argument when provided', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setClientCertificate')
                ->once()
                ->with('/path/cert.pem', '/path/key.pem', '/path/ca.pem')
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, [
                'client_certificate' => '/path/cert.pem',
                'client_key'         => '/path/key.pem',
                'ca_certificate'     => '/path/ca.pem',
            ]);
        });

    });

    describe('configureClient v2.0 options', function () {

        it('applies timeout when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setTimeout')
                ->once()
                ->with(15.0)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['timeout' => 15.0]);
        });

        it('does not call setTimeout when timeout is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setTimeout');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['timeout' => null]);
        });

        it('applies auto_retry when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setAutoRetry')
                ->once()
                ->with(3)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['auto_retry' => 3]);
        });

        it('does not call setAutoRetry when auto_retry is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setAutoRetry');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['auto_retry' => null]);
        });

        it('applies batch_size when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setBatchSize')
                ->once()
                ->with(100)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['batch_size' => 100]);
        });

        it('applies batch_size=0 to disable batching', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setBatchSize')
                ->once()
                ->with(0)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['batch_size' => 0]);
        });

        it('does not call setBatchSize when batch_size is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setBatchSize');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['batch_size' => null]);
        });

        it('applies browse_max_depth when configured', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setDefaultBrowseMaxDepth')
                ->once()
                ->with(20)
                ->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['browse_max_depth' => 20]);
        });

        it('does not call setDefaultBrowseMaxDepth when browse_max_depth is null', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setDefaultBrowseMaxDepth');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['browse_max_depth' => null]);
        });

        it('applies all v2.0 options together', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setTimeout')->once()->with(10.0)->andReturnSelf();
            $mock->shouldReceive('setAutoRetry')->once()->with(2)->andReturnSelf();
            $mock->shouldReceive('setBatchSize')->once()->with(50)->andReturnSelf();
            $mock->shouldReceive('setDefaultBrowseMaxDepth')->once()->with(15)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, [
                'timeout' => 10.0,
                'auto_retry' => 2,
                'batch_size' => 50,
                'browse_max_depth' => 15,
            ]);
        });
    });

    describe('configureClient v3.0 options', function () {

        it('applies explicit logger from config', function () {
            $logger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setLogger')->once()->with($logger)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['logger' => $logger]);
        });

        it('applies default logger when no explicit logger in config', function () {
            $logger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultLogger: $logger);
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setLogger')->once()->with($logger)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, []);
        });

        it('does not call setLogger when no logger available', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setLogger');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, []);
        });

        it('applies explicit cache from config', function () {
            $cache = Mockery::mock(CacheInterface::class);
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setCache')->once()->with($cache)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['cache' => $cache]);
        });

        it('applies null cache from config to disable caching', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setCache')->once()->with(null)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['cache' => null]);
        });

        it('applies default cache when no explicit cache in config', function () {
            $cache = Mockery::mock(CacheInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultCache: $cache);
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setCache')->once()->with($cache)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, []);
        });

        it('does not call setCache when no cache available', function () {
            $manager = new OpcuaManager(makeConfig());
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldNotReceive('setCache');

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, []);
        });

        it('explicit config logger takes precedence over default', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);
            $explicitLogger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultLogger: $defaultLogger);
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setLogger')->once()->with($explicitLogger)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['logger' => $explicitLogger]);
        });

        it('explicit config cache takes precedence over default', function () {
            $defaultCache = Mockery::mock(CacheInterface::class);
            $explicitCache = Mockery::mock(CacheInterface::class);
            $manager = new OpcuaManager(makeConfig(), defaultCache: $defaultCache);
            $mock = Mockery::mock(Client::class)->makePartial();
            $mock->shouldReceive('setCache')->once()->with($explicitCache)->andReturnSelf();

            $method = new ReflectionMethod($manager, 'configureClient');
            $method->invoke($manager, $mock, ['cache' => $explicitCache]);
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
});
