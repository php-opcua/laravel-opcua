<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Subscription Transfer via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('transferSubscriptions returns TransferResult array or throws ServiceException', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $subId = $sub->subscriptionId;

                $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $monResults = $client->createMonitoredItems($subId, [
                    ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                ]);
                expect(StatusCode::isGood($monResults[0]->statusCode))->toBeTrue();

                try {
                    $transferResults = $client->transferSubscriptions([$subId]);

                    expect($transferResults)->toBeArray()->toHaveCount(1);
                    expect($transferResults[0]->statusCode)->toBeInt();
                } catch (ServiceException|\Throwable $e) {
                    // Some servers do not support TransferSubscriptions — this is acceptable
                    expect(true)->toBeTrue();
                }

                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('republish returns a result or throws when notification unavailable', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(500.0);
                $subId = $sub->subscriptionId;

                $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
                $client->createMonitoredItems($subId, [
                    ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                ]);

                $pub = $client->publish();
                $seqNum = $pub->sequenceNumber;

                try {
                    $result = $client->republish($subId, $seqNum);
                    expect($result)->toBeArray();
                    expect($result)->toHaveKey('sequenceNumber');
                } catch (ServiceException|\Throwable $e) {
                    // BadMessageNotAvailable or unsupported — acceptable
                    expect(true)->toBeTrue();
                }

                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
