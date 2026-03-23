<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Dynamic variables via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('reads Counter (incrementing integer)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('reads Random (random value)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Random']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('reads SineWave', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'SineWave']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect($dv->getValue())->toBeGreaterThanOrEqual(-1.0)->toBeLessThanOrEqual(1.0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('reads Timestamp', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Timestamp']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
