# Changelog

## [3.0.0] - 2026-03-23

### Changed

- **BREAKING:** Updated dependency `gianfriaur/opcua-php-client` from `^2.0.0` to `^3.0.0`.
- **BREAKING:** Updated dependency `gianfriaur/opcua-php-client-session-manager` from `^2.0.0` to `^3.0.0`.
- **BREAKING:** Service response methods now return typed DTOs instead of arrays:
  - `createSubscription()` → `SubscriptionResult` (access via `->subscriptionId`, `->revisedPublishingInterval`, etc.)
  - `createMonitoredItems()` → `MonitoredItemResult[]` (access via `->statusCode`, `->monitoredItemId`, etc.)
  - `createEventMonitoredItem()` → `MonitoredItemResult`
  - `call()` → `CallResult` (access via `->statusCode`, `->outputArguments`, etc.)
  - `browseWithContinuation()` / `browseNext()` → `BrowseResultSet` (access via `->references`, `->continuationPoint`)
  - `publish()` → `PublishResult` (access via `->subscriptionId`, `->notifications`, etc.)
  - `translateBrowsePaths()` → `BrowsePathResult[]` (access via `->statusCode`, `->targets`)
- **BREAKING:** Browse methods `nodeClassMask: int` parameter changed to `nodeClasses: NodeClass[]`. Use `[NodeClass::Object, NodeClass::Variable]` instead of bitmask integers.
- **BREAKING:** Named parameters renamed: `readMulti($items)` → `readMulti($readItems)`, `writeMulti($items)` → `writeMulti($writeItems)`, `createMonitoredItems(..., $items)` → `createMonitoredItems(..., $monitoredItems)`.
- **BREAKING:** Type classes now expose `public readonly` properties. Getter methods are deprecated: `$ref->getNodeId()` → `$ref->nodeId`, `$dv->getStatusCode()` → `$dv->statusCode`, `$dv->getValue()` (convenience method, unchanged), etc.
- Session manager daemon now uses Laravel log channels and cache stores instead of custom `log_file`/`cache_driver` config keys.
- `OpcuaServiceProvider` now injects Laravel's default PSR-3 logger and PSR-16 cache into `OpcuaManager` for automatic client configuration.

### Added

- **String NodeId parameters everywhere.** All methods accepting `NodeId` now also accept OPC UA string format: `'i=2259'`, `'ns=2;s=MyNode'`, `'ns=2;i=1001'`.
- **Fluent Builder API.** `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()` return a fluent builder when called without arguments.
- **PSR-3 Logging.** `setLogger()` and `getLogger()` exposed on client instances and via facade. Laravel's logger is injected by default.
- **PSR-16 Cache.** `setCache()`, `getCache()`, `invalidateCache()`, `flushCache()` for browse result caching. Laravel's cache is injected by default.
- **Per-call cache control.** `useCache` parameter on `browse()`, `browseAll()`, `getEndpoints()`, `resolveNodeId()`.
- **Automatic DataType discovery.** `discoverDataTypes()` discovers server-defined structured types and registers dynamic codecs.
- **Extension object repository.** `getExtensionObjectRepository()` for custom structured type handling.
- **Subscription transfer.** `transferSubscriptions()` and `republish()` for session recovery.
- **DataValue factory methods.** `DataValue::ofInt32()`, `DataValue::ofString()`, etc. for convenient value creation.
- **MockClient for testing.** Drop-in test double via `MockClient::create()` with call recording and handler registration.
- Config keys `log_channel` and `cache_store` in `session_manager` section for daemon logging/caching via Laravel channels/stores.
- Artisan command options `--log-channel` and `--cache-store` for `php artisan opcua:session`.
- Updated `Opcua` facade PHPDoc with all new v3.0 method signatures including `NodeClass[]`, `BrowseResultSet`, `SubscriptionResult`, `CallResult`, `PublishResult`, builder return types, and cache/logger methods.
- Integration tests for string NodeId, builder API, cache operations, logger, and type discovery.

### Removed

