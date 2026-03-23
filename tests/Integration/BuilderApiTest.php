<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Fluent Builder API via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('reads multiple nodes using readMulti builder', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $results = $client->readMulti()
                    ->node('i=2259')
                    ->node('i=2257')
                    ->execute();

                expect($results)->toHaveCount(2);
                expect($results[0]->statusCode)->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('writes multiple nodes using writeMulti builder', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $intNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                $strNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);

                $results = $client->writeMulti()
                    ->node($intNodeId)->int32(7777)
                    ->node($strNodeId)->string('builder-test')
                    ->execute();

                expect($results)->toHaveCount(2);
                expect(StatusCode::isGood($results[0]))->toBeTrue();
                expect(StatusCode::isGood($results[1]))->toBeTrue();

                $dv = $client->read($intNodeId);
                expect($dv->getValue())->toBe(7777);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('translateBrowsePaths builder resolves paths', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $results = $client->translateBrowsePaths()
                    ->from('i=84')
                    ->path('Objects')
                    ->execute();

                expect($results)->toHaveCount(1);
                expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();
                expect($results[0]->targets[0]->targetId->identifier)->toBe(85);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
