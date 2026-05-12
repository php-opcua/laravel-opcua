<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Cache\FileCache;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\NodeId;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Issue #1 — cache serialization ({$mode} mode)", function () use ($factory, $mode) {

        /**
         * Regression test for issue #1:
         * https://github.com/php-opcua/laravel-opcua/issues/1
         * https://github.com/php-opcua/opcua-client/issues/1
         *
         * Laravel 13 sets 'serializable_classes' => false in config/cache.php,
         * which causes unserialize() to be called with ['allowed_classes' => false].
         *
         * The fix in opcua-client v4.1.1 wraps cached values as safe strings
         * inside cachedFetch(), so the PSR-16 backend only ever stores plain
         * strings that are immune to allowed_classes restrictions.
         *
         * This test uses a FileCache (which serializes to disk) and verifies
         * that browse results survive a full cache roundtrip across two
         * separate connections (simulating two HTTP requests).
         */
        it('browse results survive file cache roundtrip across connections', function () use ($factory) {
            $cacheDir = sys_get_temp_dir() . '/opcua-laravel-issue1-' . getmypid();
            $cache = new FileCache($cacheDir, 300);

            try {
                // First connection: cache miss, fetches from server, stores in cache
                $manager1 = TestHelper::$factory([
                    'connections' => [
                        'default' => [
                            'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                            'cache' => $cache,
                        ],
                    ],
                ]);

                $client1 = $manager1->connect();
                $refs1 = $client1->browse('i=85', useCache: true);
                expect($refs1)->toBeArray()->not->toBeEmpty();
                expect($refs1[0])->toBeInstanceOf(ReferenceDescription::class);
                $client1->disconnect();

                // Second connection: cache hit, reads from file cache
                $manager2 = TestHelper::$factory([
                    'connections' => [
                        'default' => [
                            'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                            'cache' => $cache,
                        ],
                    ],
                ]);

                $client2 = $manager2->connect();
                $refs2 = $client2->browse('i=85', useCache: true);

                expect($refs2)->toBeArray()->not->toBeEmpty();
                expect($refs2[0])->toBeInstanceOf(ReferenceDescription::class);

                foreach ($refs2 as $ref) {
                    expect($ref->nodeId)->toBeInstanceOf(NodeId::class);
                    expect($ref->browseName->name)->toBeString();
                    expect($ref->displayName->text)->toBeString();
                    expect($ref->isForward)->toBeBool();
                }

                $names = array_map(fn($r) => $r->browseName->name, $refs2);
                expect($names)->toContain('Server');

                $client2->disconnect();
            } finally {
                $cache->clear();
                if (is_dir($cacheDir)) {
                    @rmdir($cacheDir);
                }
                TestHelper::safeDisconnect('default', $manager1 ?? null);
                TestHelper::safeDisconnect('default', $manager2 ?? null);
            }
        })->group('integration');

        /**
         * Verifies that the wrapped cache value is a plain string that
         * survives unserialize() with allowed_classes=false (Laravel 13).
         *
         * Direct mode only: in managed mode the daemon handles caching
         * internally and the client-side FileCache is not populated.
         */
        it('cached value is a plain string immune to allowed_classes restriction', function () use ($factory, $mode) {
            if ($mode === 'managed') {
                $this->markTestSkipped('Managed mode caches via daemon, not client-side FileCache');
            }
            $cacheDir = sys_get_temp_dir() . '/opcua-laravel-issue1-str-' . getmypid();
            $cache = new FileCache($cacheDir, 300);

            try {
                $manager = TestHelper::$factory([
                    'connections' => [
                        'default' => [
                            'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                            'cache' => $cache,
                        ],
                    ],
                ]);

                $client = $manager->connect();
                $client->browse('i=85', useCache: true);

                $files = glob($cacheDir . '/*.cache') ?: [];
                expect($files)->not->toBeEmpty();

                foreach ($files as $file) {
                    $raw = @file_get_contents($file);
                    if ($raw === false || $raw === '') {
                        continue;
                    }
                    $entry = @unserialize($raw);
                    if (!is_array($entry) || !array_key_exists('value', $entry)) {
                        continue;
                    }
                    $value = $entry['value'];

                    $afterLaravel = unserialize(serialize($value), ['allowed_classes' => false]);
                    expect($afterLaravel)->toEqual($value);
                }

                $client->disconnect();
            } finally {
                $cache->clear();
                if (is_dir($cacheDir)) {
                    @rmdir($cacheDir);
                }
                TestHelper::safeDisconnect('default', $manager ?? null);
            }
        })->group('integration');

    })->group('integration');

}
