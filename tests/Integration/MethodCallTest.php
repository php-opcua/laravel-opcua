<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Method Call via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('calls Add(3.0, 4.0) and gets Good status', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($methodsNodeId);
                $addRef = TestHelper::findRefByName($refs, 'Add');
                expect($addRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $addRef->nodeId,
                    [
                        new Variant(BuiltinType::Double, 3.0),
                        new Variant(BuiltinType::Double, 4.0),
                    ],
                );

                expect($result->statusCode)->toBeInt();
                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('calls Multiply(5.0, 6.0) and gets Good status', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($methodsNodeId);
                $mulRef = TestHelper::findRefByName($refs, 'Multiply');
                expect($mulRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $mulRef->nodeId,
                    [
                        new Variant(BuiltinType::Double, 5.0),
                        new Variant(BuiltinType::Double, 6.0),
                    ],
                );

                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('calls Concatenate("hello", " world")', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($methodsNodeId);
                $concatRef = TestHelper::findRefByName($refs, 'Concatenate');
                expect($concatRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $concatRef->nodeId,
                    [
                        new Variant(BuiltinType::String, 'hello'),
                        new Variant(BuiltinType::String, ' world'),
                    ],
                );

                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('calls Reverse("abcdef")', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($methodsNodeId);
                $reverseRef = TestHelper::findRefByName($refs, 'Reverse');
                expect($reverseRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $reverseRef->nodeId,
                    [new Variant(BuiltinType::String, 'abcdef')],
                );

                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('calls Echo(42)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($methodsNodeId);
                $echoRef = TestHelper::findRefByName($refs, 'Echo');
                expect($echoRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $echoRef->nodeId,
                    [new Variant(BuiltinType::Int32, 42)],
                );

                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('calls Failing() and gets BadInternalError', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
                $refs = $client->browse($methodsNodeId);
                $failRef = TestHelper::findRefByName($refs, 'Failing');
                expect($failRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $failRef->nodeId,
                    [],
                );

                expect(StatusCode::isBad($result->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
