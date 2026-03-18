<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\Subscriptions\DataChangeNotification;
use Gianfriaur\OpcuaLaravel\Subscriptions\EventNotification;
use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionWorker;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

function makeWorkerConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'default' => 'default',
        'session_manager' => [
            'enabled' => false,
            'socket_path' => '/tmp/nonexistent.sock',
        ],
        'connections' => [
            'default' => [
                'endpoint' => 'opc.tcp://localhost:4840',
            ],
        ],
    ], $overrides);
}

// Dummy job classes for testing dispatch
class TestDataChangeJob
{
    public function __construct(public readonly DataChangeNotification $notification) {}
}

class TestEventJob
{
    public function __construct(public readonly EventNotification $notification) {}
}

function buildWorker(
    array $subscriptions,
    array $publishResponses,
    ?\Closure $dispatcher = null,
    bool $useConnectTo = false,
    ?string $connectToUrl = null,
    array $connectToConfig = [],
    ?\Closure $logger = null,
    array $extraClientExpectations = [],
): SubscriptionWorker {
    $mockClient = Mockery::mock(OpcUaClientInterface::class);

    $mockClient->shouldReceive('createSubscription')
        ->andReturn([
            'subscriptionId' => 1,
            'revisedPublishingInterval' => 500.0,
            'revisedLifetimeCount' => 2400,
            'revisedMaxKeepAliveCount' => 10,
        ]);

    foreach ($extraClientExpectations as $callback) {
        $callback($mockClient);
    }

    if (!isset($extraClientExpectations['createMonitoredItems'])) {
        $mockClient->shouldReceive('createMonitoredItems')
            ->andReturn([['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1]]);
    }
    if (!isset($extraClientExpectations['createEventMonitoredItem'])) {
        $mockClient->shouldReceive('createEventMonitoredItem')
            ->andReturn(['statusCode' => 0, 'monitoredItemId' => 20, 'revisedSamplingInterval' => 0.0, 'revisedQueueSize' => 0]);
    }

    $mockClient->shouldReceive('deleteMonitoredItems')->zeroOrMoreTimes();
    $mockClient->shouldReceive('deleteSubscription')->zeroOrMoreTimes();

    $manager = Mockery::mock(OpcuaManager::class, [makeWorkerConfig()])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    if ($useConnectTo) {
        $manager->shouldReceive('connectTo')->andReturn($mockClient);
        $manager->shouldNotReceive('connect');
    } else {
        $manager->shouldReceive('connect')->andReturn($mockClient);
    }
    $manager->shouldReceive('disconnectAll');
    $manager->shouldReceive('getDefaultConnection')->andReturn('default');

    $worker = new SubscriptionWorker($manager, $subscriptions, $logger, $dispatcher);

    $publishIdx = 0;
    $mockClient->shouldReceive('publish')
        ->andReturnUsing(function () use (&$publishIdx, $publishResponses, $worker) {
            if ($publishIdx < count($publishResponses)) {
                $response = $publishResponses[$publishIdx];
                $publishIdx++;
                return $response;
            }
            $worker->stop();
            return [
                'subscriptionId' => 1,
                'sequenceNumber' => 999,
                'moreNotifications' => false,
                'notifications' => [],
            ];
        });

    return $worker;
}

describe('SubscriptionWorker', function () {

    describe('setup — data_change subscriptions', function () {

        it('creates a subscription and monitored items on the client', function () {
            $worker = buildWorker(
                subscriptions: [
                    'test-sub' => [
                        'connection' => 'default',
                        'nodes' => [
                            ['node_id' => 'ns=2;i=1001'],
                            ['node_id' => 'ns=2;i=1002'],
                        ],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [],
                extraClientExpectations: [
                    'createMonitoredItems' => function ($mock) {
                        $mock->shouldReceive('createMonitoredItems')
                            ->once()
                            ->withArgs(function (int $subId, array $items) {
                                return $subId === 1
                                    && count($items) === 2
                                    && $items[0]['nodeId']->getIdentifier() === 1001
                                    && $items[0]['nodeId']->getNamespaceIndex() === 2
                                    && $items[1]['nodeId']->getIdentifier() === 1002;
                            })
                            ->andReturn([
                                ['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                                ['statusCode' => 0, 'monitoredItemId' => 11, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                            ]);
                    },
                ],
            );

            $worker->run();
            expect(true)->toBeTrue();
        });

        it('uses custom publishing interval', function () {
            $mockClient = Mockery::mock(OpcUaClientInterface::class);
            $mockClient->shouldReceive('createSubscription')
                ->once()
                ->with(1000.0)
                ->andReturn(['subscriptionId' => 1, 'revisedPublishingInterval' => 1000.0, 'revisedLifetimeCount' => 2400, 'revisedMaxKeepAliveCount' => 10]);
            $mockClient->shouldReceive('createMonitoredItems')
                ->andReturn([['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1]]);
            $mockClient->shouldReceive('deleteMonitoredItems')->zeroOrMoreTimes();
            $mockClient->shouldReceive('deleteSubscription')->zeroOrMoreTimes();

            $manager = Mockery::mock(OpcuaManager::class, [makeWorkerConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('connect')->andReturn($mockClient);
            $manager->shouldReceive('disconnectAll');
            $manager->shouldReceive('getDefaultConnection')->andReturn('default');

            $subscriptions = [
                'test' => [
                    'publishing_interval' => 1000.0,
                    'nodes' => [['node_id' => 'i=85']],
                    'job' => TestDataChangeJob::class,
                ],
            ];

            $worker = new SubscriptionWorker($manager, $subscriptions);
            $mockClient->shouldReceive('publish')->andReturnUsing(function () use ($worker) {
                $worker->stop();
                return ['subscriptionId' => 1, 'sequenceNumber' => 1, 'moreNotifications' => false, 'notifications' => []];
            });

            $worker->run();
            expect(true)->toBeTrue();
        });

        it('logs warning when monitored item creation fails', function () {
            $logs = [];

            $worker = buildWorker(
                subscriptions: [
                    'test' => [
                        'nodes' => [['node_id' => 'ns=2;i=9999']],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [],
                logger: function (string $msg, string $level) use (&$logs) {
                    $logs[] = ['msg' => $msg, 'level' => $level];
                },
                extraClientExpectations: [
                    'createMonitoredItems' => function ($mock) {
                        $mock->shouldReceive('createMonitoredItems')
                            ->andReturn([['statusCode' => 0x80000000, 'monitoredItemId' => 0, 'revisedSamplingInterval' => 0.0, 'revisedQueueSize' => 0]]);
                    },
                ],
            );

            $worker->run();

            $warningLogs = array_filter($logs, fn($l) => $l['level'] === 'warning');
            expect($warningLogs)->not->toBeEmpty();
        });
    });

    describe('setup — event subscriptions', function () {

        it('creates an event monitored item on the client', function () {
            $worker = buildWorker(
                subscriptions: [
                    'alarms' => [
                        'type' => 'event',
                        'node_id' => 'i=2253',
                        'job' => TestEventJob::class,
                    ],
                ],
                publishResponses: [],
                extraClientExpectations: [
                    'createEventMonitoredItem' => function ($mock) {
                        $mock->shouldReceive('createEventMonitoredItem')
                            ->once()
                            ->withArgs(function (int $subId, $nodeId, array $fields, int $handle) {
                                return $subId === 1
                                    && $nodeId->getIdentifier() === 2253
                                    && $fields === ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'];
                            })
                            ->andReturn(['statusCode' => 0, 'monitoredItemId' => 20, 'revisedSamplingInterval' => 0.0, 'revisedQueueSize' => 0]);
                    },
                ],
            );

            $worker->run();
            expect(true)->toBeTrue();
        });

        it('uses custom select_fields', function () {
            $worker = buildWorker(
                subscriptions: [
                    'alarms' => [
                        'type' => 'event',
                        'node_id' => 'i=2253',
                        'select_fields' => ['EventId', 'Severity'],
                        'job' => TestEventJob::class,
                    ],
                ],
                publishResponses: [],
                extraClientExpectations: [
                    'createEventMonitoredItem' => function ($mock) {
                        $mock->shouldReceive('createEventMonitoredItem')
                            ->once()
                            ->withArgs(fn($s, $n, $f) => $f === ['EventId', 'Severity'])
                            ->andReturn(['statusCode' => 0, 'monitoredItemId' => 20, 'revisedSamplingInterval' => 0.0, 'revisedQueueSize' => 0]);
                    },
                ],
            );

            $worker->run();
            expect(true)->toBeTrue();
        });
    });

    describe('notification handling — data change', function () {

        it('dispatches a data change job with correct DTO', function () {
            $dispatched = [];

            $dataValue = Mockery::mock(DataValue::class);
            $dataValue->shouldReceive('getValue')->andReturn(42.5);
            $dataValue->shouldReceive('getStatusCode')->andReturn(0);
            $dataValue->shouldReceive('getSourceTimestamp')->andReturn(new \DateTimeImmutable('2026-01-15T10:30:00+00:00'));
            $dataValue->shouldReceive('getServerTimestamp')->andReturn(new \DateTimeImmutable('2026-01-15T10:30:01+00:00'));

            $worker = buildWorker(
                subscriptions: [
                    'temp-monitor' => [
                        'nodes' => [['node_id' => 'ns=2;i=1001']],
                        'job' => TestDataChangeJob::class,
                        'queue' => 'opcua',
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            ['type' => 'DataChange', 'clientHandle' => 1, 'dataValue' => $dataValue],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = ['job' => $job, 'queue' => $queue];
                },
            );

            $worker->run();

            expect($dispatched)->toHaveCount(1);

            $job = $dispatched[0]['job'];
            expect($job)->toBeInstanceOf(TestDataChangeJob::class);
            expect($dispatched[0]['queue'])->toBe('opcua');

            $dto = $job->notification;
            expect($dto)->toBeInstanceOf(DataChangeNotification::class);
            expect($dto->subscriptionName)->toBe('temp-monitor');
            expect($dto->nodeId)->toBe('ns=2;i=1001');
            expect($dto->value)->toBe(42.5);
            expect($dto->statusCode)->toBe(0);
            expect($dto->sourceTimestamp)->toBe('2026-01-15T10:30:00+00:00');
            expect($dto->serverTimestamp)->toBe('2026-01-15T10:30:01+00:00');
        });

        it('dispatches multiple jobs for multiple notifications', function () {
            $dispatched = [];

            $dv1 = Mockery::mock(DataValue::class);
            $dv1->shouldReceive('getValue')->andReturn(10);
            $dv1->shouldReceive('getStatusCode')->andReturn(0);
            $dv1->shouldReceive('getSourceTimestamp')->andReturn(null);
            $dv1->shouldReceive('getServerTimestamp')->andReturn(null);

            $dv2 = Mockery::mock(DataValue::class);
            $dv2->shouldReceive('getValue')->andReturn(20);
            $dv2->shouldReceive('getStatusCode')->andReturn(0);
            $dv2->shouldReceive('getSourceTimestamp')->andReturn(null);
            $dv2->shouldReceive('getServerTimestamp')->andReturn(null);

            $worker = buildWorker(
                subscriptions: [
                    'multi' => [
                        'nodes' => [
                            ['node_id' => 'ns=2;i=1001'],
                            ['node_id' => 'ns=2;i=1002'],
                        ],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            ['type' => 'DataChange', 'clientHandle' => 1, 'dataValue' => $dv1],
                            ['type' => 'DataChange', 'clientHandle' => 2, 'dataValue' => $dv2],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = $job;
                },
                extraClientExpectations: [
                    'createMonitoredItems' => function ($mock) {
                        $mock->shouldReceive('createMonitoredItems')
                            ->andReturn([
                                ['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                                ['statusCode' => 0, 'monitoredItemId' => 11, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                            ]);
                    },
                ],
            );

            $worker->run();

            expect($dispatched)->toHaveCount(2);
            expect($dispatched[0]->notification->nodeId)->toBe('ns=2;i=1001');
            expect($dispatched[0]->notification->value)->toBe(10);
            expect($dispatched[1]->notification->nodeId)->toBe('ns=2;i=1002');
            expect($dispatched[1]->notification->value)->toBe(20);
        });

        it('ignores notifications with unknown clientHandle', function () {
            $dispatched = [];

            $worker = buildWorker(
                subscriptions: [
                    'test' => [
                        'nodes' => [['node_id' => 'ns=2;i=1001']],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            ['type' => 'DataChange', 'clientHandle' => 999, 'dataValue' => null],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = $job;
                },
            );

            $worker->run();

            expect($dispatched)->toBeEmpty();
        });

        it('handles null dataValue gracefully', function () {
            $dispatched = [];

            $worker = buildWorker(
                subscriptions: [
                    'test' => [
                        'nodes' => [['node_id' => 'ns=2;i=1001']],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            ['type' => 'DataChange', 'clientHandle' => 1, 'dataValue' => null],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = $job;
                },
            );

            $worker->run();

            expect($dispatched)->toHaveCount(1);
            expect($dispatched[0]->notification->value)->toBeNull();
            expect($dispatched[0]->notification->statusCode)->toBe(0);
            expect($dispatched[0]->notification->sourceTimestamp)->toBeNull();
        });
    });

    describe('notification handling — events', function () {

        it('dispatches an event job with associative field names', function () {
            $dispatched = [];

            $v0 = Mockery::mock(Variant::class);
            $v0->shouldReceive('getValue')->andReturn('event-id-123');
            $v1 = Mockery::mock(Variant::class);
            $v1->shouldReceive('getValue')->andReturn('HighTemperature');
            $v2 = Mockery::mock(Variant::class);
            $v2->shouldReceive('getValue')->andReturn(800);

            $worker = buildWorker(
                subscriptions: [
                    'alarms' => [
                        'type' => 'event',
                        'node_id' => 'i=2253',
                        'select_fields' => ['EventId', 'SourceName', 'Severity'],
                        'job' => TestEventJob::class,
                        'queue' => 'events',
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            [
                                'type' => 'Event',
                                'clientHandle' => 1,
                                'eventFields' => [$v0, $v1, $v2],
                            ],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = ['job' => $job, 'queue' => $queue];
                },
            );

            $worker->run();

            expect($dispatched)->toHaveCount(1);

            $job = $dispatched[0]['job'];
            expect($job)->toBeInstanceOf(TestEventJob::class);
            expect($dispatched[0]['queue'])->toBe('events');

            $dto = $job->notification;
            expect($dto)->toBeInstanceOf(EventNotification::class);
            expect($dto->subscriptionName)->toBe('alarms');
            expect($dto->nodeId)->toBe('i=2253');
            expect($dto->eventFields)->toBe([
                'EventId' => 'event-id-123',
                'SourceName' => 'HighTemperature',
                'Severity' => 800,
            ]);
        });

        it('handles raw scalar values in eventFields (not Variant objects)', function () {
            $dispatched = [];

            $worker = buildWorker(
                subscriptions: [
                    'alarms' => [
                        'type' => 'event',
                        'node_id' => 'i=2253',
                        'select_fields' => ['EventId', 'SourceName', 'Severity'],
                        'job' => TestEventJob::class,
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            [
                                'type' => 'Event',
                                'clientHandle' => 1,
                                'eventFields' => ['raw-id', 'raw-source', 500],
                            ],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = $job;
                },
            );

            $worker->run();

            expect($dispatched)->toHaveCount(1);
            expect($dispatched[0]->notification->eventFields)->toBe([
                'EventId' => 'raw-id',
                'SourceName' => 'raw-source',
                'Severity' => 500,
            ]);
        });
    });

    describe('ad-hoc endpoint connections', function () {

        it('uses connectTo for subscriptions with endpoint key', function () {
            $mockClient = Mockery::mock(OpcUaClientInterface::class);
            $mockClient->shouldReceive('createSubscription')
                ->andReturn(['subscriptionId' => 1, 'revisedPublishingInterval' => 500.0, 'revisedLifetimeCount' => 2400, 'revisedMaxKeepAliveCount' => 10]);
            $mockClient->shouldReceive('createMonitoredItems')
                ->andReturn([['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1]]);
            $mockClient->shouldReceive('deleteMonitoredItems')->zeroOrMoreTimes();
            $mockClient->shouldReceive('deleteSubscription')->zeroOrMoreTimes();

            $manager = Mockery::mock(OpcuaManager::class, [makeWorkerConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('connectTo')
                ->once()
                ->with(
                    'opc.tcp://10.0.0.50:4840',
                    ['username' => 'admin', 'password' => 'pass'],
                    'adhoc:opc.tcp://10.0.0.50:4840',
                )
                ->andReturn($mockClient);
            $manager->shouldNotReceive('connect');
            $manager->shouldReceive('disconnectAll');
            $manager->shouldReceive('getDefaultConnection')->andReturn('default');

            $subscriptions = [
                'remote' => [
                    'endpoint' => 'opc.tcp://10.0.0.50:4840',
                    'endpoint_config' => ['username' => 'admin', 'password' => 'pass'],
                    'nodes' => [['node_id' => 'ns=2;i=1001']],
                    'job' => TestDataChangeJob::class,
                ],
            ];

            $worker = new SubscriptionWorker($manager, $subscriptions);
            $mockClient->shouldReceive('publish')->andReturnUsing(function () use ($worker) {
                $worker->stop();
                return ['subscriptionId' => 1, 'sequenceNumber' => 1, 'moreNotifications' => false, 'notifications' => []];
            });

            $worker->run();
            expect(true)->toBeTrue();
        });

        it('groups subscriptions with same endpoint on one connection', function () {
            $mockClient = Mockery::mock(OpcUaClientInterface::class);
            $mockClient->shouldReceive('createSubscription')
                ->twice() // Two subscriptions, one connection
                ->andReturn(['subscriptionId' => 1, 'revisedPublishingInterval' => 500.0, 'revisedLifetimeCount' => 2400, 'revisedMaxKeepAliveCount' => 10]);
            $mockClient->shouldReceive('createMonitoredItems')
                ->andReturn([['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1]]);
            $mockClient->shouldReceive('createEventMonitoredItem')
                ->andReturn(['statusCode' => 0, 'monitoredItemId' => 20, 'revisedSamplingInterval' => 0.0, 'revisedQueueSize' => 0]);
            $mockClient->shouldReceive('deleteMonitoredItems')->zeroOrMoreTimes();
            $mockClient->shouldReceive('deleteSubscription')->zeroOrMoreTimes();

            $manager = Mockery::mock(OpcuaManager::class, [makeWorkerConfig()])
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('connectTo')
                ->once()
                ->andReturn($mockClient);
            $manager->shouldReceive('disconnectAll');
            $manager->shouldReceive('getDefaultConnection')->andReturn('default');

            $subscriptions = [
                'data' => [
                    'endpoint' => 'opc.tcp://10.0.0.50:4840',
                    'nodes' => [['node_id' => 'ns=2;i=1001']],
                    'job' => TestDataChangeJob::class,
                ],
                'events' => [
                    'endpoint' => 'opc.tcp://10.0.0.50:4840',
                    'type' => 'event',
                    'node_id' => 'i=2253',
                    'job' => TestEventJob::class,
                ],
            ];

            $worker = new SubscriptionWorker($manager, $subscriptions);
            $mockClient->shouldReceive('publish')->andReturnUsing(function () use ($worker) {
                $worker->stop();
                return ['subscriptionId' => 1, 'sequenceNumber' => 1, 'moreNotifications' => false, 'notifications' => []];
            });

            $worker->run();
            expect(true)->toBeTrue();
        });
    });

    describe('cleanup', function () {

        it('deletes all monitored items and subscriptions on shutdown', function () {
            $worker = buildWorker(
                subscriptions: [
                    'test' => [
                        'nodes' => [['node_id' => 'ns=2;i=1001'], ['node_id' => 'ns=2;i=1002']],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [],
                extraClientExpectations: [
                    'createMonitoredItems' => function ($mock) {
                        $mock->shouldReceive('createMonitoredItems')
                            ->andReturn([
                                ['statusCode' => 0, 'monitoredItemId' => 10, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                                ['statusCode' => 0, 'monitoredItemId' => 11, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                            ]);
                        $mock->shouldReceive('deleteMonitoredItems')
                            ->once()
                            ->with(1, [10, 11]);
                        $mock->shouldReceive('deleteSubscription')
                            ->once()
                            ->with(1);
                    },
                ],
            );

            $worker->run();
            // Mockery verifies expectations
            expect(true)->toBeTrue();
        });
    });

    describe('queue assignment', function () {

        it('dispatches with null queue when queue is not set', function () {
            $dispatched = [];

            $dv = Mockery::mock(DataValue::class);
            $dv->shouldReceive('getValue')->andReturn(1);
            $dv->shouldReceive('getStatusCode')->andReturn(0);
            $dv->shouldReceive('getSourceTimestamp')->andReturn(null);
            $dv->shouldReceive('getServerTimestamp')->andReturn(null);

            $worker = buildWorker(
                subscriptions: [
                    'test' => [
                        'nodes' => [['node_id' => 'ns=2;i=1001']],
                        'job' => TestDataChangeJob::class,
                    ],
                ],
                publishResponses: [
                    [
                        'subscriptionId' => 1,
                        'sequenceNumber' => 1,
                        'moreNotifications' => false,
                        'notifications' => [
                            ['type' => 'DataChange', 'clientHandle' => 1, 'dataValue' => $dv],
                        ],
                    ],
                ],
                dispatcher: function (object $job, ?string $queue) use (&$dispatched) {
                    $dispatched[] = ['job' => $job, 'queue' => $queue];
                },
            );

            $worker->run();

            expect($dispatched)->toHaveCount(1);
            expect($dispatched[0]['queue'])->toBeNull();
        });
    });
});
