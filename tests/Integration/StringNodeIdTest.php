<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("String NodeId via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('reads ServerState using string NodeId "i=2259"', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $dv = $client->read('i=2259');

                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browses Objects folder using string NodeId "i=85"', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $refs = $client->browse('i=85');

                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn($r) => $r->browseName->name, $refs);
                expect($names)->toContain('Server');
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('reads multiple nodes using string NodeIds', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $results = $client->readMulti([
                    ['nodeId' => 'i=2259'],
                    ['nodeId' => 'i=2257'],
                ]);

                expect($results)->toHaveCount(2);
                expect($results[0]->statusCode)->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
