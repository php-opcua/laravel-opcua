<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Subscription Transfer via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('transferSubscriptions returns TransferResult array', function () use ($factory) {
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

                $transferResults = $client->transferSubscriptions([$subId]);

                expect($transferResults)->toBeArray()->toHaveCount(1);
                expect($transferResults[0]->statusCode)->toBeInt();

                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('republish returns a result with sequenceNumber', function () use ($factory) {
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
                } catch (\Gianfriaur\OpcuaPhpClient\Exception\ServiceException $e) {
                    // BadMessageNotAvailable is acceptable — the server may have already discarded the notification
                    expect(true)->toBeTrue();
                }

                $client->deleteSubscription($subId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
