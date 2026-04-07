<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Connection state via OpcuaManager ({$mode} mode)", function () use ($factory, $mode) {

        if ($mode === 'managed') {
            it('reports Disconnected before connect', function () use ($factory) {
                $manager = TestHelper::$factory();
                $client = $manager->connection();

                expect($client->isConnected())->toBeFalse();
                expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
            })->group('integration');
        }

        it('reports Connected after connect', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                expect($client->isConnected())->toBeTrue();
                expect($client->getConnectionState())->toBe(ConnectionState::Connected);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('reports Disconnected after disconnect', function () use ($factory) {
            $manager = TestHelper::$factory();
            $client = $manager->connect();

            expect($client->isConnected())->toBeTrue();

            $client->disconnect();

            expect($client->isConnected())->toBeFalse();
            expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
        })->group('integration');

        it('reconnects successfully', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $dv1 = $client->read(NodeId::numeric(0, 2259));
                expect($dv1->statusCode)->toBe(StatusCode::Good);

                $client->reconnect();

                expect($client->isConnected())->toBeTrue();
                $dv2 = $client->read(NodeId::numeric(0, 2259));
                expect($dv2->statusCode)->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
