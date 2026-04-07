<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\LaravelOpcua\OpcuaManager;

describe('Opcua Facade', function () {

    it('resolves to OpcuaManager class', function () {
        $accessor = (new ReflectionMethod(Opcua::class, 'getFacadeAccessor'))->invoke(null);

        expect($accessor)->toBe(OpcuaManager::class);
    });
});
