# Changelog

## [4.3.0] - 2026-05-05

### Added

- Per-connection `log_channel` config key — Laravel channel name resolved lazily, no Facade needed in config files.
- `OpcuaManager::setLogger(LoggerInterface)` runtime override (best-effort propagation to existing connections).
- `OpcuaManager::useConsoleLogger(OutputInterface, …, ?string $dateFormat = 'Y-m-d H:i:s.v')` — Symfony `ConsoleLogger` wrapped with millisecond timestamp by default; pass `dateFormat: null` to disable.
- `OpcuaManager::getLogger()`.
- `Logging\TimestampedLogger` — generic PSR-3 decorator that prepends a formatted timestamp.
- `OpcuaServiceProvider` wires a `log`-manager → channel resolver into the manager.

### Changed

- `OpcuaManager::__construct` gained an optional `?\Closure $loggerResolver` parameter (5th, default `null`, BC-safe).
- Logger resolution priority: runtime override → config `'logger'` → config `'log_channel'` → default logger.
- Bumped `php-opcua/opcua-client` `^4.2.0` → `^4.3.0` and `php-opcua/opcua-session-manager` `^4.2.0` → `^4.3.1`. Notable downstream impact:
  - `NodeManagementModule` is back in the default module list — `addNodes()` / `deleteNodes()` / `addReferences()` / `deleteReferences()` reachable through `Opcua::*`. Servers without the service set raise `ServiceUnsupportedException` on first call (still a subclass of `ServiceException`, existing handlers keep matching).
  - Top-level `ServiceFault` now decodes to `ServiceException` instead of the misleading `EncodingException: Buffer underflow`.
  - Wire-format compliance fixes: `RequestHeader.timestamp` is a valid `UtcTime`, anonymous `policyId` discovered for all security modes, NodeManagement TypeIds reference DefaultBinary encoding, ECC sequence numbers per OPC UA 1.05.4. Servers stricter than UA-.NETStandard (open62541 etc.) are now reachable.
  - **Cache codec breaking change** — persistent caches must be flushed on upgrade. `unserialize()` removed from every cache code path; `WireCacheCodec` (JSON gated by allowlist) is the new default. Pre-v4.3 entries are silently discarded on first access. New `ClientBuilder::setCacheCodec()` is available if you need to override.
  - Daemon: `--version` flag, `umask(0077)` around bind closes the socket-permission race, NDJSON 64 KiB per-frame cap, IPv4-mapped IPv6 loopback handled, `username` no longer leaked via `list`, Windows path / URL redaction in error messages, conservative PID-check fallback.
  - Daemon: Unix-socket path length is now validated before bind — long paths get a clear `DaemonException` instead of a confusing `chmod(): No such file or directory` after silent kernel truncation.

### Tests

- +12 unit tests; full suite **161 passing**.

## [4.2.0] - 2026-04-17

### Changed

- Bumped `php-opcua/opcua-client` from `^4.1.1` to `^4.2.0` and `php-opcua/opcua-session-manager` from `^4.1` to `^4.2.0`. Picks up the Kernel + ServiceModule architecture, the Wire-serialization pipeline, the describe/invoke IPC commands that make third-party modules reachable through `ManagedClient::__call()`, and the cross-platform IPC transport (Unix socket on Linux/macOS, TCP loopback on Windows).
- Relocated DTO imports in `src/Facades/Opcua.php`: `BrowsePathResult`, `BrowseResultSet`, `CallResult`, `MonitoredItemModifyResult`, `MonitoredItemResult`, `PublishResult`, `SetTriggeringResult`, `SubscriptionResult`, `TransferResult` now live in their module namespaces (`PhpOpcua\Client\Module\*`) rather than `PhpOpcua\Client\Types\*`. No behaviour change — only the fully-qualified class names moved.

### Added

- **Cross-platform session manager out of the box.** `config/opcua.php → session_manager.socket_path` now accepts:
  - `unix://<path>` (explicit Unix-domain socket)
  - `tcp://127.0.0.1:<port>` (loopback-only — non-loopback hosts are refused by `TcpLoopbackTransport` on the client and by `SessionManagerDaemon` on the daemon)
  - scheme-less path (interpreted as `unix://<path>`, backwards-compatible with pre-v4.2.0 configs)

  Default value is platform-aware: `storage_path('app/opcua-session-manager.sock')` on Linux/macOS, `tcp://127.0.0.1:9990` on Windows. `OpcuaManager::isSessionManagerRunning()` and `OpcuaManager::shouldUseSessionManager()` now inspect the endpoint URI via `TransportFactory::toUnixPath()` — for Unix endpoints they keep the historical `file_exists($socketPath)` check; TCP endpoints can't be filesystem-probed, so presence is assumed (a missing daemon surfaces as a clear `DaemonException` on the first IPC call).
- **`php artisan opcua:session-manager` reflects the endpoint kind.** The startup table shows "Endpoint" instead of "Socket", and the "Socket Mode" row is only printed for Unix-socket endpoints. `mkdir -p` for the parent directory is skipped for TCP endpoints.

### Tests / CI

