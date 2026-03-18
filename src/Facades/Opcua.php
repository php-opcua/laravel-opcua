<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Facades;

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionBuilder;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Illuminate\Support\Facades\Facade;

/**
 * @method static OpcUaClientInterface connection(?string $name = null)
 * @method static OpcUaClientInterface connect(?string $name = null)
 * @method static OpcUaClientInterface connectTo(string $endpointUrl, array $config = [], ?string $as = null)
 * @method static void disconnect(?string $name = null)
 * @method static void disconnectAll()
 * @method static bool isSessionManagerRunning()
 * @method static string getDefaultConnection()
 * @method static SubscriptionBuilder subscription(string $name)
 * @method static array getRegisteredSubscriptions()
 *
 * Proxied to default connection:
 * @method static void connect(string $endpointUrl)
 * @method static void disconnect()
 * @method static EndpointDescription[] getEndpoints(string $endpointUrl)
 * @method static ReferenceDescription[] browse(NodeId $nodeId, int $direction = 0, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0)
 * @method static DataValue read(NodeId $nodeId, int $attributeId = 13)
 * @method static DataValue[] readMulti(array $items)
 * @method static int write(NodeId $nodeId, mixed $value, BuiltinType $type)
 * @method static int[] writeMulti(array $items)
 * @method static array call(NodeId $objectId, NodeId $methodId, array $inputArguments = [])
 * @method static array createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0)
 * @method static array createMonitoredItems(int $subscriptionId, array $items)
 * @method static array createEventMonitoredItem(int $subscriptionId, NodeId $nodeId, array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int $clientHandle = 1)
 * @method static array deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds)
 * @method static int deleteSubscription(int $subscriptionId)
 * @method static array publish(array $acknowledgements = [])
 * @method static DataValue[] historyReadRaw(NodeId $nodeId, ?\DateTimeImmutable $startTime = null, ?\DateTimeImmutable $endTime = null, int $numValuesPerNode = 0, bool $returnBounds = false)
 *
 * @see OpcuaManager
 */
class Opcua extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpcuaManager::class;
    }
}
