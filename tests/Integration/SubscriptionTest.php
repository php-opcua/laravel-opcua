<?php

declare(strict_types=1);

use PhpOpcua\LaravelOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Subscription via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('creates a subscription with valid subscriptionId', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                expect($sub->subscriptionId)->toBeInt()->toBeGreaterThan(0);
                expect($sub->revisedPublishingInterval)->toBeGreaterThan(0);

                $status = $client->deleteSubscription($sub->subscriptionId);
                expect(StatusCode::isGood($status))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('creates a monitored item on Counter variable', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $subId = $sub->subscriptionId;

                $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $results = $client->createMonitoredItems($subId, [
                    ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                ]);

                expect($results)->toHaveCount(1);
                expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();
                expect($results[0]->monitoredItemId)->toBeInt()->toBeGreaterThan(0);

                $client->deleteMonitoredItems($subId, [$results[0]->monitoredItemId]);
                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('publishes and gets a valid response structure', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $subId = $sub->subscriptionId;

                $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $monResults = $client->createMonitoredItems($subId, [
                    ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                ]);
                $monId = $monResults[0]->monitoredItemId;

                $pub = $client->publish();

                expect($pub->subscriptionId)->toBe($subId);
                expect($pub->sequenceNumber)->toBeInt();
                expect($pub->moreNotifications)->toBeBool();
                expect($pub->notifications)->toBeArray();

                $client->deleteMonitoredItems($subId, [$monId]);
                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('deletes a monitored item', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $subId = $sub->subscriptionId;

                $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $monResults = $client->createMonitoredItems($subId, [
                    ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                ]);
                $monId = $monResults[0]->monitoredItemId;

                $deleteResults = $client->deleteMonitoredItems($subId, [$monId]);
                expect($deleteResults)->toHaveCount(1);
                expect(StatusCode::isGood($deleteResults[0]))->toBeTrue();

                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('deletes a subscription', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $status = $client->deleteSubscription($sub->subscriptionId);
                expect(StatusCode::isGood($status))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('subscribes to multiple dynamic variables at once', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $subId = $sub->subscriptionId;

                $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $randomNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Random']);
                $sineNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'SineWave']);

                $monResults = $client->createMonitoredItems($subId, [
                    ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                    ['nodeId' => $randomNodeId, 'clientHandle' => 2],
                    ['nodeId' => $sineNodeId, 'clientHandle' => 3],
                ]);

                expect($monResults)->toHaveCount(3);
                foreach ($monResults as $result) {
                    expect(StatusCode::isGood($result->statusCode))->toBeTrue();
                    expect($result->monitoredItemId)->toBeInt()->toBeGreaterThan(0);
                }

                $monIds = array_map(fn($r) => $r->monitoredItemId, $monResults);
                $client->deleteMonitoredItems($subId, $monIds);
                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
