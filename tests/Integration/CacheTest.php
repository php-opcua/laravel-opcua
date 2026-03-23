<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Cache\InMemoryCache;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Cache via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('sets and gets a cache driver', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connection();
                $cache = new InMemoryCache(300);

                $client->setCache($cache);

                expect($client->getCache())->toBe($cache);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('browse results are cached on second call', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $client->setCache(new InMemoryCache(300));

                $refs1 = $client->browse('i=85', useCache: true);
                $refs2 = $client->browse('i=85', useCache: true);

                expect($refs1)->toHaveCount(count($refs2));
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('invalidateCache clears a specific node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $client->setCache(new InMemoryCache(300));

                $client->browse('i=85', useCache: true);
                $client->invalidateCache('i=85');
                $refs = $client->browse('i=85', useCache: true);

                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('flushCache clears all cached data', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $client->setCache(new InMemoryCache(300));

                $client->browse('i=85', useCache: true);
                $client->flushCache();
                $refs = $client->browse('i=85', useCache: true);

                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('useCache=false bypasses the cache', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $client->setCache(new InMemoryCache(300));

                $refs = $client->browse('i=85', useCache: false);

                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('disables caching with setCache(null)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connection();
                $client->setCache(null);

                expect($client->getCache())->toBeNull();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
