# Changelog

## [4.0.0] - 2026-04-7

### Changed

- **BREAKING:** Package rebranded from `gianfriaur/opcua-laravel-client` to `php-opcua/laravel-opcua`.
- **BREAKING:** Namespace changed from `Gianfriaur\OpcuaLaravel` to `PhpOpcua\LaravelOpcua`.
- **BREAKING:** Updated dependency `php-opcua/opcua-client` from `^3.0.0` to `^4.0.0` (was `gianfriaur/opcua-php-client`).
- **BREAKING:** Updated dependency `php-opcua/opcua-session-manager` from `^3.0.0` to `^4.0.0` (was `gianfriaur/opcua-php-client-session-manager`).
- **BREAKING:** Direct mode now uses `ClientBuilder` → `Client` pattern. Configuration methods (`setSecurityPolicy`, `setTimeout`, `setAutoRetry`, `setBatchSize`, `setDefaultBrowseMaxDepth`, `setLogger`, `setCache`) moved from `Client` to `ClientBuilder`. All configuration is applied from `config/opcua.php` before connection. The `connection()` method for direct mode now returns a connected `Client` (auto-connects to the configured endpoint). The pattern of calling `connection()` then manually configuring and connecting is no longer supported for direct mode. Managed mode (via session manager daemon) is unchanged.
- **BREAKING:** `write()` type parameter is now nullable: `write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null)`. Pass `null` (or omit) for automatic type detection.
- **BREAKING:** `read()` has a new `bool $refresh = false` parameter for bypassing the read metadata cache.
- **BREAKING:** `OpcUaClientInterface` no longer has setter methods (`setLogger`, `setCache`, `setTimeout`, etc.). These are now on `ClientBuilderInterface` for direct mode and remain on `ManagedClient` for managed mode.
- **BREAKING:** `ExtensionObject` values are now returned as `ExtensionObject` readonly DTOs instead of `array|object`.
- `OpcuaManager` internally split into `configureBuilder()` (for direct mode via `ClientBuilderInterface`) and `configureManagedClient()` (for managed mode via `ManagedClient`). The old `configureClient()` method has been removed.
- Removed setter `@method` annotations from Facade (`setLogger`, `setCache`, `setTimeout`, `setAutoRetry`, `setBatchSize`, `setDefaultBrowseMaxDepth`) since they are no longer on `OpcUaClientInterface`.
- Updated CI test server suite from `php-opcua/opcua-test-suite@v1.1.5` to `php-opcua/uanetstandard-test-suite@v1.0.0`.
- Updated all documentation references: `opcua-php-client` to `opcua-client`, `opcua-php-client-session-manager` to `opcua-session-manager`, `opcua-test-server-suite` to `uanetstandard-test-suite`, `opcua-laravel-client` to `laravel-opcua`.
- Added "Tested against the OPC UA reference implementation" disclaimer to README.
- Added "Versioning" section to README.
- Aligned Ecosystem table with `opcua-client` (added `opcua-cli`, `opcua-client-nodeset`).

### Added

