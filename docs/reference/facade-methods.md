---
eyebrow: 'Docs · Reference'
lede:    'Every method on the Opcua facade. Manager methods (connection management) plus the full OpcUaClientInterface surface proxied to the default connection.'

see_also:
  - { href: './opcua-manager-api.md',                meta: '5 min' }
  - { href: './exceptions.md',                       meta: '4 min' }
  - { href: '../using-the-client/facade-vs-injection.md', meta: '5 min' }

prev: { label: 'Filament',         href: '../integrations/filament.md' }
next: { label: 'OpcuaManager API', href: './opcua-manager-api.md' }
---

# Facade methods

The `Opcua` facade resolves to the `OpcuaManager` singleton (the
container alias `'opcua'` points to `OpcuaManager::class` — see
[OpcuaManager API](./opcua-manager-api.md)).

Two groups of methods are accessible through the facade:

1. **Manager methods** — connection lifecycle, defined directly on
   `OpcuaManager`.
2. **Client methods** — every method on
   `PhpOpcua\Client\OpcUaClientInterface`, proxied to the default
   connection via `OpcuaManager::__call()`.

The authoritative `@method` list lives in
`src/Facades/Opcua.php` (the IDE-readable docblock).

## Connection management (manager methods)

<!-- @method name="connection" -->
Returns the client for a named connection, creating and caching it on
first use.

**Signature**

```php
Opcua::connection(?string $name = null): OpcUaClientInterface
```

| Parameter | Type      | Description                                            |
| --------- | --------- | ------------------------------------------------------ |
| `$name`   | `?string` | Connection name; defaults to `config('opcua.default')` |

**Throws** `\InvalidArgumentException` if the connection is not
defined in `config/opcua.php`.
<!-- @endmethod -->

<!-- @method name="connect" -->
Like `connection()`, but for managed-mode clients it also forces an
explicit `connect()` to the configured endpoint (managed clients are
created in a disconnected state).

**Signature**

```php
Opcua::connect(?string $name = null): OpcUaClientInterface
```
<!-- @endmethod -->

<!-- @method name="connectTo" -->
Opens a client for an arbitrary endpoint not defined in the config
file. The endpoint URL is the **first positional argument**, not a
key inside the config array.

**Signature**

```php
Opcua::connectTo(string $endpointUrl, array $config = [], ?string $as = null): OpcUaClientInterface
```

| Parameter      | Type      | Description                                                                  |
| -------------- | --------- | ---------------------------------------------------------------------------- |
| `$endpointUrl` | `string`  | OPC UA endpoint URL (e.g. `opc.tcp://host:4840`)                             |
| `$config`      | `array`   | Optional connection config — same keys as a `connections.*` entry            |
| `$as`          | `?string` | Optional name to store the connection under (default: `ad-hoc:<endpointUrl>`) |

Returns the connected client. The instance is also tracked
internally, so it gets closed by `disconnectAll()` and can be reached
again by calling `Opcua::connection($as)`.
<!-- @endmethod -->

<!-- @method name="disconnect" -->
Closes a connection by name and removes it from the manager's cache.

**Signature**

```php
Opcua::disconnect(?string $name = null): void
```

| Parameter | Type      | Description                              |
| --------- | --------- | ---------------------------------------- |
| `$name`   | `?string` | Connection name; defaults to the default |

There is **no overload that accepts a client instance** — pass the
name (or the `$as` name used at `connectTo()` time). In managed mode
this triggers a `close` IPC frame against the daemon, releasing the
daemon-side session (so the next call rebuilds it).
<!-- @endmethod -->

<!-- @method name="disconnectAll" -->
Closes every cached connection.

**Signature**

```php
Opcua::disconnectAll(): void
```

The manager does **not** register a `register_shutdown_function()`
hook — call this explicitly (typically in a queue worker's
`afterTerminate` callback, or in an Octane `RequestTerminated`
listener) if you need deterministic cleanup.
<!-- @endmethod -->

