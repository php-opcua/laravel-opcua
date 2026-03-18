<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\Subscriptions\DataChangeNotification;
use Gianfriaur\OpcuaLaravel\Subscriptions\EventNotification;
use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionWorker;
use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;

class IntegrationDataChangeJob
{
    public function __construct(public readonly DataChangeNotification $notification) {}
}

class IntegrationEventJob
{
    public function __construct(public readonly EventNotification $notification) {}
}

function discoverNodeIdString(OpcUaClientInterface $client, array $path): string
{
    return TestHelper::browseToNode($client, $path)->toString();
}

function pollWorker(SubscriptionWorker $worker, int $maxCycles, int $usleepBetween = 500_000): array
{
    $setupMethod = new ReflectionMethod($worker, 'setup');
    $setupMethod->invoke($worker);

    $clientsProp = new ReflectionProperty($worker, 'clients');
    $acksProp = new ReflectionProperty($worker, 'pendingAcks');
    $handleMethod = new ReflectionMethod($worker, 'handleNotifications');

    $dispatched = [];

    for ($i = 0; $i < $maxCycles; $i++) {
        if ($i > 0) {
            usleep($usleepBetween);
        }

        $clients = $clientsProp->getValue($worker);
        foreach ($clients as $connName => $client) {
            try {
                $acks = $acksProp->getValue($worker)[$connName] ?? [];
                $newAcks = $acksProp->getValue($worker);
                $newAcks[$connName] = [];
                $acksProp->setValue($worker, $newAcks);

                $pub = $client->publish($acks);

                if (!empty($pub['notifications'])) {
                    $handleMethod->invoke($worker, $pub);
                }

                if (isset($pub['subscriptionId'], $pub['sequenceNumber'])) {
                    $currentAcks = $acksProp->getValue($worker);
                    $currentAcks[$connName][] = [
                        'subscriptionId' => $pub['subscriptionId'],
                        'sequenceNumber' => $pub['sequenceNumber'],
                    ];
                    $acksProp->setValue($worker, $currentAcks);
                }
            } catch (\Throwable) {
            }
        }
    }

    $cleanupMethod = new ReflectionMethod($worker, 'cleanup');
    $cleanupMethod->invoke($worker);

    return $dispatched;
}

function makeTestWorker(OpcuaManager $manager, array $subscriptions, array &$dispatched): SubscriptionWorker
{
    return new SubscriptionWorker(
        manager: $manager,
        subscriptions: $subscriptions,
        dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
            $dispatched[] = ['job' => $job, 'queue' => $queue];
        },
    );
}


beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Subscription job dispatching (direct mode)', function () {

    it('dispatches data change jobs for Counter variable', function () {
        $manager = TestHelper::createDirectManager();
        $dispatched = [];

        try {
            $client = $manager->connect();
            $counterStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'Counter']);
            $manager->disconnect();

            $worker = makeTestWorker($manager, [
                'counter-test' => [
                    'connection' => 'default',
                    'publishing_interval' => 500.0,
                    'nodes' => [
                        ['node_id' => $counterStr, 'sampling_interval' => 500.0],
                    ],
                    'job' => IntegrationDataChangeJob::class,
                    'queue' => 'test-queue',
                ],
            ], $dispatched);

            pollWorker($worker, maxCycles: 5);

            expect($dispatched)->not->toBeEmpty();

            $first = $dispatched[0];
            expect($first['job'])->toBeInstanceOf(IntegrationDataChangeJob::class);
            expect($first['queue'])->toBe('test-queue');

            $dto = $first['job']->notification;
            expect($dto->subscriptionName)->toBe('counter-test');
            expect($dto->nodeId)->toBe($counterStr);
            expect($dto->value)->toBeInt();
            expect($dto->statusCode)->toBe(0);
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('dispatches data change jobs for multiple nodes', function () {
        $manager = TestHelper::createDirectManager();
        $dispatched = [];

        try {
            $client = $manager->connect();
            $counterStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'Counter']);
            $randomStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'Random']);
            $manager->disconnect();

            $worker = makeTestWorker($manager, [
                'multi-node' => [
                    'connection' => 'default',
                    'nodes' => [
                        ['node_id' => $counterStr],
                        ['node_id' => $randomStr],
                    ],
                    'job' => IntegrationDataChangeJob::class,
                ],
            ], $dispatched);

            pollWorker($worker, maxCycles: 5);

            expect($dispatched)->not->toBeEmpty();

            foreach ($dispatched as $d) {
                $dto = $d['job']->notification;
                if ($dto->nodeId === $counterStr) {
                    expect($dto->value)->toBeInt();
                }
                if ($dto->nodeId === $randomStr) {
                    expect($dto->value)->toBeNumeric();
                }
            }
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('dispatches jobs with valid timestamps', function () {
        $manager = TestHelper::createDirectManager();
        $dispatched = [];

        try {
            $client = $manager->connect();
            $counterStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'Counter']);
            $manager->disconnect();

            $worker = makeTestWorker($manager, [
                'ts-test' => [
                    'connection' => 'default',
                    'nodes' => [['node_id' => $counterStr]],
                    'job' => IntegrationDataChangeJob::class,
                ],
            ], $dispatched);

            pollWorker($worker, maxCycles: 5);

            expect($dispatched)->not->toBeEmpty();

            $dto = $dispatched[0]['job']->notification;
            if ($dto->sourceTimestamp !== null) {
                expect(strtotime($dto->sourceTimestamp))->not->toBeFalse();
            }
            if ($dto->serverTimestamp !== null) {
                expect(strtotime($dto->serverTimestamp))->not->toBeFalse();
            }
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('dispatches jobs for ad-hoc endpoint connection', function () {
        $manager = TestHelper::createDirectManager();
        $dispatched = [];

        try {
            $client = $manager->connect();
            $counterStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'Counter']);
            $manager->disconnect();

            $worker = makeTestWorker($manager, [
                'adhoc-test' => [
                    'endpoint' => TestHelper::ENDPOINT_NO_SECURITY,
                    'endpoint_config' => [],
                    'nodes' => [['node_id' => $counterStr]],
                    'job' => IntegrationDataChangeJob::class,
                ],
            ], $dispatched);

            pollWorker($worker, maxCycles: 5);

            expect($dispatched)->not->toBeEmpty();

            $dto = $dispatched[0]['job']->notification;
            expect($dto->subscriptionName)->toBe('adhoc-test');
            expect($dto->value)->toBeInt();
        } finally {
            TestHelper::safeDisconnect(null, $manager);
        }
    })->group('integration');

    it('dispatches jobs for Counter with incrementing values', function () {
        $manager = TestHelper::createDirectManager();
        $dispatched = [];

        try {
            $client = $manager->connect();
            $counterStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'Counter']);
            $manager->disconnect();

            $worker = makeTestWorker($manager, [
                'increment-test' => [
                    'connection' => 'default',
                    'publishing_interval' => 500.0,
                    'nodes' => [['node_id' => $counterStr, 'sampling_interval' => 500.0]],
                    'job' => IntegrationDataChangeJob::class,
                ],
            ], $dispatched);

            pollWorker($worker, maxCycles: 6, usleepBetween: 800_000);

            expect($dispatched)->not->toBeEmpty();

            if (count($dispatched) >= 2) {
                $values = array_map(fn($d) => $d['job']->notification->value, $dispatched);
                for ($i = 1; $i < count($values); $i++) {
                    expect($values[$i])->toBeGreaterThanOrEqual($values[$i - 1]);
                }
            }
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('dispatches jobs for SineWave with float values in range', function () {
        $manager = TestHelper::createDirectManager();
        $dispatched = [];

        try {
            $client = $manager->connect();
            $sineStr = discoverNodeIdString($client, ['TestServer', 'Dynamic', 'SineWave']);
            $manager->disconnect();

            $worker = makeTestWorker($manager, [
                'sine-test' => [
                    'connection' => 'default',
                    'nodes' => [['node_id' => $sineStr]],
                    'job' => IntegrationDataChangeJob::class,
                ],
            ], $dispatched);

            pollWorker($worker, maxCycles: 5);

            expect($dispatched)->not->toBeEmpty();

            foreach ($dispatched as $d) {
                $value = $d['job']->notification->value;
                expect($value)->toBeFloat();
                expect($value)->toBeGreaterThanOrEqual(-1.1);
                expect($value)->toBeLessThanOrEqual(1.1);
            }
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

})->group('integration');
