<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Facades;

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\MonitoredItemsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathResult;
use Gianfriaur\OpcuaPhpClient\Types\BrowseResultSet;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\PublishResult;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\SubscriptionResult;
use Gianfriaur\OpcuaPhpClient\Types\TransferResult;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Illuminate\Support\Facades\Facade;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @method static OpcUaClientInterface connection(?string $name = null)
 * @method static OpcUaClientInterface connect(?string $name = null)
 * @method static OpcUaClientInterface connectTo(string $endpointUrl, array $config = [], ?string $as = null)
 * @method static void disconnect(?string $name = null)
 * @method static void disconnectAll()
 * @method static bool isSessionManagerRunning()
 * @method static string getDefaultConnection()
 *
 * Proxied to default connection:
 * @method static void connect(string $endpointUrl)
 * @method static void disconnect()
 * @method static void reconnect()
 * @method static bool isConnected()
 * @method static ConnectionState getConnectionState()
 * @method static self setLogger(LoggerInterface $logger)
 * @method static LoggerInterface getLogger()
 * @method static ExtensionObjectRepository getExtensionObjectRepository()
 * @method static self setCache(?CacheInterface $cache)
 * @method static ?CacheInterface getCache()
 * @method static void invalidateCache(NodeId|string $nodeId)
 * @method static void flushCache()
 * @method static self setTimeout(float $timeout)
 * @method static float getTimeout()
 * @method static self setAutoRetry(int $maxRetries)
 * @method static int getAutoRetry()
 * @method static self setBatchSize(int $batchSize)
 * @method static int|null getBatchSize()
 * @method static int|null getServerMaxNodesPerRead()
 * @method static int|null getServerMaxNodesPerWrite()
 * @method static int discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true)
 * @method static EndpointDescription[] getEndpoints(string $endpointUrl, bool $useCache = true)
 * @method static ReferenceDescription[] browse(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [], bool $useCache = true)
 * @method static BrowseResultSet browseWithContinuation(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [])
 * @method static BrowseResultSet browseNext(string $continuationPoint)
 * @method static ReferenceDescription[] browseAll(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [], bool $useCache = true)
 * @method static self setDefaultBrowseMaxDepth(int $maxDepth)
 * @method static int getDefaultBrowseMaxDepth()
 * @method static BrowseNode[] browseRecursive(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?int $maxDepth = null, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [])
 * @method static BrowsePathResult[]|BrowsePathsBuilder translateBrowsePaths(?array $browsePaths = null)
 * @method static NodeId resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true)
 * @method static DataValue read(NodeId|string $nodeId, int $attributeId = 13)
 * @method static DataValue[]|ReadMultiBuilder readMulti(?array $readItems = null)
 * @method static int write(NodeId|string $nodeId, mixed $value, BuiltinType $type)
 * @method static int[]|WriteMultiBuilder writeMulti(?array $writeItems = null)
 * @method static CallResult call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = [])
 * @method static SubscriptionResult createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0)
 * @method static MonitoredItemResult[]|MonitoredItemsBuilder createMonitoredItems(int $subscriptionId, ?array $items = null)
 * @method static MonitoredItemResult createEventMonitoredItem(int $subscriptionId, NodeId|string $nodeId, array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int $clientHandle = 1)
 * @method static int[] deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds)
 * @method static int deleteSubscription(int $subscriptionId)
 * @method static PublishResult publish(array $acknowledgements = [])
 * @method static TransferResult[] transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false)
 * @method static array republish(int $subscriptionId, int $retransmitSequenceNumber)
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