<!-- @method name="isSessionManagerRunning" -->
Checks whether the session manager daemon's socket file exists.

**Signature**

```php
Opcua::isSessionManagerRunning(): bool
```

For Unix-domain endpoints this is a `file_exists($socketPath)` check
— **not** a live `ping` round-trip. For TCP endpoints (Windows) the
method always returns `true` and the first IPC call surfaces the real
status via a `DaemonException`.
<!-- @endmethod -->

<!-- @method name="getDefaultConnection" -->
Returns the default connection name (the value of `config('opcua.default')`).

**Signature**

```php
Opcua::getDefaultConnection(): string
```
<!-- @endmethod -->

## Client methods (proxied to the default connection)

The methods below live on `OpcUaClientInterface` and are forwarded to
the default connection by `OpcuaManager::__call()`. To target a
non-default connection use `Opcua::connection('other')->read(...)`.

### Read

<!-- @method name="read" -->
Reads a single attribute of a single node.

**Signature**

```php
Opcua::read(NodeId|string $nodeId, int $attributeId = 13, bool $refresh = false): DataValue
```

Default `$attributeId` is `13` (Value). Pass other `AttributeId::*`
constants for DisplayName, DataType, etc. `$refresh` bypasses the
read-metadata cache when true.

Use `$dv->getValue()` to read the value — the `DataValue` class
exposes the value via that accessor (the underlying `Variant` is
private).
<!-- @endmethod -->

<!-- @method name="readMulti" -->
Reads multiple attributes / nodes. Call **without arguments** to get
back a fluent `ReadMultiBuilder`; with `?array $readItems` it runs
immediately and returns an array of `DataValue`.

**Signature**

```php
Opcua::readMulti(?array $readItems = null): array|ReadMultiBuilder
```

```php
$values = Opcua::readMulti()
    ->node('ns=2;s=Speed')
    ->node('ns=2;s=Temp')->attribute(AttributeId::Value)
    ->execute();
```

The builder's `execute()` always returns an array of `DataValue`,
keyed positionally.
<!-- @endmethod -->

### Write

<!-- @method name="write" -->
Writes the Value attribute of a node. Returns the OPC UA status code
as `int` — check it with `StatusCode::isGood($code)`.

**Signature**

```php
Opcua::write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int
```

When `$type` is `null` and `auto_detect_write_type` is enabled, the
client reads the node's `DataType` attribute to derive the
`BuiltinType` automatically (see
[Operations · Writing](../operations/writing.md)).
<!-- @endmethod -->

<!-- @method name="writeMulti" -->
Multi-write with optional builder. Returns `int[]` of status codes
when called with an array; returns a `WriteMultiBuilder` when called
with `null`.

**Signature**

```php
Opcua::writeMulti(?array $writeItems = null): array|WriteMultiBuilder
```

Builder usage:

```php
Opcua::writeMulti()
    ->node('ns=2;s=Setpoint')->value(75.0)
    ->node('ns=2;s=Enabled')->typed(true, BuiltinType::Boolean)
    ->execute();
```

`value()` takes **one** argument (auto-detect type). `typed()` takes
`(value, BuiltinType)`. `node()` is mandatory before each `value()` /
`typed()`.
<!-- @endmethod -->

### Browse

<!-- @method name="browse" -->
Returns the immediate children of a node.

**Signature**

```php
Opcua::browse(
    NodeId|string $nodeId,
    BrowseDirection $direction = BrowseDirection::Forward,
    ?NodeId $referenceTypeId = null,
    bool $includeSubtypes = true,
    array $nodeClasses = [],
    bool $useCache = true,
): array
```

Returns `ReferenceDescription[]`. See
[Operations · Browsing](../operations/browsing.md).
<!-- @endmethod -->

<!-- @method name="browseRecursive" -->
Walks the subtree under a node. `$maxDepth` is the **third** positional
parameter — use the named argument (`maxDepth:`) if you don't need to
set `$direction`.

