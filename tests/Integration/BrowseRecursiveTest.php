<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("BrowseAll via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('browseAll returns all references with automatic continuation', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $refs = $client->browseAll(NodeId::numeric(0, 85));

                expect($refs)->toBeArray()->not->toBeEmpty();
                expect($refs[0])->toBeInstanceOf(ReferenceDescription::class);

                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('Server');
                expect($names)->toContain('TestServer');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browseAll with BrowseDirection::Inverse', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

                $refs = $client->browseAll($testServerNodeId, BrowseDirection::Inverse);

                expect($refs)->toBeArray()->not->toBeEmpty();
                foreach ($refs as $ref) {
                    expect($ref->isForward)->toBeFalse();
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

    describe("BrowseRecursive via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('browseRecursive returns BrowseNode tree', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

                $tree = $client->browseRecursive($testServerNodeId, maxDepth: 2);

                expect($tree)->toBeArray()->not->toBeEmpty();
                expect($tree[0])->toBeInstanceOf(BrowseNode::class);

                $dataTypesNode = null;
                foreach ($tree as $node) {
                    if ($node->reference->browseName->name === 'DataTypes') {
                        $dataTypesNode = $node;
                        break;
                    }
                }

                expect($dataTypesNode)->not->toBeNull();
                expect($dataTypesNode->hasChildren())->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browseRecursive respects maxDepth=1', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

                $tree = $client->browseRecursive($testServerNodeId, maxDepth: 1);

                expect($tree)->toBeArray()->not->toBeEmpty();

                foreach ($tree as $node) {
                    expect($node->hasChildren())->toBeFalse();
                }
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('applies browse_max_depth from config', function () use ($factory) {
            $manager = TestHelper::$factory([
                'connections' => [
                    'default' => [
                        'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                        'browse_max_depth' => 5,
                    ],
                ],
            ]);
            try {
                $client = $manager->connect();

                expect($client->getDefaultBrowseMaxDepth())->toBe(5);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browseRecursive finds Methods folder children', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);

                $tree = $client->browseRecursive($methodsNodeId, maxDepth: 1);

                expect($tree)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn(BrowseNode $n) => $n->reference->browseName->name, $tree);
                expect($names)->toContain('Add');
                expect($names)->toContain('Multiply');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
