<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Timeout configuration via OpcuaManager ({$mode} mode)", function () use ($factory, $mode) {

        it('applies timeout from config', function () use ($factory) {
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'timeout' => 15.0,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                expect($client->getTimeout())->toBe(15.0);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->statusCode)->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('uses default timeout when not configured', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                expect($client->getTimeout())->toBe(5.0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        if ($mode === 'managed') {
            it('applies timeout via fluent API after creation', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connection();
                    $client->setTimeout(20.0);

                    expect($client->getTimeout())->toBe(20.0);

                    $endpoint = TestHelper::ENDPOINT_NO_SECURITY;
                    $client->connect($endpoint);

                    $dv = $client->read(NodeId::numeric(0, 2259));
                    expect($dv->statusCode)->toBe(StatusCode::Good);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');
        }

    })->group('integration');

}
