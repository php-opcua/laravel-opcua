# OPC UA Laravel Client — AI Skills Reference

> Task-oriented recipes for AI coding assistants. Feed this file to your AI (Claude, Cursor, Copilot, GPT, etc.) so it knows how to use `php-opcua/laravel-opcua` correctly.

## How to use this file

Add this file to your AI assistant's context:
- **Claude Code**: reference per-session with `--add-file vendor/php-opcua/laravel-opcua/llms-skills.md`
- **Cursor**: copy into your project's rules directory — `cp vendor/php-opcua/laravel-opcua/llms-skills.md .cursor/rules/laravel-opcua.md`
- **GitHub Copilot**: copy or append the content into your project's `.github/copilot-instructions.md` file (create the file and directory if they don't exist). Copilot reads this file automatically for project-specific context
- **Other tools**: paste the content into your system prompt, project knowledge base, or context configuration

---

## What This Package Does

Laravel integration for OPC UA. Wraps [`opcua-client`](https://github.com/php-opcua/opcua-client) and [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager) with Laravel conventions: a `Facade`, `.env`-based configuration, named connections (like `config/database.php`), and an Artisan command for the session manager daemon.

**Key feature**: transparent session manager detection — if the daemon is running, connections persist across requests via `ManagedClient`; if not, direct `Client` connections are used. Zero code changes.

---

## Skill: Install and Configure

### When to use
The user wants to add OPC UA support to a Laravel application.

### Install
```bash
composer require php-opcua/laravel-opcua
php artisan vendor:publish --tag=opcua-config
```

### Configure (.env)
```dotenv
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
```

### Quick test
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();
$value = $client->read('i=2259');
echo $value->getValue(); // 0 = Running
$client->disconnect();
```

### Important rules
- The `Opcua` Facade is the main entry point — always use `PhpOpcua\LaravelOpcua\Facades\Opcua`
- `php artisan vendor:publish --tag=opcua-config` creates `config/opcua.php`
- Minimum config is just `OPCUA_ENDPOINT` in `.env`
- Logger and cache are automatically injected from Laravel's service container

---

## Skill: Read Values

### When to use
The user wants to read process variables from PLCs, sensors, or any OPC UA server in a Laravel app.

### Code
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

// Single read
$dv = $client->read('i=2259');
echo $dv->getValue();       // scalar value
echo $dv->statusCode;       // 0 = Good

// Force fresh read (bypass metadata cache)
$dv = $client->read('i=2259', refresh: true);

// Multi read — fluent builder
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->node('ns=2;s=Temperature')->value()
    ->execute();

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

// Multi read — array syntax
$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'ns=2;i=1001'],
]);

$client->disconnect();
```

### Important rules
- All methods accept string NodeIds: `'i=2259'`, `'ns=2;i=1001'`, `'ns=2;s=Temperature'`
- `getValue()` unwraps the OPC UA Variant and returns the PHP-native value
- Check `$dv->statusCode` — `0` means Good, use `StatusCode::isGood($dv->statusCode)` for proper checking
- `read($nodeId, refresh: true)` bypasses the metadata cache when `read_metadata_cache` is enabled

---

## Skill: Write Values

### When to use
The user wants to write setpoints, commands, or values to a PLC.

### Code
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

$client = Opcua::connect();

// Auto-detect type (default, recommended)
$status = $client->write('ns=2;i=1001', 42);

// Explicit type
$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

if (StatusCode::isGood($status)) {
    echo 'Write successful';
}

// Multi write — fluent builder
$results = $client->writeMulti()
    ->node('ns=2;i=1001')->int32(42)
    ->node('ns=2;i=1002')->double(3.14)
    ->node('ns=2;s=Label')->string('active')
    ->execute();

$client->disconnect();
```

### Important rules
- Auto-detect (`write($nodeId, $value)` without type) reads the node's DataType first, caches it
- `auto_detect_write_type` is `true` by default in config
- Common types: `BuiltinType::Boolean`, `Int16`, `Int32`, `UInt32`, `Float`, `Double`, `String`

---

## Skill: Browse the Address Space

### When to use
The user wants to discover what's available on the server — nodes, variables, methods.

### Code
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\NodeClass;

$client = Opcua::connect();

// Browse Objects folder
$refs = $client->browse('i=85');
foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId}) [{$ref->nodeClass->name}]\n";
}

// Filter by node class
$refs = $client->browse('i=85', nodeClasses: [NodeClass::Variable]);

// Browse with cache control
$refs = $client->browse('i=85', useCache: false);

// Browse all (automatic continuation)
$allRefs = $client->browseAll('i=85');

// Recursive browse
$tree = $client->browseRecursive('i=85', maxDepth: 3);

// Resolve path to NodeId
$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$value = $client->read($nodeId);

// Cache management
$client->invalidateCache('i=85');
$client->flushCache();

$client->disconnect();
```

---

## Skill: Use Named Connections

### When to use
The user has multiple OPC UA servers (PLCs, different production lines) and wants to connect to them by name.

### Config (config/opcua.php)
```php
'connections' => [
    'default' => [
        'endpoint' => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),
    ],
    'plc-line-1' => [
        'endpoint' => 'opc.tcp://10.0.0.10:4840',
        'username' => 'operator',
        'password' => 'pass123',
    ],
    'plc-line-2' => [
        'endpoint'           => 'opc.tcp://10.0.0.11:4840',
        'security_policy'    => 'Basic256Sha256',
        'security_mode'      => 'SignAndEncrypt',
        'client_certificate' => '/etc/opcua/certs/client.pem',
        'client_key'         => '/etc/opcua/certs/client.key',
    ],
],
```

### Code
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Default connection
$client = Opcua::connect();

// Named connection
$plc1 = Opcua::connect('plc-line-1');
$plc2 = Opcua::connect('plc-line-2');

$temp1 = $plc1->read('ns=2;s=Temperature')->getValue();
$temp2 = $plc2->read('ns=2;s=Temperature')->getValue();

// Disconnect all
Opcua::disconnectAll();

// Ad-hoc connection (not in config)
$client = Opcua::connectTo('opc.tcp://10.0.0.50:4840', [
    'username' => 'operator',
    'password' => 'secret',
], as: 'temp-plc');

// Switch default via .env
// OPCUA_CONNECTION=plc-line-1
```

### Important rules
- Named connections work like `config/database.php`
- `Opcua::connection('name')` returns the cached client (creates if needed)
- `Opcua::connect('name')` connects and returns the client
- `Opcua::connectTo()` creates ad-hoc connections not defined in config
- `Opcua::disconnect('name')` disconnects one, `Opcua::disconnectAll()` disconnects all
- Repeated calls to `connection('plc-1')` return the same cached instance

---

## Skill: Enable Session Manager for Persistent Connections

### When to use
The user wants to avoid the 50-200ms OPC UA handshake on every HTTP request.

### Start daemon
```bash
php artisan opcua:session
php artisan opcua:session --timeout=600 --max-sessions=100 --log-channel=stack --cache-store=redis
```

### Configuration (.env)
```dotenv
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_AUTH_TOKEN=my-secret
OPCUA_SESSION_TIMEOUT=600
OPCUA_MAX_SESSIONS=100
```

### Code — zero changes needed
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Same code as before — the switch is automatic
$client = Opcua::connect();
$value = $client->read('i=2259');
$client->disconnect();

// Check which mode is active
if (Opcua::isSessionManagerRunning()) {
    echo "Using persistent sessions (ManagedClient)";
} else {
    echo "Using direct connections (Client)";
}
```

### Supervisor config
```ini
[program:opcua-session-manager]
command=php /path/to/artisan opcua:session
directory=/path/to/laravel
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/opcua-session-manager.log
```

### Important rules
- The `Opcua` Facade auto-detects the daemon by checking if the Unix socket file exists
- If the socket exists → `ManagedClient` (persistent), if not → direct `Client` (per-request)
- **Zero code changes** between the two modes
- The Artisan `opcua:session` command uses Laravel's log channels and cache stores
- Use Supervisor or systemd to keep the daemon running in production

---

## Skill: Call Methods on the Server

### When to use
The user wants to invoke OPC UA methods — trigger operations, run diagnostics.

### Code
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
    echo $result->outputArguments[0]->value; // 7.0
}

