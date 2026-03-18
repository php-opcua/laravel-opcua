<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Subscriptions;

readonly class EventNotification
{
    /**
     * @param string $subscriptionName
     * @param string $nodeId
     * @param array<string, mixed> $eventFields
     * @param int $clientHandle
     */
    public function __construct(
        public string $subscriptionName,
        public string $nodeId,
        public array  $eventFields,
        public int    $clientHandle,
    ) {}
}
