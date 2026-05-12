---
eyebrow: 'Docs · Getting started'
lede:    'Three layers of a Laravel-OPCUA application: the facade you call, the manager Laravel binds, and the optional daemon that holds OPC UA sessions across requests. Internalise this once.'

see_also:
  - { href: '../using-the-client/facade-vs-injection.md',  meta: '5 min' }
  - { href: '../session-manager/overview.md',              meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/getting-started/thinking-in-opc-ua.md', meta: 'external', label: 'opcua-client — thinking in OPC UA' }

prev: { label: 'Quick start',  href: './quick-start.md' }
next: { label: 'Upgrading',    href: './upgrading.md' }
---

# How laravel-opcua fits

Three concepts you need clear in your head before going deeper.
This page is the orientation map.

## 1 — Three runtime layers

<!-- @code-block language="text" label="layers" -->
```text
┌─────────────────────────────────────────────────────────────────────┐
│ Your Laravel application                                             │
│   - HTTP controllers                                                  │
│   - Console commands                                                  │
│   - Queue workers                                                     │
│   - Livewire components                                               │
│   - Listeners                                                         │
│                                                                       │
│   Opcua::read(...)   ←── facade                                       │
│   OpcuaManager::read(...)  ←── injected manager                       │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│ OpcuaManager (container singleton)                                   │
│   - Resolves configuration                                            │
│   - Picks direct vs managed mode                                      │
│   - Wires logger / cache / event dispatcher from the container        │
│   - Caches connection instances per name                              │
└─────────────────────────────────────────────────────────────────────┘
                       │                              │
                       ▼ (direct mode)                ▼ (managed mode)
┌─────────────────────────────┐     ┌──────────────────────────────────┐
│ opcua-client                 │     │ ManagedClient ──IPC──→ daemon    │
│   ClientBuilder→Client       │     │   (opcua-session-manager)         │
│   Opens a session per use     │     │   Persistent sessions             │
└─────────────────────────────┘     └──────────────────────────────────┘
                       │                              │
                       └──────────────┬───────────────┘
                                       ▼
                           ┌─────────────────────┐
                           │ OPC UA server       │
                           │ (PLC, SCADA, …)     │
                           └─────────────────────┘
```
<!-- @endcode-block -->

Three things to internalise:

- **Application code is always the same shape.** Whether the
  underlying mode is direct or managed, the facade / manager
  API is identical. You write `Opcua::read('ns=2;s=...')` either
  way.
- **`OpcuaManager` is the integration layer.** It owns
  configuration parsing, mode selection, lazy connection
  creation. Everything Laravel-specific lives in or around it.
- **The daemon is optional.** Without it, every operation opens
  a fresh OPC UA session. With it, sessions live across HTTP
  requests — the whole point of using Laravel rather than a CLI
  tool.

## 2 — The two modes, in detail

### Direct mode

When the daemon is **not** running (or
`OPCUA_SESSION_MANAGER_ENABLED=false`), `OpcuaManager` creates a
fresh `Client` per connection name on first use.

<!-- @code-block language="text" label="direct mode flow" -->
```text
HTTP request 1
  Opcua::read('ns=2;s=...')
    → OpcuaManager::connection('default') (cached in Laravel container singleton)
    → If no Client cached: ClientBuilder::create()->setX()->...->connect()
    → Client::read(...)
    → Response
  (end of request — singleton state survives for next request only if process is long-lived)

HTTP request 2 (in PHP-FPM — fresh process)
  Same flow — Client was destroyed when the request worker recycled
```
<!-- @endcode-block -->

PHP-FPM workers recycle the singleton between requests. The
"caching in container" doesn't help across requests; it only
helps within a single request that issues multiple OPC UA calls.

**Cost**: ~1 second OPC UA handshake per request worker. If your
controller does several OPC UA operations in one request, they
share the same session — no per-call handshake.

### Managed mode

When the daemon is running, `OpcuaManager` creates a
`ManagedClient` that speaks IPC with the daemon. The daemon
holds the OPC UA session.

<!-- @code-block language="text" label="managed mode flow" -->
```text
opcua:session daemon runs in the background, holding 0..N OPC UA sessions

HTTP request 1
  Opcua::read('ns=2;s=...')
    → OpcuaManager::connection('default')
    → ManagedClient::connect('opc.tcp://...')
      → IPC frame: {"command":"open","endpointUrl":"opc.tcp://...","config":{...},"authToken":"..."}
      → Daemon checks the session-reuse cache (key derived from
        endpoint + identity-related config keys) → returns sessionId
    → ManagedClient::read(...)
      → IPC frame: {"command":"query","sessionId":"...","method":"read","params":[...],"authToken":"..."}
      → Daemon reads from its existing OPC UA session
      → Response: {"success":true,"data":<DataValue payload>}
  (end of request — daemon-side OPC UA session lives on, unless the
   request explicitly called Opcua::disconnect(), which also sends
   {"command":"close"} and releases the session)

HTTP request 2
  Same flow — the daemon's session is reused; sub-millisecond connect
```
<!-- @endcode-block -->

**Cost**: ~1ms IPC round-trip per call. The OPC UA handshake
happens **once on the daemon side**, then never again until the
daemon restarts.

For request-driven workloads, this is the difference between an
unusable 1-second OPC UA latency per request and a snappy ~3-5ms
total.

### Auto-detection

`OpcuaManager::isSessionManagerRunning()` does a
`file_exists($socketPath)` check for Unix-domain endpoints; for
TCP endpoints it always returns `true`. Mode selection happens on
each call to `connection()` — the manager picks managed mode
whenever the socket file exists (or, on TCP, whenever
`session_manager.enabled` is `true`).

Forcing direct mode in production (e.g. for a CLI command that
doesn't need the daemon):

<!-- @code-block language="bash" label="bash — force direct" -->
```bash
OPCUA_SESSION_MANAGER_ENABLED=false php artisan plc:something
```
<!-- @endcode-block -->

See [Session manager · Monitoring the daemon](../session-manager/monitoring-the-daemon.md).

## 3 — The Laravel idioms

### Service container

`OpcuaManager` is bound as a singleton:

<!-- @code-block language="php" label="container bindings" -->
```php
$this->app->singleton(OpcuaManager::class, fn($app) => new OpcuaManager(...));
$this->app->alias(OpcuaManager::class, 'opcua');
```
<!-- @endcode-block -->

Both `app(OpcuaManager::class)` and `app('opcua')` return the
same instance.

### Facade

`PhpOpcua\LaravelOpcua\Facades\Opcua` resolves the singleton and
proxies method calls through it. Every method on the facade is
documented in the DocBlock — your IDE picks them all up.

### Configuration

`config/opcua.php` — a single config file. The package merges
its built-in defaults so you can override only what you need.

### Logging

OPC UA log lines route through `config/logging.php` channels
specified by `log_channel` in `config/opcua.php`. Use any Laravel
log channel (single, stack, syslog, papertrail, slack, …).

### Cache

OPC UA browse / resolve / endpoint caching routes through
`config/cache.php` stores specified by `cache_store`. Any Laravel
cache backend works (file, redis, memcached, database, …).

### Events

The 47-class PSR-14 event catalogue from `opcua-client` (see the
[event reference](https://github.com/php-opcua/opcua-client/blob/master/docs/observability/event-reference.md))
flows through Laravel's event dispatcher — `Illuminate\Events\Dispatcher`
implements the PSR-14 `EventDispatcherInterface`, so the events
arrive at `Event::listen(...)` listeners natively. Queue listeners
with `ShouldQueue`, broadcast with `ShouldBroadcast`. See
[Events · Overview](../events/overview.md).

### Artisan

`php artisan opcua:session` starts the daemon. Configurable via
flags and config. See [Reference · Artisan
commands](../reference/artisan-commands.md).

## What the package does not do

- **Does not embed the OPC UA stack.** It depends on
  `opcua-client`, which is the actual implementation.
- **Does not depend on the session manager at runtime.** The
  daemon dependency is bundled, but the package works without
  the daemon — just without persistent sessions.
- **Does not bind every OPC UA value to Eloquent.** Persisting
  reads is your application's concern; see [Recipes · Persistent
  tag history](../recipes/persistent-tag-history.md) for the
  canonical pattern.
- **Does not provide a UI.** Build your own (Blade, Livewire,
  Inertia, Filament); see [Integrations](../integrations/livewire.md)
  and [Recipes](../recipes/livewire-realtime-dashboard.md).

## Where the OPC UA depth lives

This package wraps `opcua-client` and `opcua-session-manager` —
the OPC UA-level documentation lives in those libraries:

- [`opcua-client` getting started](https://github.com/php-opcua/opcua-client/blob/master/docs/getting-started/quick-start.md)
- [`opcua-client` thinking in OPC UA](https://github.com/php-opcua/opcua-client/blob/master/docs/getting-started/thinking-in-opc-ua.md)
- [`opcua-session-manager` overview](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/overview.md)

This documentation focuses on **the Laravel-specific surface**:
how to use it from a controller, a job, a Livewire component, a
Pest test. For OPC UA primitives (what's a NodeId? what's a
security policy?), the upstream docs are the source of truth.

## Where to go next

- [Facade vs injection](../using-the-client/facade-vs-injection.md)
  — the first design choice in any non-trivial application.
- [The config file](../configuration/config-file.md) — every
  knob in `config/opcua.php`.
- [Session manager · Overview](../session-manager/overview.md) —
  when and why to run the daemon.
