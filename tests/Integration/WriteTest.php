<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Write via OpcuaManager ({$mode} mode)", function () use ($factory) {

        // ── Scalar writes ────────────────────────────────────────────

        describe('Write and read back scalars', function () use ($factory) {

            it('writes true to BooleanValue and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);

                    $statusCode = $client->write($nodeId, true, BuiltinType::Boolean);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->getValue())->toBeTrue();

                    $statusCode = $client->write($nodeId, false, BuiltinType::Boolean);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->getValue())->toBeFalse();
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

            it('writes an integer to Int32Value and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);

                    $statusCode = $client->write($nodeId, 12345, BuiltinType::Int32);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->statusCode)->toBe(StatusCode::Good);
                    expect($dv->getValue())->toBe(12345);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

            it('writes a float to DoubleValue and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DoubleValue']);

                    $statusCode = $client->write($nodeId, 3.14159, BuiltinType::Double);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->statusCode)->toBe(StatusCode::Good);
                    expect(abs($dv->getValue() - 3.14159))->toBeLessThan(0.0001);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

            it('writes a string to StringValue and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);

                    $testString = 'Laravel OpcUA test ' . time();
                    $statusCode = $client->write($nodeId, $testString, BuiltinType::String);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->getValue())->toBe($testString);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

            it('writes a ByteValue and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteValue']);

                    $statusCode = $client->write($nodeId, 200, BuiltinType::Byte);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->getValue())->toBe(200);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

        });

        // ── WriteMulti ───────────────────────────────────────────────

        describe('WriteMulti', function () use ($factory) {

            it('writes multiple values in one call', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $intNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                    $strNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);

                    $results = $client->writeMulti([
                        ['nodeId' => $intNodeId, 'value' => 9999, 'type' => BuiltinType::Int32],
                        ['nodeId' => $strNodeId, 'value' => 'multi-write-test', 'type' => BuiltinType::String],
                    ]);

                    expect($results)->toHaveCount(2);
                    expect(StatusCode::isGood($results[0]))->toBeTrue();
                    expect(StatusCode::isGood($results[1]))->toBeTrue();

                    $dvInt = $client->read($intNodeId);
                    expect($dvInt->getValue())->toBe(9999);

                    $dvStr = $client->read($strNodeId);
                    expect($dvStr->getValue())->toBe('multi-write-test');
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

        });

        // ── Array writes ─────────────────────────────────────────────

        describe('Array writes', function () use ($factory) {

            it('writes an Int32 array and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'Int32Array']);

                    $values = [10, 20, 30, 40, 50];
                    $statusCode = $client->write($nodeId, $values, BuiltinType::Int32);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->getValue())->toBe($values);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

            it('writes a String array and reads it back', function () use ($factory) {
                $manager = TestHelper::$factory();
                try {
                    $client = $manager->connect();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'StringArray']);

                    $values = ['alpha', 'beta', 'gamma'];
                    $statusCode = $client->write($nodeId, $values, BuiltinType::String);
                    expect(StatusCode::isGood($statusCode))->toBeTrue();

                    $dv = $client->read($nodeId);
                    expect($dv->getValue())->toBe($values);
                } finally {
                    TestHelper::safeDisconnect('default', $manager);
                }
            })->group('integration');

        });

        // ── Read-only writes ─────────────────────────────────────────

        describe('Write to read-only variables', function () use ($factory) {

            it('fails to write to Boolean_RO', function () use ($factory) {
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

        });

    })->group('integration');

}
