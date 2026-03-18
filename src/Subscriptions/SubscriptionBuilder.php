<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Subscriptions;

class SubscriptionBuilder
{
    private ?string $connectionName = null;
    private ?string $endpointUrl = null;
    private array $endpointConfig = [];
    private string $type = 'data_change';
    private array $nodesList = [];
    private ?string $eventNodeId = null;
    private array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'];
    private ?string $jobClass = null;
    private ?string $queueName = null;
    private float $publishingInterval = 500.0;

    public function __construct(
        private readonly string $name,
    ) {}

    public function connection(string $name): self
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * @param string $url
     * @param array $config
     * @return SubscriptionBuilder
     */
    public function endpoint(string $url, array $config = []): self
    {
        $this->endpointUrl = $url;
        $this->endpointConfig = $config;

        return $this;
    }

    /**
     * @param array<int, string|array{node_id: string, sampling_interval?: float}> $nodes
     * @return SubscriptionBuilder
     */
    public function nodes(array $nodes): self
    {
        $this->type = 'data_change';

        foreach ($nodes as $node) {
            $this->nodesList[] = is_string($node)
                ? ['node_id' => $node]
                : $node;
        }

        return $this;
    }

    /**
     * @param string $nodeId
     * @param string[]|null $selectFields
     * @return SubscriptionBuilder
     */
    public function events(string $nodeId, ?array $selectFields = null): self
    {
        $this->type = 'event';
        $this->eventNodeId = $nodeId;

        if ($selectFields !== null) {
            $this->selectFields = $selectFields;
        }

        return $this;
    }


    /**
     * @param string $jobClass
     * @return SubscriptionBuilder
     */
    public function job(string $jobClass): self
    {
        $this->jobClass = $jobClass;

        return $this;
    }

    /**
     * @param string $name
     * @return SubscriptionBuilder
     */
    public function queue(string $name): self
    {
        $this->queueName = $name;

        return $this;
    }

    /**
     * @param float $ms
     * @return $this
     */
    public function publishingInterval(float $ms): self
    {
        $this->publishingInterval = $ms;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $config = [
            'type' => $this->type,
            'job' => $this->jobClass,
            'queue' => $this->queueName,
            'publishing_interval' => $this->publishingInterval,
        ];

        if ($this->endpointUrl !== null) {
            $config['endpoint'] = $this->endpointUrl;
            $config['endpoint_config'] = $this->endpointConfig;
        } elseif ($this->connectionName !== null) {
            $config['connection'] = $this->connectionName;
        }

        if ($this->type === 'event') {
            $config['node_id'] = $this->eventNodeId;
            $config['select_fields'] = $this->selectFields;
        } else {
            $config['nodes'] = $this->nodesList;
        }

        return $config;
    }
}
