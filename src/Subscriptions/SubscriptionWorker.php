<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Subscriptions;

use Closure;
use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class SubscriptionWorker
{
    private bool $running = true;

    /** @var array<int, array{subscriptionName: string, nodeId: string, job: string, queue: ?string, type: string, selectFields?: string[]}> */
    private array $handleMap = [];

    /** @var array<int, string> subscriptionId => subscriptionName */
    private array $subscriptionMap = [];

    /** @var array<string, OpcUaClientInterface> connectionName => client */
    private array $clients = [];

    /** @var array<string, int[]> connectionName => subscriptionIds */
    private array $connectionSubscriptions = [];

    /** @var array<string, array<array{subscriptionId: int, sequenceNumber: int}>> connectionName => acknowledgements */
    private array $pendingAcks = [];

    /** @var array<int, int[]> subscriptionId => monitoredItemIds (for cleanup) */
    private array $monitoredItems = [];

    private int $nextHandle = 1;

    /**
     * @param OpcuaManager $manager
     * @param array<string, array> $subscriptions
     * @param Closure|null $logger
     * @param Closure|null $dispatcher
     */
    public function __construct(
        private readonly OpcuaManager $manager,
        private readonly array        $subscriptions,
        private readonly ?Closure     $logger = null,
        private readonly ?Closure     $dispatcher = null,
    ) {}

    public function run(): void
    {
        $this->setup();
        $this->poll();
        $this->cleanup();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function setup(): void
    {
        $grouped = [];
        $adHocMeta = [];

        foreach ($this->subscriptions as $name => $config) {
            if (!empty($config['endpoint'])) {
                $key = 'adhoc:' . $config['endpoint'];
                $adHocMeta[$key] = [
                    'endpoint' => $config['endpoint'],
                    'endpoint_config' => $config['endpoint_config'] ?? [],
                ];
            } else {
                $key = $config['connection'] ?? $this->manager->getDefaultConnection();
            }

            $grouped[$key][$name] = $config;
        }

        foreach ($grouped as $connectionKey => $subs) {
            if (isset($adHocMeta[$connectionKey])) {
                $meta = $adHocMeta[$connectionKey];
                $client = $this->manager->connectTo(
                    $meta['endpoint'],
                    $meta['endpoint_config'],
                    $connectionKey,
                );
                $this->log("Ad-hoc connection to {$meta['endpoint']}");
            } else {
                $client = $this->manager->connect($connectionKey);
            }

            $this->clients[$connectionKey] = $client;
            $this->pendingAcks[$connectionKey] = [];

            foreach ($subs as $name => $config) {
                $type = $config['type'] ?? 'data_change';
                $publishingInterval = (float) ($config['publishing_interval'] ?? 500.0);

                $sub = $client->createSubscription($publishingInterval);
                $subscriptionId = $sub['subscriptionId'];
                $this->subscriptionMap[$subscriptionId] = $name;
                $this->connectionSubscriptions[$connectionKey][] = $subscriptionId;
                $this->monitoredItems[$subscriptionId] = [];

                if ($type === 'event') {
                    $this->setupEventSubscription($client, $subscriptionId, $name, $config);
                } else {
                    $this->setupDataChangeSubscription($client, $subscriptionId, $name, $config);
                }

                $this->log("Subscription '{$name}' created (id: {$subscriptionId}, type: {$type})");
            }
        }
    }

    private function setupDataChangeSubscription(
        OpcUaClientInterface $client,
        int $subscriptionId,
        string $name,
        array $config,
    ): void {
        $nodes = $config['nodes'] ?? [];
        $items = [];

        foreach ($nodes as $nodeConfig) {
            $handle = $this->nextHandle++;
            $nodeId = NodeId::parse($nodeConfig['node_id']);

            $items[] = [
                'nodeId' => $nodeId,
                'clientHandle' => $handle,
                'samplingInterval' => (float) ($nodeConfig['sampling_interval'] ?? -1),
            ];

            $this->handleMap[$handle] = [
                'subscriptionName' => $name,
                'nodeId' => $nodeConfig['node_id'],
                'job' => $config['job'],
                'queue' => $config['queue'] ?? null,
                'type' => 'data_change',
            ];
        }

        if (empty($items)) {
            return;
        }

        $results = $client->createMonitoredItems($subscriptionId, $items);
        foreach ($results as $result) {
            if ($result['statusCode'] === 0) {
                $this->monitoredItems[$subscriptionId][] = $result['monitoredItemId'];
            } else {
                $this->log("Failed to create monitored item (status: {$result['statusCode']})", 'warning');
            }
        }
    }

    private function setupEventSubscription(
        OpcUaClientInterface $client,
        int $subscriptionId,
        string $name,
        array $config,
    ): void {
        $handle = $this->nextHandle++;
        $nodeId = NodeId::parse($config['node_id']);
        $selectFields = $config['select_fields'] ?? [
            'EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity',
        ];

        $this->handleMap[$handle] = [
            'subscriptionName' => $name,
            'nodeId' => $config['node_id'],
            'job' => $config['job'],
            'queue' => $config['queue'] ?? null,
            'type' => 'event',
            'selectFields' => $selectFields,
        ];

        $result = $client->createEventMonitoredItem(
            $subscriptionId,
            $nodeId,
            $selectFields,
            $handle,
        );

        if ($result['statusCode'] === 0) {
            $this->monitoredItems[$subscriptionId][] = $result['monitoredItemId'];
        } else {
            $this->log("Failed to create event monitored item (status: {$result['statusCode']})", 'warning');
        }
    }

    private function poll(): void
    {
        while ($this->running) {
            foreach ($this->clients as $connectionName => $client) {
                if (!$this->running) {
                    break;
                }

                try {
                    $acks = $this->pendingAcks[$connectionName];
                    $this->pendingAcks[$connectionName] = [];

                    $pub = $client->publish($acks);

                    if (!empty($pub['notifications'])) {
                        $this->handleNotifications($pub);
                    }

                    if (isset($pub['subscriptionId'], $pub['sequenceNumber'])) {
                        $this->pendingAcks[$connectionName][] = [
                            'subscriptionId' => $pub['subscriptionId'],
                            'sequenceNumber' => $pub['sequenceNumber'],
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->log("Publish error on '{$connectionName}': {$e->getMessage()}", 'error');
                    usleep(1_000_000); // 1s backoff on error
                }
            }
        }
    }

    private function handleNotifications(array $pub): void
    {
        foreach ($pub['notifications'] as $notification) {
            $clientHandle = $notification['clientHandle'] ?? null;
            $mapping = $this->handleMap[$clientHandle] ?? null;

            if ($mapping === null) {
                continue;
            }

            $type = $notification['type'] ?? 'DataChange';

            if ($type === 'DataChange') {
                $this->dispatchDataChangeJob($mapping, $notification);
            } elseif ($type === 'Event') {
                $this->dispatchEventJob($mapping, $notification);
            }
        }
    }

    private function dispatchDataChangeJob(array $mapping, array $notification): void
    {
        /** @var DataValue|null $dataValue */
        $dataValue = $notification['dataValue'] ?? null;

        $dto = new DataChangeNotification(
            subscriptionName: $mapping['subscriptionName'],
            nodeId: $mapping['nodeId'],
            clientHandle: $notification['clientHandle'],
            value: $dataValue?->getValue(),
            statusCode: $dataValue?->getStatusCode() ?? 0,
            sourceTimestamp: $dataValue?->getSourceTimestamp()?->format('c'),
            serverTimestamp: $dataValue?->getServerTimestamp()?->format('c'),
        );

        $this->dispatchJob($mapping['job'], $dto, $mapping['queue'] ?? null);
    }

    private function dispatchEventJob(array $mapping, array $notification): void
    {
        $rawFields = $notification['eventFields'] ?? [];
        $selectFields = $mapping['selectFields'] ?? [];

        $eventFields = [];
        foreach ($rawFields as $index => $variant) {
            $fieldName = $selectFields[$index] ?? "field_{$index}";
            $eventFields[$fieldName] = is_object($variant) && method_exists($variant, 'getValue')
                ? $variant->getValue()
                : $variant;
        }

        $dto = new EventNotification(
            subscriptionName: $mapping['subscriptionName'],
            nodeId: $mapping['nodeId'],
            eventFields: $eventFields,
            clientHandle: $notification['clientHandle'],
        );

        $this->dispatchJob($mapping['job'], $dto, $mapping['queue'] ?? null);
    }

    private function dispatchJob(string $jobClass, object $dto, ?string $queue): void
    {
        $job = new $jobClass($dto);

        if ($this->dispatcher) {
            ($this->dispatcher)($job, $queue);
            return;
        }

        if ($queue) {
            dispatch($job)->onQueue($queue);
        } else {
            dispatch($job);
        }
    }

    private function cleanup(): void
    {
        $this->log('Shutting down subscriptions...');

        foreach ($this->clients as $connectionName => $client) {
            $subscriptionIds = $this->connectionSubscriptions[$connectionName] ?? [];

            foreach ($subscriptionIds as $subscriptionId) {
                try {
                    $monitoredItemIds = $this->monitoredItems[$subscriptionId] ?? [];
                    if (!empty($monitoredItemIds)) {
                        $client->deleteMonitoredItems($subscriptionId, $monitoredItemIds);
                    }
                    $client->deleteSubscription($subscriptionId);
                    $name = $this->subscriptionMap[$subscriptionId] ?? $subscriptionId;
                    $this->log("Subscription '{$name}' deleted");
                } catch (\Throwable $e) {
                    $this->log("Error cleaning up subscription: {$e->getMessage()}", 'warning');
                }
            }
        }

        $this->manager->disconnectAll();
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->logger) {
            ($this->logger)($message, $level);
        }
    }
}
