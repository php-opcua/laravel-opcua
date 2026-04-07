# Testing

## MockClient

The underlying `opcua-client` provides a `MockClient` — a drop-in test double that implements `OpcUaClientInterface` with no TCP connection. Use it to test your application logic without a real OPC UA server.

### Basic Usage

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0))
    ->onRead('ns=2;s=Temperature', fn() => DataValue::ofDouble(23.5));

$value = $mock->read('i=2259');
echo $value->getValue(); // 0
```

### Register Handlers

```php
$mock = MockClient::create()
    ->onRead($nodeId, fn() => DataValue::ofInt32(42))
    ->onWrite($nodeId, fn() => StatusCode::Good)
    ->onBrowse($nodeId, fn() => [...references...])
    ->onCall($objectId, $methodId, fn(array $args) => new CallResult(...))
    ->onResolveNodeId($path, fn() => new NodeId(0, 2253))
    ->onGetEndpoints(fn() => [...endpoints...]);  // v4.0+
```

### v4.0+ MockClient Methods

The `MockClient` in v4 supports additional methods for testing trust store, event, and subscription features:

```php
$mock = MockClient::create()
    // Event dispatcher
    ->setEventDispatcher($dispatcher)

    // Endpoint discovery handler
    ->onGetEndpoints(fn() => [...endpoints...])

    // Write type auto-detection (type parameter is now optional)
    ->setAutoDetectWriteType(true)

    // Read metadata cache
    ->setReadMetadataCache(true)

    // Trust store
    ->setTrustStorePath('/tmp/test-trust')
    ->setTrustPolicy(TrustPolicy::Fingerprint);
```

Additional operations available on the mock:

```php
// Modify monitored items
$mock->modifyMonitoredItems($subId, [
    ['monitoredItemId' => 1, 'samplingInterval' => 200.0],
]);

// Set triggering links
$mock->setTriggering($subId, $triggeringItemId, [2, 3], []);
```

> **Note:** In v4, the `write()` type parameter is nullable. `MockClient` reflects this -- you can call `$mock->write('ns=2;i=1001', 42)` without specifying a type, and it will use the `onWrite` handler as usual.

### Call Tracking

```php
$mock->read('i=2259');
$mock->read('i=2256');

echo $mock->callCount('read');       // 2
echo count($mock->getCalls());       // 2
echo count($mock->getCallsFor('read')); // 2

$mock->resetCalls();
echo $mock->callCount('read');       // 0
```

### DataValue Factory Methods

```php
DataValue::ofBoolean(true);
DataValue::ofInt32(42);
DataValue::ofUInt32(100);
DataValue::ofDouble(3.14);
DataValue::ofString('hello');
DataValue::ofFloat(2.5);
DataValue::of($value, BuiltinType::Int32);
DataValue::bad(StatusCode::BadNodeIdUnknown);
```

### Injecting MockClient into OpcuaManager

```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0));

$manager = new OpcuaManager([
    'default' => 'default',
    'session_manager' => ['enabled' => false, 'socket_path' => '/tmp/nonexistent.sock'],
    'connections' => ['default' => ['endpoint' => 'opc.tcp://localhost:4840']],
]);

// Replace the connection with the mock
$ref = new \ReflectionProperty($manager, 'connections');
$ref->setValue($manager, ['default' => $mock]);

$value = $manager->connection('default')->read('i=2259');
echo $value->getValue(); // 0
echo $mock->callCount('read'); // 1
```

## Running Tests

### Unit Tests

No external dependencies required:

```bash
./vendor/bin/pest tests/Unit/
```

### Integration Tests

Require the [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) Docker containers:

```bash
git clone https://github.com/php-opcua/uanetstandard-test-suite.git
cd uanetstandard-test-suite
docker compose up -d
```

Then run:

```bash
./vendor/bin/pest tests/Integration/ --group=integration
```

### All Tests

```bash
./vendor/bin/pest
```

### Coverage

```bash
php -d pcov.enabled=1 ./vendor/bin/pest --coverage
```

Target: 99.5%+ code coverage.

## Test Structure

```
tests/
├── Unit/
│   ├── ConfigTest.php               # Config file validation
│   ├── FacadeTest.php               # Facade resolution
│   ├── MockClientTest.php           # MockClient integration
│   ├── OpcuaManagerTest.php         # Manager logic, configureClient
│   └── OpcuaServiceProviderTest.php # Provider registration
└── Integration/
    ├── Helpers/
    │   └── TestHelper.php           # Endpoints, config builders, daemon management
    ├── ConnectionTest.php           # Direct and managed connections
    ├── ConnectionStateTest.php      # isConnected, reconnect
    ├── ReadTest.php                 # Scalar, array, multi-read
    ├── WriteTest.php                # Scalar, array, multi-write
    ├── BrowseTest.php               # Browse, continuation, NodeClass filter
    ├── BrowseRecursiveTest.php      # browseAll, browseRecursive, maxDepth
    ├── TranslateBrowsePathTest.php  # resolveNodeId, translateBrowsePaths
    ├── MethodCallTest.php           # Method invocation
    ├── SubscriptionTest.php         # Create, monitor, publish, delete
    ├── SubscriptionTransferTest.php # transferSubscriptions, republish
    ├── HistoryReadAdvancedTest.php  # historyReadRaw, historyReadAtTime
    ├── TimeoutTest.php              # Timeout config and fluent API
    ├── AutoRetryTest.php            # Auto-retry config and fluent API
    ├── BatchingTest.php             # Server limits, transparent batching
    ├── StringNodeIdTest.php         # String NodeId in read, browse, readMulti
    ├── BuilderApiTest.php           # Fluent builders for read, write, paths
    ├── CacheTest.php                # setCache, invalidateCache, flushCache, useCache
    ├── LoggerTest.php               # setLogger, getLogger
    ├── TypeDiscoveryTest.php        # discoverDataTypes, getExtensionObjectRepository
    ├── DynamicVariableTest.php      # Counter, Random, SineWave, Timestamp
    └── ErrorHandlingTest.php        # BadNodeIdUnknown, read-only writes
```

## TestHelper

The `TestHelper` class provides:

- **Endpoint constants** — 8 test server endpoints (no-security, userpass, certificate, auto-accept, etc.)
- **Daemon management** — `startDaemon()` / `stopDaemon()` for integration tests
- **Config builders** — `makeDirectConfig()`, `makeManagedConfig()`, `createDirectManager()`, `createManagedManager()`
- **Browse helpers** — `browseToNode()`, `findRefByName()`, `safeDisconnect()`

All integration tests run in both **direct** and **managed** (daemon) modes using a `foreach` loop pattern.
