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
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\Aggregate\AggregateOptions;
use PhpOpcua\Client\Module\FileTransfer\CreateFileResult;
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;
use PhpOpcua\Client\Module\Subscription\MonitoredItemModifyResult;
use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Module\Subscription\PublishResult;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Module\Subscription\SetTriggeringResult;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\Subscription\TransferResult;
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
 * History update (v4.4.0 — OPC UA Part 11 §6.9):
 * @method static int[] historyInsertData(NodeId|string $nodeId, DataValue[] $values)
 * @method static int[] historyReplaceData(NodeId|string $nodeId, DataValue[] $values)
 * @method static int[] historyUpdateData(NodeId|string $nodeId, DataValue[] $values)
 * @method static int historyDeleteRawModified(NodeId|string $nodeId, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime, bool $isDeleteModified = false)
 * @method static int[] historyDeleteAtTime(NodeId|string $nodeId, \DateTimeImmutable[] $timestamps)
 * @method static int[] historyInsertEvent(NodeId|string $nodeId, string[] $selectFields, array $eventData)
 * @method static int[] historyReplaceEvent(NodeId|string $nodeId, string[] $selectFields, array $eventData)
 * @method static int[] historyUpdateEvent(NodeId|string $nodeId, string[] $selectFields, array $eventData)
 * @method static int[] historyDeleteEvent(NodeId|string $nodeId, string[] $eventIds)
 *
 * File transfer (v4.4.0 — OPC UA Part 5 §C.2 / §C.3):
 * @method static int openFile(NodeId|string $fileNodeId, OpenFileMode|int $mode)
 * @method static void closeFile(NodeId|string $fileNodeId, int $fileHandle)
 * @method static string readFile(NodeId|string $fileNodeId, int $fileHandle, int $length)
 * @method static void writeFile(NodeId|string $fileNodeId, int $fileHandle, string $data)
 * @method static int getFilePosition(NodeId|string $fileNodeId, int $fileHandle)
 * @method static void setFilePosition(NodeId|string $fileNodeId, int $fileHandle, int $position)
 * @method static NodeId createDirectory(NodeId|string $directoryNodeId, string $directoryName)
 * @method static CreateFileResult createFileInDirectory(NodeId|string $directoryNodeId, string $fileName, bool $requestFileOpen = false)
 * @method static void deleteFileSystemObject(NodeId|string $directoryNodeId, NodeId|string $targetNodeId)
 * @method static NodeId moveOrCopyFileSystemObject(NodeId|string $directoryNodeId, NodeId|string $sourceNodeId, NodeId|string $targetDirectoryNodeId, bool $createCopy, string $newName = '')
 *
 * Client-side aggregate computation (v4.4.0 — OPC UA Part 13, reachable through Client::__call):
 * @method static DataValue[] aggregate(DataValue[] $rawValues, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime, float $processingIntervalMs, AggregateFunction $function, ?AggregateOptions $options = null)
 * @method static DataValue[] historyAggregate(NodeId|string $nodeId, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime, float $processingIntervalMs, AggregateFunction $function, ?AggregateOptions $options = null)
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
