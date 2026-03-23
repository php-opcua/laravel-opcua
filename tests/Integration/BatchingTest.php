<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Batching via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('discovers server operation limits after connect', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                // The no-security server (port 4840) has MaxNodesPerRead=5
                $maxRead = $client->getServerMaxNodesPerRead();
                $maxWrite = $client->getServerMaxNodesPerWrite();

                expect($maxRead)->toBe(5);
                expect($maxWrite)->toBe(5);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('transparently batches readMulti when items exceed server limit', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $boolNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);
                $intNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                $doubleNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DoubleValue']);
                $stringNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
                $floatNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'FloatValue']);
                $byteNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteValue']);
                $uint16NodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'UInt16Value']);

                // Read 7 nodes — exceeds server limit of 5
                $results = $client->readMulti([
                    ['nodeId' => $boolNodeId],
                    ['nodeId' => $intNodeId],
                    ['nodeId' => $doubleNodeId],
                    ['nodeId' => $stringNodeId],
                    ['nodeId' => $floatNodeId],
                    ['nodeId' => $byteNodeId],
                    ['nodeId' => $uint16NodeId],
                ]);

                expect($results)->toHaveCount(7);
                foreach ($results as $dv) {
                    expect($dv->statusCode)->toBe(StatusCode::Good);
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('transparently batches writeMulti when items exceed server limit', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $intNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                $doubleNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DoubleValue']);
                $stringNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
                $boolNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);
                $floatNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'FloatValue']);
                $byteNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteValue']);

                $results = $client->writeMulti([
                    ['nodeId' => $intNodeId, 'value' => 111, 'type' => BuiltinType::Int32],
                    ['nodeId' => $doubleNodeId, 'value' => 2.22, 'type' => BuiltinType::Double],
                    ['nodeId' => $stringNodeId, 'value' => 'batch-test', 'type' => BuiltinType::String],
                    ['nodeId' => $boolNodeId, 'value' => true, 'type' => BuiltinType::Boolean],
                    ['nodeId' => $floatNodeId, 'value' => 3.33, 'type' => BuiltinType::Float],
                    ['nodeId' => $byteNodeId, 'value' => 42, 'type' => BuiltinType::Byte],
                ]);

                expect($results)->toHaveCount(6);
                foreach ($results as $status) {
                    expect(StatusCode::isGood($status))->toBeTrue();
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('applies batch_size from config', function () use ($factory) {
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'batch_size' => 3,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                expect($client->getBatchSize())->toBe(3);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('disables batching with batch_size=0', function () use ($factory) {
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'batch_size' => 0,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                expect($client->getBatchSize())->toBe(0);
                expect($client->getServerMaxNodesPerRead())->toBeNull();
                expect($client->getServerMaxNodesPerWrite())->toBeNull();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