$client->disconnect();
```

---

## Skill: Subscribe to Data Changes

### When to use
The user wants real-time monitoring of OPC UA variables.

### Code
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],
    ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],
]);

// Poll for notifications
$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo "Handle {$notif['clientHandle']}: {$notif['dataValue']->getValue()}\n";
}

// Modify monitored items
$client->modifyMonitoredItems($sub->subscriptionId, [
    ['monitoredItemId' => 1, 'samplingInterval' => 200.0],
]);

// Set triggering — when item 1 changes, report items 2 and 3
$client->setTriggering($sub->subscriptionId, 1, [2, 3], []);

// Clean up
$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

### Important rules
- Subscriptions require polling (`publish()`) — OPC UA is not WebSocket
- In plain Laravel (no daemon), subscriptions die with the request
- With `php artisan opcua:session` running, subscriptions persist across requests
- Always `deleteSubscription()` when done

---

## Skill: Read Historical Data

### When to use
The user wants past values — trend analysis, logs, aggregated statistics.

### Code
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\NodeId;

$client = Opcua::connect();

// Raw values
$history = $client->historyReadRaw(
    'ns=2;i=1001',
    new \DateTimeImmutable('-1 hour'),
    new \DateTimeImmutable('now'),
);

foreach ($history as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s')}] {$dv->getValue()}\n";
}

// Aggregated (e.g., average over 1-minute intervals)
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
    new \DateTimeImmutable('now'),
]);

$client->disconnect();
```

