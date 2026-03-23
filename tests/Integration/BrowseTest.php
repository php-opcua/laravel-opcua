<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Browse via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('browses the root Objects folder', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $refs = $client->browse(NodeId::numeric(0, 85));

                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('Server');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses the TestServer folder', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
                $refs = $client->browse($testServerNodeId);

                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('DataTypes');
                expect($names)->toContain('Methods');
                expect($names)->toContain('Dynamic');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses DataTypes folder', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes']);
                $refs = $client->browse($nodeId);

                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('Scalar');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses Methods folder', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($nodeId);

                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('Add');
                expect($names)->toContain('Multiply');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('verifies node classes are correct', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
                $refs = $client->browse($testServerNodeId);

                foreach ($refs as $ref) {
                    $name = $ref->browseName->name;
                    if (in_array($name, ['DataTypes', 'Methods', 'Dynamic'], true)) {
                        expect($ref->nodeClass)->toBe(NodeClass::Object);
                    }
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses with direction=Inverse', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

                $refs = $client->browse($testServerNodeId, direction: BrowseDirection::Inverse);

                expect($refs)->toBeArray()->not->toBeEmpty();
                foreach ($refs as $ref) {
                    expect($ref->isForward)->toBeFalse();
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses with specific reference type (Organizes)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $refs = $client->browse(
                    NodeId::numeric(0, 85),
                    direction: BrowseDirection::Forward,
                    referenceTypeId: NodeId::numeric(0, 35),
                    includeSubtypes: true,
                );

                expect($refs)->toBeArray()->not->toBeEmpty();
                foreach ($refs as $ref) {
                    expect($ref->isForward)->toBeTrue();
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses with continuation', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $result = $client->browseWithContinuation(NodeId::numeric(0, 85));

                expect($result->references)->toBeArray()->not->toBeEmpty();
                expect($result->continuationPoint === null || is_string($result->continuationPoint))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('deeply browses to a scalar node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);

                expect($nodeId)->toBeInstanceOf(NodeId::class);
                expect($nodeId->namespaceIndex)->toBeGreaterThanOrEqual(0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
