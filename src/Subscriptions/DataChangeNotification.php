<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Subscriptions;

readonly class DataChangeNotification
{
    public function __construct(
        public string  $subscriptionName,
        public string  $nodeId,
        public int     $clientHandle,
        public mixed   $value,
        public int     $statusCode,
        public ?string $sourceTimestamp,
        public ?string $serverTimestamp,
    ) {}
}
