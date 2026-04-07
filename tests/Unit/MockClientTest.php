<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

describe('MockClient integration with OpcuaManager', function () {

    it('can be injected into OpcuaManager connections via reflection', function () {
        $mock = MockClient::create()
            ->onRead('i=2259', fn() => DataValue::ofInt32(0));

        $manager = new OpcuaManager([
            'default' => 'default',
            'session_manager' => ['enabled' => false, 'socket_path' => '/tmp/nonexistent.sock'],
            'connections' => ['default' => ['endpoint' => 'opc.tcp://localhost:4840']],
        ]);

        $ref = new ReflectionProperty($manager, 'connections');
        $ref->setValue($manager, ['default' => $mock]);

        $dv = $manager->connection('default')->read('i=2259');

        expect($dv->statusCode)->toBe(StatusCode::Good);
        expect($dv->getValue())->toBe(0);
        expect($mock->callCount('read'))->toBe(1);
    });

    it('tracks all calls made through the manager proxy', function () {
        $mock = MockClient::create()
            ->onRead('i=2259', fn() => DataValue::ofInt32(42))
            ->onRead('i=2256', fn() => DataValue::ofInt32(99));

        $manager = new OpcuaManager([
            'default' => 'default',
            'session_manager' => ['enabled' => false, 'socket_path' => '/tmp/nonexistent.sock'],
            'connections' => ['default' => ['endpoint' => 'opc.tcp://localhost:4840']],
        ]);

        $ref = new ReflectionProperty($manager, 'connections');
        $ref->setValue($manager, ['default' => $mock]);

        $manager->read('i=2259');
        $manager->read('i=2256');

        expect($mock->callCount('read'))->toBe(2);
        expect($mock->getCalls())->toHaveCount(2);
    });

    it('uses DataValue factory methods for convenient test setup', function () {
        $dvInt = DataValue::ofInt32(42);
        expect($dvInt->statusCode)->toBe(StatusCode::Good);
        expect($dvInt->getValue())->toBe(42);

        $dvStr = DataValue::ofString('hello');
        expect($dvStr->statusCode)->toBe(StatusCode::Good);
        expect($dvStr->getValue())->toBe('hello');

        $dvBool = DataValue::ofBoolean(true);
        expect($dvBool->statusCode)->toBe(StatusCode::Good);
        expect($dvBool->getValue())->toBeTrue();

        $dvDouble = DataValue::ofDouble(3.14);
        expect($dvDouble->statusCode)->toBe(StatusCode::Good);
        expect(abs($dvDouble->getValue() - 3.14))->toBeLessThan(0.001);
    });

    it('creates bad DataValues for error scenarios', function () {
        $dv = DataValue::bad(StatusCode::BadNodeIdUnknown);

        expect(StatusCode::isBad($dv->statusCode))->toBeTrue();
    });
});