**Signature**

```php
Opcua::browseRecursive(
    NodeId|string $nodeId,
    BrowseDirection $direction = BrowseDirection::Forward,
    ?int $maxDepth = null,
    ?NodeId $referenceTypeId = null,
    bool $includeSubtypes = true,
    array $nodeClasses = [],
): array
```

Returns `BrowseNode[]`.
<!-- @endmethod -->

<!-- @method name="translateBrowsePaths" -->
Multi-path translation. Returns `BrowsePathResult[]` when called with
an array, or a `BrowsePathsBuilder` when called with `null`.

**Signature**

```php
Opcua::translateBrowsePaths(?array $browsePaths = null): array|BrowsePathsBuilder
```
<!-- @endmethod -->

<!-- @method name="resolveNodeId" -->
Resolves a browse-path string to a `NodeId`.

**Signature**

```php
Opcua::resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId
```
<!-- @endmethod -->

### Method calls

<!-- @method name="call" -->
Invokes an OPC UA method. Returns a `CallResult` object, **not** a
`[status, outputs]` tuple.

**Signature**

```php
Opcua::call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
```

Use `$result->statusCode` and `$result->outputArguments` to read the
fields.
<!-- @endmethod -->

### Subscriptions

<!-- @method name="createSubscription" -->
Creates an OPC UA subscription on the server.

**Signature**

```php
Opcua::createSubscription(
    float $publishingInterval = 500.0,
    int $lifetimeCount = 2400,
    int $maxKeepAliveCount = 10,
    int $maxNotificationsPerPublish = 0,
    bool $publishingEnabled = true,
    int $priority = 0,
): SubscriptionResult
```

Returns a `SubscriptionResult` carrying the server-assigned
`$subscriptionId`. Pass that ID to `createMonitoredItems()` /
`createEventMonitoredItem()` to attach items.
<!-- @endmethod -->

<!-- @method name="createMonitoredItems" -->
Creates monitored items on a subscription. Pass `null` to get the
builder, or `array` to run immediately.

**Signature**

```php
Opcua::createMonitoredItems(int $subscriptionId, ?array $items = null): array|MonitoredItemsBuilder
```
<!-- @endmethod -->

<!-- @method name="createEventMonitoredItem" -->
Creates a single event-shaped monitored item.

**Signature**

```php
Opcua::createEventMonitoredItem(
    int $subscriptionId,
    NodeId|string $nodeId,
    array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
    int $clientHandle = 1,
): MonitoredItemResult
```
<!-- @endmethod -->

<!-- @method name="deleteSubscription" -->
Deletes a subscription on the server.

**Signature**

```php
Opcua::deleteSubscription(int $subscriptionId): int
```
<!-- @endmethod -->

<!-- @method name="publish" -->
Drives the publish loop. Notifications flow out through PSR-14
events (`DataChangeReceived`, `EventNotificationReceived`, alarm
events) — see [Events · Overview](../events/overview.md).

**Signature**

```php
Opcua::publish(array $acknowledgements = []): PublishResult
```
<!-- @endmethod -->

### History

<!-- @method name="historyReadRaw" -->
Reads a contiguous range of historical values.

**Signature**

```php
Opcua::historyReadRaw(
    NodeId|string $nodeId,
    ?\DateTimeImmutable $startTime = null,
    ?\DateTimeImmutable $endTime = null,
    int $numValuesPerNode = 0,
    bool $returnBounds = false,
): array
```

Returns `DataValue[]`.
<!-- @endmethod -->

<!-- @method name="historyReadProcessed" -->
Reads server-aggregated historical values (averages, min/max, …).

**Signature**

```php
Opcua::historyReadProcessed(
    NodeId|string $nodeId,
    \DateTimeImmutable $startTime,
    \DateTimeImmutable $endTime,
    float $processingInterval,
    NodeId $aggregateType,
): array
```
<!-- @endmethod -->

