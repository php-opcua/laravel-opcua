# Usage

All examples assume `use PhpOpcua\LaravelOpcua\Facades\Opcua;` at the top of the file.

## Reading Values

```php
$client = Opcua::connect();

// String NodeId
$dv = $client->read('i=2259');
echo $dv->getValue();    // 0 = Running
echo $dv->statusCode;    // 0 (Good)

// NodeId object
use PhpOpcua\Client\Types\NodeId;

$dv = $client->read(NodeId::numeric(2, 1001));

// Force a fresh read, bypassing the metadata cache
$dv = $client->read('i=2259', refresh: true);

$client->disconnect();
```

> **v4.0 note:** `read()` now accepts an optional `bool $refresh = false` parameter. When `true`, the client bypasses the read metadata cache and fetches the value directly from the server. When `read_metadata_cache` is enabled in config (default), repeated reads of the same node reuse cached metadata for the data type, avoiding redundant server round-trips.

### Reading Multiple Values

```php
$client = Opcua::connect();

// Array syntax
$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'ns=2;i=1001'],
    ['nodeId' => 'ns=2;s=Temperature'],
]);

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

// Fluent builder
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->execute();

$client->disconnect();
```

## Writing Values

```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

$client = Opcua::connect();

// Auto-detect type (v4.0) — the client reads the node's data type from the server
$status = $client->write('ns=2;i=1001', 42);

// Explicit type — still supported when you need to override auto-detection
$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

if (StatusCode::isGood($status)) {
    echo 'Write successful';
}

$client->disconnect();
```

> **v4.0 note:** The `$type` parameter is now nullable (`?BuiltinType $type = null`). When omitted, the client auto-detects the correct OPC UA type by querying the node's data type attribute. This requires `auto_detect_write_type` to be enabled in config (default: `true`). Pass an explicit `BuiltinType` to skip auto-detection when you already know the type.

### Writing Multiple Values

```php
// Array syntax
$results = $client->writeMulti([
    ['nodeId' => 'ns=2;i=1001', 'value' => 42, 'type' => BuiltinType::Int32],
    ['nodeId' => 'ns=2;i=1002', 'value' => 'hello', 'type' => BuiltinType::String],
]);

// Fluent builder
$results = $client->writeMulti()
    ->node('ns=2;i=1001')->int32(42)
    ->node('ns=2;i=1002')->string('hello')
    ->execute();
```

## Browsing the Address Space

```php
$client = Opcua::connect();

$refs = $client->browse('i=85'); // Objects folder

foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeClass->name})\n";
}

$client->disconnect();
```

### Browse with Caching

Browse results are cached by default. Control caching per-call:

```php
$refs = $client->browse('i=85', useCache: false);  // skip cache
$client->invalidateCache('i=85');                   // clear one node
$client->flushCache();                              // clear all
```

### Browse All (Automatic Continuation)

```php
$allRefs = $client->browseAll('i=85');
```

### Recursive Browse

```php
use PhpOpcua\Client\Types\BrowseNode;

$tree = $client->browseRecursive('i=85', maxDepth: 3);

foreach ($tree as $node) {
    echo $node->reference->displayName . "\n";
    foreach ($node->children as $child) {
        echo "  " . $child->reference->displayName . "\n";
    }
}
```

### Browse with Node Class Filter

```php
use PhpOpcua\Client\Types\NodeClass;

$refs = $client->browse('i=85', nodeClasses: [NodeClass::Variable]);
```

### Path Resolution

```php
$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$dv = $client->read($nodeId);
```

### TranslateBrowsePaths

```php
use PhpOpcua\Client\Types\QualifiedName;

// Array syntax
$results = $client->translateBrowsePaths([
    [
        'startingNodeId' => 'i=84',
        'relativePath' => [
            ['targetName' => new QualifiedName(0, 'Objects')],
            ['targetName' => new QualifiedName(0, 'Server')],
        ],
    ],
]);

echo $results[0]->targets[0]->targetId->identifier; // 2253

// Fluent builder
$results = $client->translateBrowsePaths()
    ->path('i=84', [['targetName' => new QualifiedName(0, 'Objects')]])
    ->execute();
```

## Calling Methods

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$client = Opcua::connect();

$result = $client->call(
    'i=85',     // parent object
    'ns=2;i=5000', // method
    [
        new Variant(BuiltinType::Double, 3.0),
        new Variant(BuiltinType::Double, 4.0),
    ],
);

if (StatusCode::isGood($result->statusCode)) {
    echo $result->outputArguments[0]->value; // 7.0
}

$client->disconnect();
```

## Subscriptions

```php
$client = Opcua::connect();

// Create subscription
$sub = $client->createSubscription(publishingInterval: 500.0);

