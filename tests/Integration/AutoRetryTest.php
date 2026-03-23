<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Auto-retry via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('applies auto_retry from config', function () use ($factory) {
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'auto_retry' => 3,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                expect($client->getAutoRetry())->toBe(3);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('sets auto_retry via fluent API', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connection();
                $client->setAutoRetry(5);

                expect($client->getAutoRetry())->toBe(5);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('operates normally with auto_retry enabled', function () use ($factory) {
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'auto_retry' => 2,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();
                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
