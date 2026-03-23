# Usage

All examples assume `use Gianfriaur\OpcuaLaravel\Facades\Opcua;` at the top of the file.

## Reading Values

```php
$client = Opcua::connect();

// String NodeId
$dv = $client->read('i=2259');
echo $dv->getValue();    // 0 = Running
echo $dv->statusCode;    // 0 (Good)

// NodeId object
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$dv = $client->read(NodeId::numeric(2, 1001));

$client->disconnect();
```

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
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$client = Opcua::connect();

$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

if (StatusCode::isGood($status)) {
    echo 'Write successful';
}

$client->disconnect();
```

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
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;

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
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;

$refs = $client->browse('i=85', nodeClasses: [NodeClass::Variable]);
```

### Path Resolution

```php
$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$dv = $client->read($nodeId);
```

### TranslateBrowsePaths

```php
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;

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
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

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
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;

$client = Opcua::connect();

echo $client->isConnected();        // true
echo $client->getConnectionState(); // ConnectionState::Connected

$client->reconnect();

$client->disconnect();
echo $client->getConnectionState(); // ConnectionState::Disconnected
```

## Timeout and Retry

```php
// Via config (config/opcua.php per connection)
// 'timeout'    => 10.0,
// 'auto_retry' => 3,
// 'batch_size' => 100,

// Or via fluent API
$client = Opcua::connection();
$client->setTimeout(10.0)
    ->setAutoRetry(3)
    ->setBatchSize(100)
    ->connect('opc.tcp://...');
```

## Endpoint Discovery

```php
$client = Opcua::connection();

$endpoints = $client->getEndpoints('opc.tcp://192.168.1.100:4840');
foreach ($endpoints as $ep) {
    echo "{$ep->securityPolicyUri} ({$ep->securityMode->name})\n";
}
```