// Monitor nodes
$monitored = $client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],
]);

// Or use the builder
$monitored = $client->createMonitoredItems($sub->subscriptionId)
    ->item('ns=2;i=1001', clientHandle: 1)
    ->item('ns=2;i=1002', clientHandle: 2)
    ->execute();

// Receive notifications
$pub = $client->publish();
echo $pub->subscriptionId;
echo $pub->sequenceNumber;

foreach ($pub->notifications as $notif) {
    echo $notif['dataValue']->getValue() . "\n";
}

// Clean up
$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

### Modifying Monitored Items

Change sampling interval, queue size, or filters on existing monitored items without recreating them:

```php
$results = $client->modifyMonitoredItems($sub->subscriptionId, [
    ['monitoredItemId' => $monitored[0]->monitoredItemId, 'samplingInterval' => 250.0],
    ['monitoredItemId' => $monitored[1]->monitoredItemId, 'queueSize' => 10],
]);
```

### Set Triggering

Configure triggering links so that a reporting monitored item triggers sampling of linked items:

```php
$client->setTriggering(
    $sub->subscriptionId,
    triggeringItemId: $monitored[0]->monitoredItemId,
    linksToAdd: [$monitored[1]->monitoredItemId],
);
```

### Subscription Transfer

After a reconnection, transfer existing subscriptions to the new session:

```php
$results = $client->transferSubscriptions([$subscriptionId]);

if (StatusCode::isGood($results[0]->statusCode)) {
    echo 'Subscription transferred';
}

// Republish unacknowledged notifications
$result = $client->republish($subscriptionId, $sequenceNumber);
```

## Historical Data

```php
$client = Opcua::connect();

// Raw historical data
$history = $client->historyReadRaw(
    'ns=2;i=1001',
    new \DateTimeImmutable('-1 hour'),
    new \DateTimeImmutable('now'),
);

foreach ($history as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s')}] {$dv->getValue()}\n";
}

// Processed (aggregated) data
$history = $client->historyReadProcessed(
    'ns=2;i=1001',
    new \DateTimeImmutable('-1 hour'),
    new \DateTimeImmutable('now'),
    processingInterval: 60000.0,
    aggregateType: NodeId::numeric(0, 2341), // Average
);

// Values at specific timestamps
$history = $client->historyReadAtTime('ns=2;i=1001', [
    new \DateTimeImmutable('-30 minutes'),
    new \DateTimeImmutable('-15 minutes'),
    new \DateTimeImmutable('now'),
]);

$client->disconnect();
```

## Type Discovery

Auto-discover server-defined structured types:

```php
$client = Opcua::connect();

$count = $client->discoverDataTypes();
echo "Discovered {$count} custom types";

// Only namespace 2
$count = $client->discoverDataTypes(namespaceIndex: 2);

// Access the codec repository
$repo = $client->getExtensionObjectRepository();

$client->disconnect();
```

## Connection State

```php
use PhpOpcua\Client\Types\ConnectionState;

$client = Opcua::connect();

echo $client->isConnected();        // true
echo $client->getConnectionState(); // ConnectionState::Connected

$client->reconnect();

$client->disconnect();
echo $client->getConnectionState(); // ConnectionState::Disconnected
```

## Timeout and Retry

Configure timeout, retry, and batch size through `config/opcua.php` per connection:

```php
// config/opcua.php
'default' => [
    'timeout'    => 10.0,
    'auto_retry' => 3,
    'batch_size' => 100,
],
```

> **v4.0 breaking change:** The `OpcUaClientInterface` no longer exposes setter methods (`setTimeout()`, `setAutoRetry()`, `setBatchSize()`). In direct mode, these values are set at build time via `ClientBuilder` and cannot be changed after connection. Configure them in `config/opcua.php` or pass them as ad-hoc config to `connectTo()`. In managed mode, setter methods remain available on `ManagedClient`.

## Certificate Trust Management

Manage trusted server certificates at runtime using the trust store:

```php
$client = Opcua::connect();

// Trust a server certificate (DER-encoded binary)
$client->trustCertificate($derCertificateBytes);

// Remove a previously trusted certificate
$client->untrustCertificate($derCertificateBytes);

$client->disconnect();
```

The trust store is configured per connection via `trust_store_path` (directory), `trust_policy`, `auto_accept`, and `auto_accept_force`. See [Installation & Configuration](02-installation.md) for details.

## Endpoint Discovery

```php
$client = Opcua::connect();

$endpoints = $client->getEndpoints('opc.tcp://192.168.1.100:4840');
foreach ($endpoints as $ep) {
    echo "{$ep->securityPolicyUri} ({$ep->securityMode->name})\n";
}

$client->disconnect();
```
