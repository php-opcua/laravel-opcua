<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Historical data access via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('reads raw historical data', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);

                $history = $client->historyReadRaw(
                    $nodeId,
                    new \DateTimeImmutable('-1 hour'),
                    new \DateTimeImmutable('now'),
                );

                expect($history)->toBeArray();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('historyReadAtTime returns values for specific timestamps', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $histNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);

                $timestamps = [
                    new \DateTimeImmutable('-30 minutes'),
                    new \DateTimeImmutable('-15 minutes'),
                    new \DateTimeImmutable('now'),
                ];

                $results = $client->historyReadAtTime($histNodeId, $timestamps);

                expect($results)->toBeArray();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
