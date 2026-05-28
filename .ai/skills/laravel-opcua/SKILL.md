---
name: laravel-opcua
description: Laravel 11/12/13 integration for OPC UA. Provides a Facade (Opcua::*), service provider, .env-based named connections, an Artisan daemon command (opcua:session), and transparent session persistence via the opcua-session-manager daemon. Use this skill whenever the user is working with OPC UA from a Laravel application — controllers, jobs, Livewire components, Filament panels, broadcasting, Horizon queues, Octane workers, scheduled tasks, or Pest tests.
license: MIT
version: v4.4.0
compatibility:
  php: ">= 8.2"
  laravel: "11.x | 12.x | 13.x"
  depends_on:
    - php-opcua/opcua-client@^4.4.0
    - php-opcua/opcua-session-manager@^4.4.0
metadata:
  package: php-opcua/laravel-opcua
  packagist: https://packagist.org/packages/php-opcua/laravel-opcua
  repository: https://github.com/php-opcua/laravel-opcua
  documentation: https://php-opcua.com
  related:
    - php-opcua/opcua-client
    - php-opcua/opcua-session-manager
    - php-opcua/opcua-cli
    - php-opcua/opcua-client-nodeset
---

# laravel-opcua

A thin, idiomatic Laravel layer over `php-opcua/opcua-client`. Three things to remember:

1. The Facade `PhpOpcua\LaravelOpcua\Facades\Opcua` proxies the full `OpcUaClientInterface`. Anything `opcua-client` can do, the Facade can do.
2. `OpcuaManager::shouldUseSessionManager()` decides per-call whether to instantiate a direct `Client` (TCP straight to the server) or a `ManagedClient` (IPC to the long-lived daemon). The decision is transparent to application code.
3. v4.4.0 picked up 21 new client methods (HistoryUpdate, File transfer, Aggregates). They are reachable through `Opcua::*` and `Opcua::connection('plc-1')->*` without any config or service-provider change.

## What this package is for

| You want to | Use |
|---|---|
| Read / write OPC UA nodes from a controller, job, command | `Opcua::read()`, `Opcua::write()` (Facade) |
| Talk to multiple OPC UA servers | Named connections in `config/opcua.php`, `Opcua::connection('plc-1')` |
| Connect to a runtime-discovered endpoint | `Opcua::connectTo($url, $configOverrides, as: 'cache-key')` |
| Avoid one new TCP connection per HTTP request | Run `php artisan opcua:session` as a supervised daemon |
| React to data changes, alarms, etc. via PSR-14 → Laravel Event system | Configure `auto_publish: true` + `auto_connect: true` + `subscriptions: [...]` |
| Test code that touches OPC UA without a server | `PhpOpcua\Client\MockClient` + Facade swap, see `references/TESTING.md` |
| Stream notifications to Livewire / Broadcasting / Notifications / Filament | Register listeners on `DataChangeReceived`, `AlarmActivated`, etc. (see `references/INTEGRATIONS.md`) |

## Mental model

```
Application code
   └── Opcua::* (Facade)
       └── OpcuaManager::connection($name)
           ├── shouldUseSessionManager() == true?
           │   └── ManagedClient (IPC → daemon → TCP → server)
           │       └── TransportFactory picks UnixSocketTransport (Linux/macOS) or TcpLoopbackTransport (Windows)
           └── shouldUseSessionManager() == false?
               └── ClientBuilder::create()->...->connect()  (direct TCP, new connection per call)
```

The two branches expose the same `OpcUaClientInterface`. Your code does not know which it has.

## Quick start

```bash
composer require php-opcua/laravel-opcua
php artisan vendor:publish --tag=opcua-config
```

```env
# .env
OPCUA_ENDPOINT=opc.tcp://plc.example:4840
OPCUA_USERNAME=operator
OPCUA_PASSWORD=changeme
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
```

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

