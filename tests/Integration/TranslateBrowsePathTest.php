<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("TranslateBrowsePaths via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('resolves /Objects to NodeId i=85', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $nodeId = $client->resolveNodeId('/Objects');

                expect($nodeId)->toBeInstanceOf(NodeId::class);
                expect($nodeId->identifier)->toBe(85);
                expect($nodeId->namespaceIndex)->toBe(0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('resolves /Objects/Server to the Server node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $nodeId = $client->resolveNodeId('/Objects/Server');

                expect($nodeId)->toBeInstanceOf(NodeId::class);
                expect($nodeId->identifier)->toBe(2253);
                expect($nodeId->namespaceIndex)->toBe(0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('resolves /Objects/Server/ServerStatus', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');

                expect($nodeId)->toBeInstanceOf(NodeId::class);
                expect($nodeId->identifier)->toBe(2256);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('translateBrowsePaths resolves multiple paths at once', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $results = $client->translateBrowsePaths([
                    [
                        'startingNodeId' => NodeId::numeric(0, 84),
                        'relativePath' => [
                            ['targetName' => new QualifiedName(0, 'Objects')],
                        ],
                    ],
                    [
                        'startingNodeId' => NodeId::numeric(0, 84),
                        'relativePath' => [
                            ['targetName' => new QualifiedName(0, 'Objects')],
                            ['targetName' => new QualifiedName(0, 'Server')],
                        ],
                    ],
                ]);

                expect($results)->toHaveCount(2);

                expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();
                expect($results[0]->targets)->not->toBeEmpty();
                expect($results[0]->targets[0]->targetId->identifier)->toBe(85);

                expect(StatusCode::isGood($results[1]->statusCode))->toBeTrue();
                expect($results[1]->targets)->not->toBeEmpty();
                expect($results[1]->targets[0]->targetId->identifier)->toBe(2253);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('resolves path to TestServer folder via browseToNode verification', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
                expect($testServerNodeId)->toBeInstanceOf(NodeId::class);

                $refs = $client->browse($testServerNodeId);
                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('DataTypes');
                expect($names)->toContain('Methods');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
