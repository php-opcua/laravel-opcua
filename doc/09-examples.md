# Examples

Complete, copy-paste-ready code examples for all major features.

## Read a Single Value

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$dv = $client->read('i=2259');
echo "ServerState: " . $dv->getValue(); // 0 = Running

$client->disconnect();
```

## Read Multiple Values (Array)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'ns=2;i=1001'],
    ['nodeId' => 'ns=2;s=Temperature'],
]);

foreach ($results as $i => $dv) {
    echo "[$i] {$dv->getValue()}\n";
}

$client->disconnect();
```

## Read Multiple Values (Fluent Builder)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->node('ns=2;s=Temperature')->value()
    ->execute();

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

$client->disconnect();
```

## Write a Value

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

$client = Opcua::connect();

$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

if (StatusCode::isGood($status)) {
    echo "Write OK\n";
}

$client->disconnect();
```

## Write Without Explicit Type (Auto-Detection, v4.0+)

When `auto_detect_write_type` is enabled (the default), you can omit the type parameter. The client reads the node's DataType attribute, caches it, and uses it for the write.

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\StatusCode;

$client = Opcua::connect();

// No BuiltinType needed — the client auto-detects that ns=2;i=1001 is Int32
$status = $client->write('ns=2;i=1001', 42);

if (StatusCode::isGood($status)) {
    echo "Write OK (type auto-detected)\n";
}

$client->disconnect();
```

## Write Multiple Values (Fluent Builder)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\StatusCode;

$client = Opcua::connect();

$results = $client->writeMulti()
    ->node('ns=2;i=1001')->int32(42)
    ->node('ns=2;i=1002')->double(3.14)
    ->node('ns=2;s=Label')->string('active')
    ->execute();

foreach ($results as $i => $status) {
    echo "[$i] " . (StatusCode::isGood($status) ? 'OK' : 'FAIL') . "\n";
}

$client->disconnect();
```

## Read with Refresh Parameter (v4.0+)

When read metadata cache is enabled, use the `refresh` parameter to bypass the cache:

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();
$client->setReadMetadataCache(true);

// Cached read (default)
$dv = $client->read('ns=2;i=1001');
echo "Cached: " . $dv->getValue() . "\n";

// Force fresh read from server
$dv = $client->read('ns=2;i=1001', refresh: true);
echo "Fresh: " . $dv->getValue() . "\n";

$client->disconnect();
```

## Browse the Address Space

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$refs = $client->browse('i=85');

foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeClass->name}) — {$ref->nodeId}\n";
}

$client->disconnect();
```

## Recursive Browse

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$tree = $client->browseRecursive('i=85', maxDepth: 3);

function printTree(array $nodes, int $indent = 0): void
{
    foreach ($nodes as $node) {
        echo str_repeat('  ', $indent) . $node->reference->displayName . "\n";
        if (!empty($node->children)) {
            printTree($node->children, $indent + 1);
        }
    }
}

printTree($tree);

$client->disconnect();
```

## Path Resolution

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$dv = $client->read($nodeId);

echo "ServerStatus NodeId: {$nodeId}\n";
echo "Value: {$dv->getValue()}\n";

$client->disconnect();
```

## Call a Method

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

$client = Opcua::connect();

$result = $client->call(
    'ns=2;i=100',   // parent object
    'ns=2;i=200',   // method
    [
        new Variant(BuiltinType::Double, 3.0),
        new Variant(BuiltinType::Double, 4.0),
    ],
);

if (StatusCode::isGood($result->statusCode)) {
    echo "Result: {$result->outputArguments[0]->value}\n"; // 7.0
}

$client->disconnect();
```

## Subscribe to Data Changes

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],
    ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],
]);

// Poll for notifications
for ($i = 0; $i < 10; $i++) {
    $pub = $client->publish();

    foreach ($pub->notifications as $notif) {
        echo "[handle={$notif['clientHandle']}] {$notif['dataValue']->getValue()}\n";
    }

    usleep(500_000);
}

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

## Historical Data

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$history = $client->historyReadRaw(
    'ns=2;i=1001',
    new \DateTimeImmutable('-1 hour'),
    new \DateTimeImmutable('now'),
);

foreach ($history as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s')}] {$dv->getValue()}\n";
}

$client->disconnect();
```

## Secure Connection with Authentication

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Option 1: Via config/opcua.php (recommended)
// 'connections' => [
//     'secure' => [
//         'endpoint'           => 'opc.tcp://10.0.0.10:4840',
//         'security_policy'    => 'Basic256Sha256',
//         'security_mode'      => 'SignAndEncrypt',
//         'username'           => 'operator',
//         'password'           => 'secret',
//         'client_certificate' => '/etc/opcua/certs/client.pem',
//         'client_key'         => '/etc/opcua/certs/client.key',
//     ],
// ],
$client = Opcua::connect('secure');

// Option 2: Via connectTo with inline config
$client = Opcua::connectTo('opc.tcp://10.0.0.10:4840', [
    'security_policy'    => 'Basic256Sha256',
    'security_mode'      => 'SignAndEncrypt',
    'username'           => 'operator',
    'password'           => 'secret',
    'client_certificate' => '/etc/opcua/certs/client.pem',
    'client_key'         => '/etc/opcua/certs/client.key',
]);

