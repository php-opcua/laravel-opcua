<?php

declare(strict_types=1);

namespace PhpOpcua\LaravelOpcua\Facades;

use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Builder\BrowsePathsBuilder;
use PhpOpcua\Client\Builder\MonitoredItemsBuilder;
use PhpOpcua\Client\Builder\ReadMultiBuilder;
use PhpOpcua\Client\Builder\WriteMultiBuilder;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BrowsePathResult;
use PhpOpcua\Client\Types\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\MonitoredItemModifyResult;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\SetTriggeringResult;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;
use Illuminate\Support\Facades\Facade;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * OpcuaManager methods:
 * @method static OpcUaClientInterface connection(?string $name = null)
 * @method static OpcUaClientInterface connect(?string $name = null)
 * @method static OpcUaClientInterface connectTo(string $endpointUrl, array $config = [], ?string $as = null)
 * @method static void disconnect(?string $name = null)
 * @method static void disconnectAll()
 * @method static bool isSessionManagerRunning()
 * @method static string getDefaultConnection()
 *
 * Proxied to default connection (OpcUaClientInterface):
 * @method static void reconnect()
 * @method static bool isConnected()
 * @method static ConnectionState getConnectionState()
 * @method static LoggerInterface getLogger()
 * @method static EventDispatcherInterface getEventDispatcher()
 * @method static ?TrustStoreInterface getTrustStore()
 * @method static ?TrustPolicy getTrustPolicy()
 * @method static ExtensionObjectRepository getExtensionObjectRepository()
 * @method static ?CacheInterface getCache()
 * @method static void invalidateCache(NodeId|string $nodeId)
 * @method static void flushCache()
 * @method static float getTimeout()
 * @method static int getAutoRetry()
 * @method static int|null getBatchSize()
 * @method static int|null getServerMaxNodesPerRead()
 * @method static int|null getServerMaxNodesPerWrite()
 * @method static int getDefaultBrowseMaxDepth()
 * @method static int discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true)
 * @method static EndpointDescription[] getEndpoints(string $endpointUrl, bool $useCache = true)
 * @method static ReferenceDescription[] browse(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [], bool $useCache = true)
 * @method static BrowseResultSet browseWithContinuation(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [])
 * @method static BrowseResultSet browseNext(string $continuationPoint)
 * @method static ReferenceDescription[] browseAll(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [], bool $useCache = true)
 * @method static BrowseNode[] browseRecursive(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?int $maxDepth = null, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [])
 * @method static BrowsePathResult[]|BrowsePathsBuilder translateBrowsePaths(?array $browsePaths = null)
 * @method static NodeId resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true)
 * @method static DataValue read(NodeId|string $nodeId, int $attributeId = 13, bool $refresh = false)
 * @method static DataValue[]|ReadMultiBuilder readMulti(?array $readItems = null)
 * @method static int write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null)
 * @method static int[]|WriteMultiBuilder writeMulti(?array $writeItems = null)
 * @method static CallResult call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = [])
 * @method static SubscriptionResult createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0)
 * @method static MonitoredItemResult[]|MonitoredItemsBuilder createMonitoredItems(int $subscriptionId, ?array $items = null)
 * @method static MonitoredItemResult createEventMonitoredItem(int $subscriptionId, NodeId|string $nodeId, array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int $clientHandle = 1)
 * @method static int[] deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds)
 * @method static MonitoredItemModifyResult[] modifyMonitoredItems(int $subscriptionId, array $itemsToModify)
 * @method static SetTriggeringResult setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd = [], array $linksToRemove = [])
 * @method static int deleteSubscription(int $subscriptionId)
 * @method static PublishResult publish(array $acknowledgements = [])
 * @method static TransferResult[] transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false)
 * @method static array republish(int $subscriptionId, int $retransmitSequenceNumber)
 * @method static void trustCertificate(string $certDer)
 * @method static void untrustCertificate(string $fingerprint)
 * @method static DataValue[] historyReadRaw(NodeId|string $nodeId, ?\DateTimeImmutable $startTime = null, ?\DateTimeImmutable $endTime = null, int $numValuesPerNode = 0, bool $returnBounds = false)
 * @method static DataValue[] historyReadProcessed(NodeId|string $nodeId, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime, float $processingInterval, NodeId $aggregateType)
 * @method static DataValue[] historyReadAtTime(NodeId|string $nodeId, array $timestamps)
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
