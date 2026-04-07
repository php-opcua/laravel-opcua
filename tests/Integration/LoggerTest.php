<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Logger via OpcuaManager ({$mode} mode)", function () use ($factory, $mode) {

        if ($mode === 'managed') {
            it('sets and gets a PSR-3 logger via runtime setter', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connection();
                    $logger = new NullLogger();

                    $client->setLogger($logger);

                    expect($client->getLogger())->toBe($logger);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');
        }

        it('configures logger from config', function () use ($factory) {
            $logger = new NullLogger();
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'logger' => $logger,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                expect($client->getLogger())->toBe($logger);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('returns a logger instance by default', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                expect($client->getLogger())->toBeInstanceOf(LoggerInterface::class);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('operations work with a custom logger attached', function () use ($factory) {
            $logger = new NullLogger();
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'logger' => $logger,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                $refs = $client->browse('i=85');
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