- CI workflow aligned with `opcua-client` / `opcua-session-manager`: `unit` job cross-OS on `ubuntu-latest` / `macos-latest` / `windows-latest` × PHP 8.2–8.5 × Laravel 11/12/13 (with the existing exclusion matrix); `integration` job stays Ubuntu-only (Docker-hosted OPC UA servers) with `needs: unit` gating. `[DOC]` commits skip CI. `codecov/codecov-action` bumped from `v5` to `v6`.
- Unit tests (`tests/Unit/`) are fully cross-OS. Integration tests (`tests/Integration/`) remain Docker-dependent and run only in the integration job.
- Full suite: **359 passing, 1 skipped, 0 failing** on Linux.

## [4.1.1] - 2026-04-13

### Fixed

- **Cache serialization compatibility with Laravel 13.** Bumped `php-opcua/opcua-client` to `^4.1.1` which fixes `cachedFetch()` storing raw PHP objects in the PSR-16 cache. Laravel 13 defaults to `serializable_classes => false` in `config/cache.php`, causing all cached OPC UA types (`ReferenceDescription`, `NodeId`, `DataValue`, etc.) to be restored as `__PHP_Incomplete_Class` on cache hit. The fix wraps cached values as safe strings so the cache backend is immune to `allowed_classes` restrictions. ([#1](https://github.com/php-opcua/laravel-opcua/issues/1), [php-opcua/opcua-client#1](https://github.com/php-opcua/opcua-client/issues/1))

### Added

- Integration test `CacheSerializationTest` verifying browse results survive a file cache roundtrip across connections and that cached values are plain strings immune to `allowed_classes` restrictions.

## [4.1.0] - 2026-04-13

### Added

- **ECC security policy support.** `security_policy` config key now accepts `ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, and `ECC_brainpoolP384r1` in addition to the existing 6 RSA policies. Added to `resolveSecurityPolicyUri()` in both `OpcuaManager` and `SessionCommand`. No `client_certificate`/`client_key` needed — ECC certificates are auto-generated when omitted. Username/password authentication uses the `EccEncryptedSecret` protocol automatically.
  - **ECC disclaimer:** No commercial OPC UA vendor supports ECC endpoints yet. This implementation is tested exclusively against the OPC Foundation's UA-.NETStandard reference stack.

### Changed

- Bumped minimum `php-opcua/opcua-client` dependency from `^4.0.0` to `^4.1` and `php-opcua/opcua-session-manager` from `^4.0.3` to `^4.1`.
- Security support expanded from 6 to **10 policies** (6 RSA + 4 ECC).
- Updated CI test server suite from `php-opcua/uanetstandard-test-suite@v1.0.0` to `@v1.1.0`.
- Updated `config/opcua.php` security policy comment to list all 10 available policies including ECC.
- Updated documentation (README, doc/07-security.md, doc/09-examples.md, llms.txt, llms-full.txt, llms-skills.md) to reflect ECC support, add ECC `.env` examples, and include the ECC disclaimer.

## [4.0.1] - 2026-04-09

### Added

- **Auto-publish.** New `auto_publish` config key in `session_manager` section. When enabled, the daemon automatically calls `publish()` for sessions with active subscriptions and dispatches PSR-14 events (`DataChangeReceived`, `EventNotificationReceived`, `AlarmActivated`, etc.) to Laravel's event system. No manual publish loop required — register listeners in your `EventServiceProvider` to handle notifications.
- **Per-connection auto-connect.** New `auto_connect` config key per connection. When `true` (and `auto_publish` is enabled), the daemon auto-connects to the endpoint on startup and registers the subscriptions defined in the new `subscriptions` config key. Connections without `auto_connect` are managed imperatively as before.
- **Declarative subscription config.** New `subscriptions` config key per connection. Define `monitored_items` and `event_monitored_items` directly in `config/opcua.php` — the daemon creates them automatically on startup. Each subscription supports `publishing_interval`, `max_keep_alive_count`, `lifetime_count`, `priority`, and per-item `sampling_interval`, `queue_size`, `client_handle`, `select_fields`.
- **Event dispatcher injection into daemon.** `SessionCommand` resolves `EventDispatcherInterface` from the Laravel container and passes it to the daemon. All OPC UA client events (47 events) are dispatched through Laravel's event system when auto-publish is active.
- `SessionCommand::buildAutoConnectConfig()` — reads connections with `auto_connect: true` and builds the daemon's auto-connect configuration.
- `SessionCommand::mapToDaemonConfig()` — maps Laravel connection config keys (`security_policy`, `timeout`, `client_certificate`, etc.) to the daemon's internal format (`securityPolicy`, `opcuaTimeout`, `clientCertPath`, etc.).
- `SessionCommand::resolveEventDispatcher()` — resolves `EventDispatcherInterface` from the Laravel container.
- `SessionCommand::resolveSecurityPolicyUri()` and `resolveSecurityModeValue()` — helper methods for security config mapping.
- New `.env` variable: `OPCUA_AUTO_PUBLISH`.
- Auto-publish status displayed in the `opcua:session` startup table.
- Auto-connect summary displayed before daemon startup when connections are configured.
- Unit tests for `buildAutoConnectConfig`, `mapToDaemonConfig`, auto-publish flag passing, event dispatcher resolution, auto-connect filtering.
- New documentation: [Auto-Publish & Monitoring](doc/10-auto-publish.md) with real-world industrial use case.

### Changed

- Updated dependency `php-opcua/opcua-session-manager` from `^4.0.0` to `^4.0.3` (required for auto-publish and auto-connect support).
- `SessionCommand::createDaemon()` now accepts `?EventDispatcherInterface $clientEventDispatcher` and `bool $autoPublish` parameters.

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