- `buildDaemonCache()` and `buildDaemonLogger()` from `OpcuaManager` (daemon now uses Laravel's log channels and cache stores directly).
- Config keys `log_file`, `log_level`, `cache_driver`, `cache_path`, `cache_ttl` (replaced by `log_channel` and `cache_store`).

## [2.0.0] - 2026-03-20

### Changed

- **BREAKING:** Updated dependency `gianfriaur/opcua-php-client` from `^1.1.0` to `^2.0.0`.
- **BREAKING:** Updated dependency `gianfriaur/opcua-php-client-session-manager` from `^1.1.0` to `^2.0.0`.
- **BREAKING:** `browse()` and `browseWithContinuation()` `$direction` parameter changed from `int` to `BrowseDirection` enum. Calls using `direction: 0` or `direction: 1` must be updated to `BrowseDirection::Forward` / `BrowseDirection::Inverse`.
- Updated CI test server suite from `GianfriAur/opcua-test-server-suite@v1.1.2` to `@v1.1.4`.

### Added

- **Timeout configuration.** New `timeout` key in connection config (seconds). Also available via `Opcua::setTimeout()` fluent API. Default: `5.0`.
- **Auto-retry configuration.** New `auto_retry` key in connection config. Automatically reconnects and retries on `ConnectionException`. Also available via `Opcua::setAutoRetry()`. Default: `0` before connect, `1` after connect.
- **Automatic batching configuration.** New `batch_size` key in connection config. When enabled, `readMulti`/`writeMulti` calls are transparently split into batches when exceeding server limits. Set to `0` to disable. Also available via `Opcua::setBatchSize()`.
- **Browse max depth configuration.** New `browse_max_depth` key in connection config. Controls default depth for `browseRecursive()`. Also available via `Opcua::setDefaultBrowseMaxDepth()`. Default: `10`.
- **Connection state management.** New methods exposed via facade: `reconnect()`, `isConnected()`, `getConnectionState()`.
- **browseAll().** Browse with automatic continuation point handling — returns all references in one call.
- **browseRecursive().** Recursive tree traversal returning `BrowseNode[]` with configurable depth and cycle detection.
- **translateBrowsePaths().** OPC UA TranslateBrowsePathsToNodeIds service for batch path resolution.
- **resolveNodeId().** Human-readable path resolution (e.g. `/Objects/Server/ServerStatus`).
- **Server operation limits discovery.** `getServerMaxNodesPerRead()` and `getServerMaxNodesPerWrite()` expose discovered limits.
- **historyReadProcessed()** and **historyReadAtTime()** methods exposed via facade.
- Updated `Opcua` facade PHPDoc with all new v2.0 method signatures for IDE autocompletion.
- New `config/opcua.php` keys: `timeout`, `auto_retry`, `batch_size`, `browse_max_depth` per connection.
- `OpcuaManager::configureClient()` applies new v2.0 settings (timeout, auto-retry, batching, browse depth) to client instances.
- Unit tests for `configureClient` v2.0 options (timeout, auto_retry, batch_size, browse_max_depth) — all null/non-null paths.
- Integration tests: `ConnectionStateTest`, `TimeoutTest`, `AutoRetryTest`, `BatchingTest`, `BrowseRecursiveTest`, `TranslateBrowsePathTest`, `HistoryReadAdvancedTest`.

## [1.1.0] - 2026-03-18

### Changed

- Updated dependencies `gianfriaur/opcua-php-client` and `gianfriaur/opcua-php-client-session-manager` from `^1.0.1` to `^1.1.0`.

### Added

- **Auto-generated client certificate support.** When a connection is configured with a `security_policy` and `security_mode` but without `client_certificate`/`client_key`, the underlying client automatically generates an in-memory self-signed certificate. The behaviour is fully transparent — no changes to the config file or application code are required.
- Config comment on `client_certificate`/`client_key` keys documenting the auto-generation fallback.
- Unit tests (`configureClient certificate behavior`) covering: no call to `setClientCertificate` when cert is absent, no call when only one of cert/key is provided, correct call when both are present, and correct forwarding of the optional `ca_certificate`.
- Integration tests for connecting with `Basic256Sha256`/`SignAndEncrypt` and no explicit client certificate, in both direct mode and managed (session manager daemon) mode.

## [1.0.1] - 2026-03-16

### Added

- Initial release. Laravel service provider, facade, `OpcuaManager` (multi-connection, session-manager auto-detection), and `opcua:session` Artisan command.