- **PSR-14 Event System.** 47 granular events for connections, sessions, reads, writes, browses, subscriptions, alarms, retries, cache, and certificates. Configure via `event_dispatcher` in connection config or bind `EventDispatcherInterface` in the Laravel container.
- **Server Trust Store.** New config keys `trust_store_path`, `trust_policy` (`fingerprint`, `fingerprint+expiry`, `full`), `auto_accept`, `auto_accept_force` per connection. For direct mode, creates a `FileTrustStore`. For managed mode, forwards via `ManagedClient::setTrustStorePath()`.
- **Write type auto-detection.** New `auto_detect_write_type` config key (default: `true`). When enabled, the `write()` method auto-detects the OPC UA type by reading node metadata, then caches the result.
- **Read metadata cache.** New `read_metadata_cache` config key (default: `false`). Caches non-Value attribute reads (DisplayName, BrowseName, DataType, etc.) via PSR-16. The `read($nodeId, refresh: true)` parameter bypasses the cache.
- **`modifyMonitoredItems()`.** Change sampling interval, queue size, or client handle on existing monitored items without recreation. Returns `MonitoredItemModifyResult[]`.
- **`setTriggering()`.** Configure triggering links between monitored items. Returns `SetTriggeringResult` with per-link status codes.
- **Certificate trust management via Facade.** `trustCertificate(string $certDer)` and `untrustCertificate(string $fingerprint)` for programmatic trust store management.
- **Event dispatcher injection.** `OpcuaServiceProvider` resolves `EventDispatcherInterface` from the Laravel container (if bound) and injects it into `OpcuaManager`.
- New config keys per connection: `trust_store_path`, `trust_policy`, `auto_accept`, `auto_accept_force`, `auto_detect_write_type`, `read_metadata_cache`.
- New `.env` variables: `OPCUA_TRUST_STORE_PATH`, `OPCUA_TRUST_POLICY`, `OPCUA_AUTO_ACCEPT`, `OPCUA_AUTO_ACCEPT_FORCE`, `OPCUA_AUTO_DETECT_WRITE_TYPE`, `OPCUA_READ_METADATA_CACHE`.
- New Facade `@method` annotations: `getEventDispatcher()`, `getTrustStore()`, `getTrustPolicy()`, `trustCertificate()`, `untrustCertificate()`, `modifyMonitoredItems()`, `setTriggering()`.
- New exception types from dependencies: `UntrustedCertificateException`, `WriteTypeDetectionException`, `WriteTypeMismatchException`.
- Added `psr/event-dispatcher` ^1.0 to dependencies.
- Unit tests for `configureBuilder` (direct mode) and `configureManagedClient` (managed mode) covering all v4.0 config options: trust store, trust policy, auto-accept, auto-detect write type, read metadata cache, event dispatcher.
- Unit tests for `resolveTrustPolicy()` helper.
- Unit test for event dispatcher injection in `OpcuaServiceProvider`.
- Config test for new v4.0 config keys.
- **AI-Ready documentation.** Added `llms-skills.md` with 13 task-oriented recipes for AI coding assistants (install, read, write, browse, named connections, session manager, methods, subscriptions, history, security, testing, dependency injection, events). Designed to be fed to Claude, Cursor, Copilot, ChatGPT, and other AI tools so they can generate correct code for Laravel OPC UA integration.
- Added AI-Ready section to README with instructions for integrating with Claude Code, Cursor, GitHub Copilot, and other AI tools.

## [3.0.0] - 2026-03-23

### Changed

- **BREAKING:** Updated dependency `php-opcua/opcua-client` from `^2.0.0` to `^3.0.0`.
- **BREAKING:** Updated dependency `php-opcua/opcua-session-manager` from `^2.0.0` to `^3.0.0`.
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

- **BREAKING:** Updated dependency `php-opcua/opcua-client` from `^1.1.0` to `^2.0.0`.
- **BREAKING:** Updated dependency `php-opcua/opcua-session-manager` from `^1.1.0` to `^2.0.0`.
- **BREAKING:** `browse()` and `browseWithContinuation()` `$direction` parameter changed from `int` to `BrowseDirection` enum. Calls using `direction: 0` or `direction: 1` must be updated to `BrowseDirection::Forward` / `BrowseDirection::Inverse`.
- Updated CI test server suite from `php-opcua/opcua-test-server-suite@v1.1.2` to `php-opcua/uanetstandard-test-suite@v1.0.0`.

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

- Updated dependencies `php-opcua/opcua-client` and `php-opcua/opcua-session-manager` from `^1.0.1` to `^1.1.0`.

### Added

- **Auto-generated client certificate support.** When a connection is configured with a `security_policy` and `security_mode` but without `client_certificate`/`client_key`, the underlying client automatically generates an in-memory self-signed certificate. The behaviour is fully transparent — no changes to the config file or application code are required.
- Config comment on `client_certificate`/`client_key` keys documenting the auto-generation fallback.
- Unit tests (`configureClient certificate behavior`) covering: no call to `setClientCertificate` when cert is absent, no call when only one of cert/key is provided, correct call when both are present, and correct forwarding of the optional `ca_certificate`.
- Integration tests for connecting with `Basic256Sha256`/`SignAndEncrypt` and no explicit client certificate, in both direct mode and managed (session manager daemon) mode.

## [1.0.1] - 2026-03-16

### Added

- Initial release. Laravel service provider, facade, `OpcuaManager` (multi-connection, session-manager auto-detection), and `opcua:session` Artisan command.
