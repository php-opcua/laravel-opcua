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

### Per-Connection Log Channel (v4.3+)

Each entry in `config/opcua.php → connections` accepts a `log_channel` key naming a Laravel log channel. The package resolves it lazily at connection time, so the config file stays free of `Log::channel(...)` Facade calls (which would explode if the config is loaded before the framework is fully booted):

```php
'connections' => [
    'default' => [
        'endpoint' => 'opc.tcp://localhost:4840',
        'log_channel' => 'stderr', // resolved via Laravel's log manager on demand
    ],
],
```

Or via env:

```dotenv
OPCUA_DEFAULT_LOG_CHANNEL=stderr
```

When omitted (or when the channel cannot be resolved), the manager falls back to the container-injected default logger.

### Logger Resolution Priority

When a connection is created, the manager picks a logger from the first source that yields one:

1. **Runtime override** — set via `OpcuaManager::setLogger()` / `useConsoleLogger()` (see below)
2. **Config `logger`** — a `LoggerInterface` instance in the connection config
3. **Config `log_channel`** — a Laravel channel name in the connection config
4. **Default logger** — the one auto-injected by the service provider

### Runtime Override (v4.3+)

`OpcuaManager` exposes `setLogger()` / `getLogger()` / `useConsoleLogger()` to swap the logger after the manager has been built. The override applies to every future connection and is best-effort propagated to existing connections that expose `setLogger()`:

```php
use Psr\Log\NullLogger;

Opcua::setLogger(new NullLogger()); // silence everything app-wide
$logger = Opcua::getLogger();        // retrieve the current override (or null)
```

The most common reason to reach for this is an Artisan command that wants OPC UA logs streamed to its `OutputInterface`, respecting `-v` / `-vv` / `-vvv`:

```php
use Illuminate\Console\Command;

class SyncOpcuaTags extends Command
{
    protected $signature = 'opcua:sync-tags';

    public function handle(): int
    {
        // Route client logs to the console, with millisecond timestamps.
        Opcua::useConsoleLogger($this->output);

        $client = Opcua::connect();
        // ...

        return self::SUCCESS;
    }
}
```

`useConsoleLogger()` wraps Symfony's `ConsoleLogger` (so `error`/`warning` are always shown, `notice` needs `-v`, `info` `-vv`, `debug` `-vvv`) and prepends `[YYYY-MM-DD HH:MM:SS.mmm]` to every line by default. Pass `dateFormat: null` to disable the prefix, or any DateTime format string to customize it:

```php
Opcua::useConsoleLogger($this->output, dateFormat: null);          // bare ConsoleLogger
Opcua::useConsoleLogger($this->output, dateFormat: 'H:i:s.v');     // time-only
```

### TimestampedLogger Decorator (v4.3+)

`PhpOpcua\LaravelOpcua\Logging\TimestampedLogger` is a generic PSR-3 decorator that prepends a formatted timestamp before forwarding to any inner logger. It is what `useConsoleLogger()` uses internally, but you can apply it to any logger you already have:

```php
use PhpOpcua\LaravelOpcua\Logging\TimestampedLogger;

$decorated = new TimestampedLogger($someLogger, 'Y-m-d H:i:s.v');
Opcua::setLogger($decorated);
```

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

## PSR-14 Event System (v4.0+)

The client supports PSR-14 event dispatching. When an event dispatcher is attached, the client fires events for every significant operation -- 47 event types in total covering connections, reads, writes, subscriptions, security, and more.

### Attaching an Event Dispatcher

```php
use Psr\EventDispatcher\EventDispatcherInterface;

$client = Opcua::connect();
$client->setEventDispatcher(app(EventDispatcherInterface::class));
```

### Automatic Injection via Laravel

If your application binds `Psr\EventDispatcher\EventDispatcherInterface` in the container, you can configure `OpcuaManager` to inject it automatically. The `OpcuaServiceProvider` will resolve it and pass it to every client created through the manager.

### Event Categories

| Category | Example Events | Description |
|----------|---------------|-------------|
| Connection | `Connected`, `Disconnected`, `Reconnecting` | Lifecycle of the TCP/secure channel |
| Read | `BeforeRead`, `AfterRead`, `ReadFailed` | Single and multi-read operations |
| Write | `BeforeWrite`, `AfterWrite`, `WriteFailed` | Single and multi-write operations |
| Browse | `BeforeBrowse`, `AfterBrowse` | Address space navigation |
| Subscription | `SubscriptionCreated`, `DataChangeNotification` | Pub/sub monitoring |
| Security | `CertificateTrusted`, `CertificateRejected`, `UntrustedCertificate` | Trust store decisions |
| Method | `BeforeCall`, `AfterCall` | Method invocations |
| Discovery | `EndpointsDiscovered`, `DataTypesDiscovered` | Endpoint and type discovery |

### Listening for Events

Use Laravel's event system or any PSR-14 compatible listener provider:

```php
use PhpOpcua\Client\Events\AfterRead;

// In a Laravel EventServiceProvider
protected $listen = [
    AfterRead::class => [
        LogOpcuaReads::class,
    ],
];
```

### Per-Client Override

```php
$client = Opcua::connect();
$client->setEventDispatcher(null); // disable events for this client
```

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
use PhpOpcua\Client\Cache\InMemoryCache;

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

## Read Metadata Cache (v4.0+)

When `read_metadata_cache` is enabled, the client caches non-Value attribute reads (DisplayName, DataType, Description, etc.). These attributes rarely change, so caching them avoids redundant round-trips.

### Configuration

```dotenv
OPCUA_READ_METADATA_CACHE=true
```

Or in `config/opcua.php`:

```php
'connections' => [
    'default' => [
        // ...
        'read_metadata_cache' => true,
    ],
],
```

### Fluent API

```php
$client = Opcua::connect();
$client->setReadMetadataCache(true);
```

### Refresh Parameter

The `read()` method accepts a `refresh` parameter to bypass the metadata cache for a specific call:

```php
// Normal read — uses metadata cache if enabled
$dv = $client->read('ns=2;i=1001');

// Force a fresh read from the server, ignoring any cached metadata
$dv = $client->read('ns=2;i=1001', refresh: true);
```

This is useful when you know the server has changed metadata (e.g. after reconfiguration) and you want to pick up the latest values.

## Write Type Auto-Detection Cache (v4.0+)

When `auto_detect_write_type` is enabled (the default), the client discovers the OPC UA data type of a node before writing, so you can omit the type parameter on `write()`. The discovered types are cached for subsequent writes to the same node.

```dotenv
OPCUA_AUTO_DETECT_WRITE_TYPE=true
```

This caching works alongside PSR-16 caching. The type discovery results are stored in the same cache backend configured for the client.
