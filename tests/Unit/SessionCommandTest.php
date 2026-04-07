<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Commands\SessionCommand;

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = \Illuminate\Container\Container::getInstance();
        if ($abstract === null) {
            return $container;
        }
        return $container->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = app('config');
        if ($key === null) {
            return $config;
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('windows_os')) {
    function windows_os(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

function makeSessionCommandApp(array $configOverrides = []): Container
{
    $app = Mockery::mock(Container::class . '[runningUnitTests]');
    $app->shouldReceive('runningUnitTests')->andReturn(true);
    Container::setInstance($app);

    $app->instance('app', $app);

    $smConfig = array_merge([
        'enabled' => false,
        'socket_path' => sys_get_temp_dir() . '/opcua-cmd-test-' . uniqid() . '.sock',
        'timeout' => 600,
        'cleanup_interval' => 30,
        'auth_token' => null,
        'max_sessions' => 100,
        'socket_mode' => 0600,
        'allowed_cert_dirs' => null,
        'log_channel' => 'stack',
        'cache_store' => 'array',
    ], $configOverrides);

    $config = new Repository([
        'opcua' => [
            'default' => 'default',
            'session_manager' => $smConfig,
            'connections' => [
                'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
            ],
        ],
    ]);

    $app->instance('config', $config);
    $app->instance('events', new Dispatcher($app));

    $logger = new NullLogger();
    $app->instance(LoggerInterface::class, $logger);

    $logManager = Mockery::mock();
    $logManager->shouldReceive('channel')->andReturn($logger);
    $app->instance('log', $logManager);

    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheManager = Mockery::mock();
    $cacheManager->shouldReceive('store')->andReturn($cacheMock);
    $app->instance('cache', $cacheManager);
    $app->instance(CacheInterface::class, $cacheMock);

    return $app;
}

/**
 * Creates a testable SessionCommand that captures the daemon parameters instead of running it.
 */
function makeTestableCommand(array $configOverrides = []): array
{
    $app = makeSessionCommandApp($configOverrides);

    $captured = [];

    $command = new class extends SessionCommand {
        public array $capturedArgs = [];

        protected function createDaemon(
            string          $socketPath,
            int             $timeout,
            int             $cleanupInterval,
            ?string         $authToken,
            int             $maxSessions,
            int             $socketMode,
            ?array          $allowedCertDirs,
            LoggerInterface $logger,
            ?CacheInterface $clientCache,
        ): SessionManagerDaemon {
            $this->capturedArgs = [
                'socketPath' => $socketPath,
                'timeout' => $timeout,
                'cleanupInterval' => $cleanupInterval,
                'authToken' => $authToken,
                'maxSessions' => $maxSessions,
                'socketMode' => $socketMode,
                'allowedCertDirs' => $allowedCertDirs,
                'logger' => $logger,
                'clientCache' => $clientCache,
            ];

            $mock = Mockery::mock(SessionManagerDaemon::class);
            $mock->shouldReceive('run')->once();
            return $mock;
        }
    };

    $command->setLaravel($app);

    return [$command, $app];
}

function runCommand(object $command, array $options = []): int
{
    $input = new \Symfony\Component\Console\Input\ArrayInput($options);
    $output = new \Symfony\Component\Console\Output\BufferedOutput();

    $command->run($input, $output);

    return 0;
}

describe('SessionCommand', function () {

    afterEach(function () {
        Container::setInstance(null);
        Mockery::close();
    });

    describe('default config values', function () {

        it('passes config defaults to the daemon', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['timeout'])->toBe(600);
            expect($command->capturedArgs['cleanupInterval'])->toBe(30);
            expect($command->capturedArgs['maxSessions'])->toBe(100);
            expect($command->capturedArgs['socketMode'])->toBe(0600);
            expect($command->capturedArgs['authToken'])->toBeNull();
            expect($command->capturedArgs['allowedCertDirs'])->toBeNull();
        });

        it('passes socket path from config', function () {
            [$command] = makeTestableCommand([
                'socket_path' => '/tmp/custom-test.sock',
            ]);

            runCommand($command);

            expect($command->capturedArgs['socketPath'])->toBe('/tmp/custom-test.sock');
        });

        it('passes auth token from config', function () {
            [$command] = makeTestableCommand([
                'auth_token' => 'my-secret-token',
            ]);

            runCommand($command);

            expect($command->capturedArgs['authToken'])->toBe('my-secret-token');
        });

        it('passes allowed cert dirs from config', function () {
            [$command] = makeTestableCommand([
                'allowed_cert_dirs' => ['/etc/opcua/certs'],
            ]);

            runCommand($command);

            expect($command->capturedArgs['allowedCertDirs'])->toBe(['/etc/opcua/certs']);
        });
    });

    describe('CLI option overrides', function () {

        it('--timeout overrides config', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--timeout' => '300']);

            expect($command->capturedArgs['timeout'])->toBe(300);
        });

        it('--cleanup-interval overrides config', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--cleanup-interval' => '10']);

            expect($command->capturedArgs['cleanupInterval'])->toBe(10);
        });

        it('--max-sessions overrides config', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--max-sessions' => '50']);

            expect($command->capturedArgs['maxSessions'])->toBe(50);
        });

        it('--socket-mode overrides config as octal', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--socket-mode' => '0660']);

            expect($command->capturedArgs['socketMode'])->toBe(0660);
        });
    });

    describe('logger resolution', function () {

        it('resolves logger via app log manager', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['logger'])->toBeInstanceOf(LoggerInterface::class);
        });

        it('passes log-channel option to log manager', function () {
            $app = makeSessionCommandApp();

            $logManager = Mockery::mock();
            $logManager->shouldReceive('channel')
                ->with('stderr')
                ->once()
                ->andReturn(new NullLogger());
            $app->instance('log', $logManager);

            $command = new class extends SessionCommand {
                public array $capturedArgs = [];

                protected function createDaemon(
                    string          $socketPath,
                    int             $timeout,
                    int             $cleanupInterval,
                    ?string         $authToken,
                    int             $maxSessions,
                    int             $socketMode,
                    ?array          $allowedCertDirs,
                    LoggerInterface $logger,
                    ?CacheInterface $clientCache,
                ): SessionManagerDaemon {
                    $this->capturedArgs = compact('logger');
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    return $mock;
                }
            };
            $command->setLaravel($app);

            runCommand($command, ['--log-channel' => 'stderr']);
        });
    });

    describe('cache resolution', function () {

        it('resolves cache via app cache manager', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['clientCache'])->toBeInstanceOf(CacheInterface::class);
        });

        it('passes cache-store option to cache manager', function () {
            $app = makeSessionCommandApp();

            $cacheMock = Mockery::mock(CacheInterface::class);
            $cacheManager = Mockery::mock();
            $cacheManager->shouldReceive('store')
                ->with('redis')
                ->once()
                ->andReturn($cacheMock);
            $app->instance('cache', $cacheManager);

            $command = new class extends SessionCommand {
                public array $capturedArgs = [];

                protected function createDaemon(
                    string          $socketPath,
                    int             $timeout,
                    int             $cleanupInterval,
                    ?string         $authToken,
                    int             $maxSessions,
                    int             $socketMode,
                    ?array          $allowedCertDirs,
                    LoggerInterface $logger,
                    ?CacheInterface $clientCache,
                ): SessionManagerDaemon {
                    $this->capturedArgs = compact('clientCache');
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    return $mock;
                }
            };
            $command->setLaravel($app);

            runCommand($command, ['--cache-store' => 'redis']);
        });
    });

    describe('output', function () {

        it('displays the startup info table', function () {
            [$command] = makeTestableCommand([
                'socket_path' => '/tmp/test-output.sock',
                'timeout' => 120,
                'auth_token' => 'secret',
            ]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();

            expect($rendered)->toContain('Starting OPC UA Session Manager');
            expect($rendered)->toContain('/tmp/test-output.sock');
            expect($rendered)->toContain('120s');
            expect($rendered)->toContain('configured');
        });

        it('shows "none" when auth token is null', function () {
            [$command] = makeTestableCommand([
                'auth_token' => null,
            ]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();
            expect($rendered)->toContain('none');
        });

        it('shows "any" when allowed cert dirs is null', function () {
            [$command] = makeTestableCommand([
                'allowed_cert_dirs' => null,
            ]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();
            expect($rendered)->toContain('any');
        });
    });

    describe('daemon creation', function () {

        it('calls daemon run()', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            // If we got here without exception, run() was called (Mockery verifies it)
            expect(true)->toBeTrue();
        });

        it('creates socket directory if missing', function () {
            $dir = sys_get_temp_dir() . '/opcua-cmd-test-' . uniqid() . '/nested';
            $sockPath = $dir . '/test.sock';

            [$command] = makeTestableCommand([
                'socket_path' => $sockPath,
            ]);

            runCommand($command);

            expect(is_dir($dir))->toBeTrue();

            @rmdir($dir);
            @rmdir(dirname($dir));
        });
    });
});