$value = $client->read('ns=2;i=1001');
echo $value->getValue();

$client->disconnect();
```

## Multiple Connections

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Connect to two PLCs simultaneously
$plc1 = Opcua::connect('plc-line-1');
$plc2 = Opcua::connect('plc-line-2');

$temp1 = $plc1->read('ns=2;s=Temperature')->getValue();
$temp2 = $plc2->read('ns=2;s=Temperature')->getValue();

echo "Line 1: {$temp1}°C\n";
echo "Line 2: {$temp2}°C\n";

Opcua::disconnectAll();
```

## Dependency Injection in a Controller

```php
use PhpOpcua\LaravelOpcua\OpcuaManager;
use Illuminate\Http\JsonResponse;

class PlcController extends Controller
{
    public function status(OpcuaManager $opcua): JsonResponse
    {
        $client = $opcua->connect();
        $state = $client->read('i=2259')->getValue();
        $client->disconnect();

        return response()->json([
            'server_state' => $state,
            'daemon_active' => $opcua->isSessionManagerRunning(),
        ]);
    }

    public function write(OpcuaManager $opcua): JsonResponse
    {
        $client = $opcua->connect();
        // Type is optional in v4 — auto-detected when auto_detect_write_type is enabled
        $status = $client->write('ns=2;i=1001', 42);
        $client->disconnect();

        return response()->json([
            'success' => StatusCode::isGood($status),
        ]);
    }
}
```

## Type Discovery

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

// Discover all custom types
$count = $client->discoverDataTypes();
echo "Discovered {$count} custom types\n";

// Now reading structured types works without manual codecs
$point = $client->read('ns=2;s=MyPoint')->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5]

$client->disconnect();
```

## Error Handling

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\StatusCode;

try {
    $client = Opcua::connect();

    $dv = $client->read('ns=99;i=99999');
    if (StatusCode::isBad($dv->statusCode)) {
        echo "Bad status: {$dv->statusCode}\n";
    }

    $client->disconnect();
} catch (ConnectionException $e) {
    echo "Connection failed: {$e->getMessage()}\n";
} catch (ServiceException $e) {
    echo "Service error: {$e->getMessage()}\n";
}
```

## Trust Store Configuration (v4.0+)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Security\TrustPolicy;

$client = Opcua::connection();

// Configure trust store
$client->setTrustStorePath(storage_path('opcua/trust'));
$client->setTrustPolicy(TrustPolicy::FingerprintAndExpiry);
$client->autoAccept(true); // TOFU mode

$client->connect('opc.tcp://192.168.1.100:4840');

$dv = $client->read('i=2259');
echo "ServerState: " . $dv->getValue() . "\n";

$client->disconnect();
```

## Certificate Trust Management (v4.0+)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Exception\UntrustedCertificateException;

try {
    $client = Opcua::connect();
} catch (UntrustedCertificateException $e) {
    echo "Untrusted certificate: " . $e->getFingerprint() . "\n";

    // Approve the certificate and retry
    $client = Opcua::connection();
    $client->trustCertificate($e->getCertificate());
    $client->connect('opc.tcp://192.168.1.100:4840');
}

// Later, revoke trust
$client->untrustCertificate($derBytes);

$client->disconnect();
```

## Event Dispatcher Setup (v4.0+)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Events\AfterRead;
use PhpOpcua\Client\Events\Connected;
use Psr\EventDispatcher\EventDispatcherInterface;

$client = Opcua::connect();

// Attach Laravel's event dispatcher
$client->setEventDispatcher(app(EventDispatcherInterface::class));

// Now all OPC UA operations fire PSR-14 events
$dv = $client->read('i=2259'); // fires BeforeRead + AfterRead

$client->disconnect(); // fires Disconnected
```

## Modify Monitored Items (v4.0+)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1, 'samplingInterval' => 1000.0],
    ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2, 'samplingInterval' => 1000.0],
]);

// Later, change the sampling interval for item 1
$client->modifyMonitoredItems($sub->subscriptionId, [
    ['monitoredItemId' => 1, 'samplingInterval' => 200.0],
]);

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

## Set Triggering (v4.0+)

Link monitored items so that one item triggers reporting of others:

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],  // triggering item
    ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],  // triggered item
    ['nodeId' => 'ns=2;i=1003', 'clientHandle' => 3],  // triggered item
]);

// When item 1 changes, also report items 2 and 3
$client->setTriggering(
    $sub->subscriptionId,
    1,          // triggering monitored item ID
    [2, 3],     // links to add
    [],         // links to remove
);

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

## Testing with MockClient

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

// In a Pest / PHPUnit test
it('reads temperature from PLC', function () {
    $mock = MockClient::create()
        ->onRead('ns=2;s=Temperature', fn() => DataValue::ofDouble(23.5));

    $value = $mock->read('ns=2;s=Temperature');

    expect($value->getValue())->toBe(23.5);
    expect($value->statusCode)->toBe(StatusCode::Good);
    expect($mock->callCount('read'))->toBe(1);
});
```