Opcua::read('i=2259')->getValue();         // 0 = Running
Opcua::write('ns=2;s=Setpoint', 42.5);     // auto-detects Double
Opcua::browseRecursive('i=85', maxDepth: 3);
```

## The 3 patterns you will use 90% of the time

### Pattern A — one-shot read/write (no daemon)

Best for HTTP requests, scheduled jobs, Artisan commands. The Facade opens a TCP connection per call, reads/writes, then closes.

```php
public function showServerState(): array
{
    $state = Opcua::read('i=2259')->getValue();
    return ['state' => $state, 'running' => $state === 0];
}
```

### Pattern B — daemon-backed, transparent session reuse

When you run `php artisan opcua:session` under Supervisor/systemd, every Facade call goes through the daemon. Sessions are reused; you no longer pay the connect + create-session + activate-session round-trip per request.

Run the daemon:
```bash
php artisan opcua:session --log-channel=stack --cache-store=redis
```

Application code does not change. Same `Opcua::read(...)`, but now backed by `ManagedClient` automatically.

### Pattern C — `auto_publish` + Laravel events

Subscribe declaratively in config; receive notifications as Laravel events.

```php
// config/opcua.php
'session_manager' => ['auto_publish' => true],
'connections' => [
    'plc-1' => [
        'endpoint' => 'opc.tcp://plc.example:4840',
        'auto_connect' => true,
        'subscriptions' => [[
            'publishing_interval' => 500.0,
            'monitored_items' => [
                ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
            ],
        ]],
    ],
],
```

```php
// app/Providers/EventServiceProvider.php
use PhpOpcua\Client\Event\DataChangeReceived;

Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    SensorReading::create([
        'client_handle' => $e->clientHandle,
        'value' => $e->dataValue->getValue(),
        'sampled_at' => $e->dataValue->sourceTimestamp,
    ]);
});
```

The daemon's auto-publish loop dispatches PSR-14 events through Laravel's event dispatcher. Listeners can be queued, broadcast, etc. — see `references/INTEGRATIONS.md`.

## Facade method surface (one-line summary)

Connection management: `connection()`, `connect()`, `connectTo()`, `disconnect()`, `disconnectAll()`, `isSessionManagerRunning()`, `getDefaultConnection()`.

Proxied to the active connection (auto-routed via `__call`):
- Reading: `read`, `readMulti`
- Writing: `write`, `writeMulti`
- Browsing: `browse`, `browseAll`, `browseRecursive`, `browseWithContinuation`, `browseNext`, `resolveNodeId`, `translateBrowsePaths`
- Method calls: `call`
- Subscriptions: `createSubscription`, `createMonitoredItems`, `createEventMonitoredItem`, `modifyMonitoredItems`, `setTriggering`, `deleteMonitoredItems`, `deleteSubscription`, `publish`, `transferSubscriptions`, `republish`
- History read: `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime`
- **History update (v4.4)**: `historyInsertData`, `historyReplaceData`, `historyUpdateData`, `historyDeleteRawModified`, `historyDeleteAtTime`, `historyInsertEvent`, `historyReplaceEvent`, `historyUpdateEvent`, `historyDeleteEvent`
- **File transfer (v4.4)**: `openFile`, `closeFile`, `readFile`, `writeFile`, `getFilePosition`, `setFilePosition`, `createDirectory`, `createFileInDirectory`, `deleteFileSystemObject`, `moveOrCopyFileSystemObject`
- **Aggregates (v4.4)**: `aggregate`, `historyAggregate`
- Trust store: `trustCertificate`, `untrustCertificate`, `getTrustStore`, `getTrustPolicy`
- Discovery: `getEndpoints`, `discoverDataTypes`, `getExtensionObjectRepository`
- Cache / logging: `getLogger`, `getCache`, `invalidateCache`, `flushCache`
- Connection state: `connect`, `disconnect`, `reconnect`, `isConnected`, `getConnectionState`, `getTimeout`, `getAutoRetry`, `getBatchSize`, `getDefaultBrowseMaxDepth`, `getServerMaxNodesPerRead`, `getServerMaxNodesPerWrite`

Full PHPDoc with all signatures: `src/Facades/Opcua.php`.

## When to follow the references

Progressive disclosure — only load what the task needs:

- `references/CONFIG.md` — every `config/opcua.php` key, env vars, named connections, defaults, version-specific keys
- `references/SESSION_MANAGER.md` — daemon command, Supervisor/systemd setup, IPC endpoints, auto-publish vs manual publish, monitoring
- `references/EVENTS.md` — full list of 56 PSR-14 events, payload shapes, queued listener pattern, common listener recipes
- `references/INTEGRATIONS.md` — Octane/FrankenPHP, Horizon/queues, Livewire, Filament, Broadcasting, Notifications, Telescope/Pulse
- `references/SECURITY.md` — policies, modes, trust store, certificate auto-generation, X.509 user auth, env-driven config
- `references/TESTING.md` — Pest setup, MockClient + Facade swap, integration tests with Docker test-suite
- `references/PITFALLS.md` — common gotchas: facade in config files, Octane state, mixed daemon versions, etc.
- `assets/recipes.md` — copy-pasteable code snippets for the 15 most common end-to-end tasks

## Idiomatic patterns

1. **Inject `OpcuaManager`, not the Facade, in long-lived classes.** The Facade resolves the manager every call; injection caches it.
   ```php
   public function __construct(private OpcuaManager $opcua) {}
   public function handle(): void { $this->opcua->read(...); }
   ```

2. **Use named connections per server.** Don't string-build endpoints in code. Define `plc-1`, `plc-2`, `historian` in config, then `Opcua::connection('historian')->historyReadRaw(...)`.

3. **Don't disconnect in HTTP requests when the daemon is enabled.** `ManagedClient::disconnect()` closes the daemon-side session, undoing the connection pooling. Let the session manager handle lifecycle.

4. **For auto-published subscriptions, never call `publish()` yourself.** It returns `auto_publish_active` error. Subscribe to events instead.

5. **Use `useCache: false` for fresh reads of high-churn nodes.** The read metadata cache is the default — pass `refresh: true` to bypass.

6. **Queue listeners for heavy event handling.** A `DataChangeReceived` listener that hits a database should be `ShouldQueue`. Otherwise the daemon publish loop blocks on it.

7. **`Opcua::connectTo()` is for ad-hoc; cache by name** with the `as:` parameter when reused across the request.

8. **Trust store goes on disk, not in DB.** `storage/app/opcua-trust-store/` by default; check it into a deploy volume, not git.

9. **Run the daemon under a dedicated UID with `socket_mode: 0600`.** The Facade-side process must be in the same group/UID.

10. **In Octane, configure `OpcuaManager` as request-scoped via flushed singletons** — see `references/INTEGRATIONS.md` for the `OctaneServiceProvider::tick` hook.

## Exit codes (Artisan `opcua:session`)

| Code | Meaning |
|---|---|
| 0 | Daemon exited cleanly (SIGTERM/SIGINT) |
| 1 | Configuration error (invalid socket_path, missing required key) |
| 2 | Bind failure (port in use, socket-path EACCES, parent dir missing) |
| 3 | Runtime error inside daemon loop (logged via PSR-3 channel) |

Non-zero exits should be caught by Supervisor `autorestart=true` or systemd `Restart=on-failure`.

## Versioning

The Laravel package versions lock-step with `php-opcua/opcua-client` and `php-opcua/opcua-session-manager`. Always upgrade in this order:

1. **Daemon first.** Stop `opcua:session`, `composer update`, restart.
2. **Application second.** `composer update php-opcua/laravel-opcua`.

If you upgrade application before daemon and call a v4.4 method (e.g. `historyInsertData`), `ManagedClient::__call()` will fail with `BadMethodCallException` because the daemon has no handler for it.

## What this skill does NOT cover

- The raw OPC UA protocol — see the `opcua-client` skill.
- The session-manager daemon's IPC protocol — see the `opcua-session-manager` skill.
- CLI usage — see the `opcua-cli` skill.
- Companion-spec types (DI, IA, AutoID, etc.) — see the `opcua-client-nodeset` skill.

Cross-skill workflow example (`docs/recipes/persistent-tag-history.md`):
- `opcua-cli generate:nodeset Vendor.NodeSet2.xml ...` (nodeset skill)
- App reads typed nodes via `Opcua::read()` (this skill)
- Persists into a historian via `Opcua::historyInsertData()` (this skill + opcua-client)
- A Filament panel browses results (this skill + Filament integration)