---

## Skill: Configure Security

### When to use
The user needs encrypted connections, authentication, or certificate trust management.

### Config (config/opcua.php)
```php
'connections' => [
    'secure-plc' => [
        'endpoint'           => 'opc.tcp://10.0.0.10:4840',
        'security_policy'    => 'Basic256Sha256',
        'security_mode'      => 'SignAndEncrypt',
        'username'           => env('OPCUA_USERNAME'),
        'password'           => env('OPCUA_PASSWORD'),
        'client_certificate' => '/etc/opcua/certs/client.pem',
        'client_key'         => '/etc/opcua/certs/client.key',
        'ca_certificate'     => '/etc/opcua/certs/ca.pem',
        'trust_store_path'   => '/var/opcua/trust',
        'trust_policy'       => 'fingerprint',
        'auto_accept'        => false,
    ],
],
```

### .env
```dotenv
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_USERNAME=operator
OPCUA_PASSWORD=secret
OPCUA_CLIENT_CERT=/path/to/client.pem
OPCUA_CLIENT_KEY=/path/to/client.key
OPCUA_TRUST_STORE_PATH=/var/opcua/trust
OPCUA_TRUST_POLICY=fingerprint
OPCUA_AUTO_ACCEPT=false
```

### Certificate trust at runtime
```php
use PhpOpcua\Client\Exception\UntrustedCertificateException;

try {
    $client = Opcua::connect();
} catch (UntrustedCertificateException $e) {
    echo "Untrusted: " . $e->getFingerprint();
    $client = Opcua::connection();
    $client->trustCertificate($e->getCertificate());
    $client = Opcua::connect(); // retry
}
```

### Important rules
- Security policies: `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`
- Security modes: `None`, `Sign`, `SignAndEncrypt`
- Auth methods: anonymous (default), username/password, X.509 certificate
- If `client_certificate`/`client_key` are omitted, a self-signed cert is auto-generated (dev only)
- Trust policies: `fingerprint`, `fingerprint+expiry`, `full`
- Store secrets in `.env`, never commit them

---

## Skill: Test Without a Real Server

### When to use
The user wants to unit test Laravel code that uses the `Opcua` Facade.

### Code
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

it('reads temperature from PLC', function () {
    $mock = MockClient::create()
        ->onRead('ns=2;s=Temperature', fn() => DataValue::ofDouble(23.5));

    $value = $mock->read('ns=2;s=Temperature');

    expect($value->getValue())->toBe(23.5);
    expect($value->statusCode)->toBe(StatusCode::Good);
    expect($mock->callCount('read'))->toBe(1);
});
```

### DataValue factory methods
```php
DataValue::ofBoolean(true);
DataValue::ofInt32(42);
DataValue::ofUInt32(100);
DataValue::ofDouble(3.14);
DataValue::ofFloat(2.5);
DataValue::ofString('hello');
DataValue::of($value, BuiltinType::Int32);
DataValue::bad(StatusCode::BadNodeIdUnknown);
```

### Inject into OpcuaManager
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0));

$manager = new OpcuaManager([
    'default' => 'default',
    'session_manager' => ['enabled' => false, 'socket_path' => '/tmp/nonexistent.sock'],
    'connections' => ['default' => ['endpoint' => 'opc.tcp://localhost:4840']],
]);

$ref = new \ReflectionProperty($manager, 'connections');
$ref->setValue($manager, ['default' => $mock]);

$value = $manager->connection('default')->read('i=2259');
echo $value->getValue(); // 0
```

### Important rules
- `MockClient` implements `OpcUaClientInterface` — works as a drop-in replacement
- Handlers: `onRead()`, `onWrite()`, `onBrowse()`, `onCall()`, `onResolveNodeId()`, `onGetEndpoints()`
- Call tracking: `callCount($method)`, `getCalls()`, `getCallsFor($method)`, `resetCalls()`

---

## Skill: Use Dependency Injection

### When to use
The user wants to inject `OpcuaManager` into controllers, jobs, or services.

### Code
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