<!-- @method name="historyReadAtTime" -->
Reads historical values at a discrete set of timestamps.

**Signature**

```php
Opcua::historyReadAtTime(NodeId|string $nodeId, array $timestamps): array
```
<!-- @endmethod -->

### Discovery

<!-- @method name="getEndpoints" -->
Returns the server's published endpoint descriptions.

**Signature**

```php
Opcua::getEndpoints(string $endpointUrl, bool $useCache = true): array
```

Returns `EndpointDescription[]` — one per (security policy,
security mode) the server advertises.
<!-- @endmethod -->

<!-- @method name="discoverDataTypes" -->
Triggers discovery of server-defined complex DataTypes and seeds the
extension-object repository.

**Signature**

```php
Opcua::discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
```

Returns the number of types discovered.
<!-- @endmethod -->

### Trust store

<!-- @method name="trustCertificate" -->
Persists a server certificate (DER bytes) into the trust store.

**Signature**

```php
Opcua::trustCertificate(string $certDer): void
```
<!-- @endmethod -->

<!-- @method name="untrustCertificate" -->
Removes a trusted certificate by SHA-1 fingerprint.

**Signature**

```php
Opcua::untrustCertificate(string $fingerprint): void
```

Note: trust-store fingerprints are **SHA-1**, not SHA-256 — see
[Security · Trust store](../security/trust-store.md).
<!-- @endmethod -->

### Other accessors

| Method                              | Returns                       | Notes                                        |
| ----------------------------------- | ----------------------------- | -------------------------------------------- |
| `reconnect(): void`                 | —                             | Tears down and rebuilds the session          |
| `isConnected(): bool`               | bool                          |                                              |
| `getConnectionState(): ConnectionState` | enum                      |                                              |
| `getLogger(): LoggerInterface`      | PSR-3 logger                  |                                              |
| `getEventDispatcher(): EventDispatcherInterface` | PSR-14 dispatcher |                                              |
| `getCache(): ?CacheInterface`       | PSR-16 cache (nullable)       |                                              |
| `invalidateCache(NodeId\|string $nodeId): void` | —                  |                                              |
| `flushCache(): void`                | —                             |                                              |
| `getTimeout(): float`               |                               |                                              |
| `getAutoRetry(): int`               |                               |                                              |

See `src/Facades/Opcua.php` for the complete `@method` list.

## Testing helpers (inherited from `Illuminate\Support\Facades\Facade`)

These are **not** package methods — they come from the base Laravel
`Facade` class and are available on every facade.

- `Opcua::partialMock(): Mockery\MockInterface` — Mockery partial mock
- `Opcua::spy(): Mockery\MockInterface` — Mockery spy
- `Opcua::shouldReceive(...)`, `Opcua::swap(...)` — standard Mockery /
  Facade entry points

There is **no `Opcua::fake()`** method shipped by either Laravel's
`Facade` base class or this package. Use
[Mocking the facade](../testing/mocking-the-facade.md) for the
supported patterns.

## Implementation notes

`OpcuaServiceProvider::register()` registers the manager as

```php
$this->app->singleton(OpcuaManager::class, function ($app) { ... });
$this->app->alias(OpcuaManager::class, 'opcua');
```

so the **primary binding** is on `OpcuaManager::class` and `'opcua'`
is an alias to it. The facade resolves `'opcua'` via
`getFacadeAccessor()` — both reach the same singleton.

Method calls dispatch through `OpcuaManager::__call()`, which forwards
to `$this->connection()->$method(...$parameters)` — the **default**
connection's client.

## Where to read next

- [OpcuaManager API](./opcua-manager-api.md) — the same surface via
  dependency injection.
- [Artisan commands](./artisan-commands.md) — the artisan CLI
  surface.
- [Exceptions](./exceptions.md) — every exception type the
  facade can raise.
