# Logging & Caching

## PSR-3 Logging

Every OPC UA client created by `OpcuaManager` automatically receives Laravel's logger. The logger captures connection events, retries, errors, and protocol details.

### Automatic Injection

The `OpcuaServiceProvider` resolves `Psr\Log\LoggerInterface` from the container and passes it to `OpcuaManager`. Every client created through the manager gets this logger via `setLogger()`.

### Override Per-Client

```php
use Psr\Log\NullLogger;

$client = Opcua::connect();
$client->setLogger(new NullLogger()); // disable logging for this client
```

### Override Per-Connection Config

Pass a logger explicitly in the connection config array (e.g. via `connectTo()`):

```php
$client = Opcua::connectTo('opc.tcp://...', [
    'logger' => new NullLogger(),
]);
```

An explicit logger in the config takes precedence over the default Laravel logger.

### What Gets Logged

| Level | Events |
|-------|--------|
| `DEBUG` | Protocol details, handshake steps |
| `INFO` | Connections, batch splits, type discovery |
| `WARNING` | Retry attempts |
| `ERROR` | Failures, exceptions |

### Daemon Logging

The `opcua:session` command uses a Laravel log channel for daemon events:

```dotenv
OPCUA_LOG_CHANNEL=stack
```

Or override via CLI:

```bash
php artisan opcua:session --log-channel=stderr
```

If not specified, the Laravel default log channel is used.

## PSR-16 Caching

Every OPC UA client created by `OpcuaManager` automatically receives Laravel's cache store. The cache stores browse results, endpoint discovery, and type discovery data.

### Automatic Injection

The `OpcuaServiceProvider` resolves `Psr\SimpleCache\CacheInterface` from the container and passes it to `OpcuaManager`. Every client gets this cache via `setCache()`.

### Per-Call Cache Control

Many browse operations accept a `useCache` parameter:

```php
// Use cache (default)
$refs = $client->browse('i=85', useCache: true);

// Skip cache for this call
$refs = $client->browse('i=85', useCache: false);

// Resolve with cache
$nodeId = $client->resolveNodeId('/Objects/Server', useCache: true);
```

Methods supporting `useCache`:
- `browse()`
- `browseAll()`
- `getEndpoints()`
- `resolveNodeId()`
- `discoverDataTypes()`

### Cache Invalidation

```php
// Invalidate a specific node
$client->invalidateCache('i=85');

// Flush all cached data
$client->flushCache();
```

### Override Per-Client

```php
use Gianfriaur\OpcuaPhpClient\Cache\InMemoryCache;

$client = Opcua::connect();
$client->setCache(new InMemoryCache(300)); // 300s TTL
```

### Disable Caching

```php
$client->setCache(null);
```

### Available Cache Drivers

The underlying client provides two built-in PSR-16 implementations:

| Driver | Class | Use case |
|--------|-------|----------|
| In-Memory | `InMemoryCache` | Default — per-process, lost on request end |
| File | `FileCache` | Persists across requests, good for CLI workers |

In a Laravel context, you'll typically rely on the automatically injected Laravel cache store (Redis, Memcached, etc.) rather than these built-in drivers.

### Daemon Caching

The `opcua:session` command injects a Laravel cache store into each OPC UA client the daemon creates:

```dotenv
OPCUA_CACHE_STORE=redis
```

Or override via CLI:

```bash
php artisan opcua:session --cache-store=redis
```

If not specified, the Laravel default cache store is used.
