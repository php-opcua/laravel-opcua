<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Type Discovery via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('discoverDataTypes returns the number of discovered types', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $count = $client->discoverDataTypes();

                expect($count)->toBeInt()->toBeGreaterThanOrEqual(0);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('getExtensionObjectRepository returns an instance', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connection();

                $repo = $client->getExtensionObjectRepository();

                expect($repo)->toBeInstanceOf(ExtensionObjectRepository::class);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
