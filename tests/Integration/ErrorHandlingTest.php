<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Error handling via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('returns BadNodeIdUnknown for a non-existent node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $dv = $client->read(NodeId::numeric(99, 99999));
                expect(StatusCode::isBad($dv->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('returns Bad status when writing to a read-only node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'ReadOnly', 'Boolean_RO']);

                $statusCode = $client->write($nodeId, true, BuiltinType::Boolean);
                expect(StatusCode::isBad($statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('throws for unconfigured connection name', function () use ($factory) {
            $manager = TestHelper::$factory();
            expect(fn() => $manager->connect('nonexistent'))
                ->toThrow(\InvalidArgumentException::class);
        })->group('integration');

    })->group('integration');

}
