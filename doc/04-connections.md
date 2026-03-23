# Connections

## Named Connections

Define connections in `config/opcua.php`:

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
],
```

### Connecting

```php
// Default connection
$client = Opcua::connect();

// Named connection
$client = Opcua::connect('plc-line-1');
```

### Getting a Connection Without Connecting

```php
$client = Opcua::connection('plc-line-1');
// Configure further, then connect manually
$client->setTimeout(15.0);
$client->connect('opc.tcp://10.0.0.10:4840');
```

### Switching the Default

```dotenv
OPCUA_CONNECTION=plc-line-1
```

## Ad-hoc Connections

Connect to endpoints not defined in config:

```php
// Minimal
$client = Opcua::connectTo('opc.tcp://10.0.0.50:4840');

// With inline config
$client = Opcua::connectTo('opc.tcp://10.0.0.99:4840', [
    'username'        => 'operator',
    'password'        => 'secret',
    'security_policy' => 'Basic256Sha256',
    'security_mode'   => 'SignAndEncrypt',
]);

// With a name for later retrieval
$client = Opcua::connectTo('opc.tcp://10.0.0.99:4840', as: 'temp-plc');
$same = Opcua::connection('temp-plc');
```

## Disconnecting

```php
// Single connection
Opcua::disconnect('plc-line-1');

// Default connection
Opcua::disconnect();

// All connections (including ad-hoc)
Opcua::disconnectAll();
```

After disconnecting, the next call to `connection()` or `connect()` creates a fresh client.

## Connection Caching

`OpcuaManager` caches client instances by name. Repeated calls to `connection('plc-1')` return the same instance:

```php
$a = Opcua::connection('plc-1');
$b = Opcua::connection('plc-1');
assert($a === $b); // true
```

Calling `disconnect()` removes the cached instance.

## Dependency Injection

The `OpcuaManager` is registered as a singleton and can be injected anywhere:

```php
use Gianfriaur\OpcuaLaravel\OpcuaManager;

class PlcController extends Controller
{
    public function read(OpcuaManager $opcua)
    {
        $client = $opcua->connect();
        $value = $client->read('ns=2;i=1001');
        $client->disconnect();

        return response()->json(['value' => $value->getValue()]);
    }
}
```

You can also resolve it from the container:

```php
$manager = app(OpcuaManager::class);
// or
$manager = app('opcua');
```

## Session Manager Auto-Detection

When creating a client, `OpcuaManager` checks for the daemon's Unix socket:

1. `session_manager.enabled` is `true` (default)
2. `session_manager.socket_path` file exists

If both conditions are met, a `ManagedClient` (daemon-proxied) is created. Otherwise, a direct `Client` is created. This is transparent — your application code doesn't change.

```php
if (Opcua::isSessionManagerRunning()) {
    // ManagedClient — session persists across requests
} else {
    // Direct Client — new TCP connection per request
}
```

## How configureClient Works

When a connection is created, `OpcuaManager::configureClient()` applies the following in order:

1. Security policy and mode
2. User credentials (username/password)
3. Client certificate and CA
4. User certificate
5. Timeout
6. Auto-retry
7. Batch size
8. Browse max depth
9. Logger (explicit config → Laravel default)
10. Cache (explicit config → Laravel default)