class PlcController extends Controller
{
    public function status(OpcuaManager $opcua)
    {
        $client = $opcua->connect();
        $state = $client->read('i=2259')->getValue();
        $client->disconnect();

        return response()->json([
            'server_state' => $state,
            'daemon_active' => $opcua->isSessionManagerRunning(),
        ]);
    }
}
```

### Resolve from container
```php
$manager = app(OpcuaManager::class);
// or
$manager = app('opcua');
```

### Important rules
- `OpcuaManager` is registered as a singleton
- It automatically receives Laravel's logger and cache from the service container
- All Facade calls proxy to `OpcuaManager`

---

## Skill: Use PSR-14 Events

### When to use
The user wants to react to OPC UA operations — log reads, handle alarms, monitor connections.

### Code
```php
// In EventServiceProvider
use PhpOpcua\Client\Event\AfterRead;
use PhpOpcua\Client\Event\AlarmActivated;

protected $listen = [
    AfterRead::class => [LogOpcuaReads::class],
    AlarmActivated::class => [HandleAlarm::class],
];

// Listener
class LogOpcuaReads
{
    public function handle(AfterRead $event): void
    {
        Log::info("OPC UA read: {$event->nodeId} = {$event->dataValue->getValue()}");
    }
}
```

### Important rules
- 47 event types: connection, read/write, browse, subscriptions, method calls, alarms, trust store, etc.
- Zero overhead when no listeners are registered (`NullEventDispatcher`)
- Events are readonly DTOs with a `$client` property
- Bind `Psr\EventDispatcher\EventDispatcherInterface` in the container for automatic injection

---

## Configuration Reference

### Connection keys (config/opcua.php)

| Key | Default | Description |
|-----|---------|-------------|
| `endpoint` | `opc.tcp://localhost:4840` | Server URL |
| `security_policy` | `None` | Security policy name |
| `security_mode` | `None` | `None`, `Sign`, `SignAndEncrypt` |
| `username` / `password` | `null` | Username/password auth |
| `client_certificate` / `client_key` | `null` | Client cert (auto-generated if omitted) |
| `ca_certificate` | `null` | CA certificate |
| `user_certificate` / `user_key` | `null` | X.509 cert auth |
| `timeout` | `5.0` | Network timeout (seconds) |
| `auto_retry` | `null` | Max reconnection retries |
| `batch_size` | `null` | Max items per batch |
| `browse_max_depth` | `10` | Default `browseRecursive()` depth |
| `trust_store_path` | `null` | Trust store directory |
| `trust_policy` | `reject` | `reject`, `fingerprint`, `fingerprint+expiry`, `full` |
| `auto_accept` | `false` | TOFU mode |
| `auto_accept_force` | `false` | Force-accept changed certs |
| `auto_detect_write_type` | `true` | Auto-detect on `write()` |
| `read_metadata_cache` | `true` | Cache node metadata |

### Session manager keys

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Enable daemon auto-detection |
| `socket_path` | `storage/app/opcua-session-manager.sock` | Unix socket path |
| `timeout` | `600` | Session inactivity timeout |
| `auth_token` | `null` | Shared secret for IPC |
| `max_sessions` | `100` | Max concurrent sessions |

---

## Common Mistakes to Avoid

### 1. Forgetting to disconnect
```php
// WRONG — leaks connection
$client = Opcua::connect();
$value = $client->read('i=2259');
return response()->json(['value' => $value->getValue()]);

// CORRECT
$client = Opcua::connect();
try {
    $value = $client->read('i=2259');
    return response()->json(['value' => $value->getValue()]);
} finally {
    $client->disconnect();
}
```

### 2. Confusing Facade methods
```php
// connect() — returns a connected client
$client = Opcua::connect('plc-1');

// connection() — same as connect() in direct mode
$client = Opcua::connection('plc-1');

// connectTo() — ad-hoc endpoint not in config
$client = Opcua::connectTo('opc.tcp://10.0.0.50:4840');

// disconnect() vs disconnectAll()
Opcua::disconnect('plc-1');   // one connection
Opcua::disconnectAll();        // everything
```

### 3. Using old array access
```php
// WRONG — DTOs use public readonly properties, not arrays
$sub = $client->createSubscription(500.0);
echo $sub['subscriptionId'];

// CORRECT
echo $sub->subscriptionId;
```

### 4. Setting options after connect in direct mode
```php
// WRONG — Client is immutable after connection in direct mode
$client = Opcua::connect();
$client->setTimeout(10.0); // won't work

// CORRECT — configure in config/opcua.php or use connectTo with inline config
$client = Opcua::connectTo('opc.tcp://...', [
    'timeout' => 10.0,
]);
```
