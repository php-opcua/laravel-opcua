---
eyebrow: 'Docs · Configuration'
lede:    'The session_manager block in config/opcua.php — what each key does, how the daemon picks it up, and how Laravel-specific bits like log channels and cache stores get wired through.'

see_also:
  - { href: '../session-manager/overview.md',             meta: '5 min' }
  - { href: '../session-manager/starting-the-daemon.md',  meta: '5 min' }
  - { href: '../session-manager/production-supervisor.md', meta: '6 min' }

prev: { label: 'Security',                href: './security.md' }
next: { label: 'Publishing & overriding', href: './publishing-overriding.md' }
---

# Session manager configuration

The `session_manager` block tells the package three things:

1. Whether the daemon should be used at all (managed mode vs direct
   mode — see [How laravel-opcua fits](../getting-started/how-laravel-opcua-fits.md)).
2. How to reach the daemon when it is running.
3. How the daemon should behave when **the artisan command starts
   it** — this is the only path where Laravel-specific wiring
   (log channel, cache store) is involved.

## Full block

<!-- @code-block language="php" label="config/opcua.php — session_manager" -->
```php
'session_manager' => [
    'enabled'          => env('OPCUA_SESSION_MANAGER_ENABLED', true),
    'socket_path'      => env('OPCUA_SOCKET_PATH'),
    'timeout'          => env('OPCUA_SESSION_TIMEOUT',  600),
    'cleanup_interval' => env('OPCUA_CLEANUP_INTERVAL', 30),
    'auth_token'       => env('OPCUA_AUTH_TOKEN'),
    'max_sessions'     => env('OPCUA_MAX_SESSIONS',     100),

    'log_channel'      => env('OPCUA_LOG_CHANNEL'),
    'cache_store'      => env('OPCUA_CACHE_STORE'),

    'auto_publish'     => env('OPCUA_AUTO_PUBLISH', false),
],
```
<!-- @endcode-block -->

## Per-key reference

### `enabled`

Master switch. When `false`, the package skips daemon-probe logic
entirely and opens a direct connection for every call.
`isSessionManagerRunning()` is short-circuited to `false`.

Set `enabled = false` in tests and in any environment where you
want to **force** direct mode regardless of whether a daemon
happens to be running.

### `socket_path`

Where the daemon listens and where managed clients connect. Two
forms:

- **Unix socket** — a filesystem path. Example:
  `storage_path('framework/opcua-session-manager.sock')`. This is
  the default and the right choice on POSIX hosts.
- **TCP loopback** — `tcp://127.0.0.1:9990`. Use this on Windows
  (no Unix sockets) or when running the daemon in a separate
  container that exposes the port.

If `socket_path` is null, the package picks a sensible default:
`storage_path('framework/opcua-session-manager.sock')` on POSIX,
`tcp://127.0.0.1:9990` on Windows.

### `timeout` (env: `OPCUA_SESSION_TIMEOUT`)

Idle timeout in seconds. After this many seconds of inactivity
on a managed session, the daemon tears it down. The default of
600 seconds (10 minutes) is conservative — most servers will hold
a session much longer.

> **Naming gotcha.** The config key is `timeout` (no `session_`
> prefix). The env var is `OPCUA_SESSION_TIMEOUT`.

Tune up to match `MaxSessionTimeout` of your server if you have
long-running subscriptions; tune down if you have memory pressure
and many short-lived clients.

### `cleanup_interval`

How often the daemon's reaper loop runs (seconds). Default 30.
You rarely need to change this.

### `auth_token`

Shared secret for IPC. When set, every managed-client request
must include `authToken`. The package's `ManagedClient` reads it
from this key automatically — you do not need to handle it in
application code.

<!-- @callout type="warning" -->
**Set `auth_token` in production.** Without it, any local
process that can reach the socket can issue commands. See
[Security hardening](../session-manager/production-supervisor.md).
<!-- @endcallout -->

Generate one with `php artisan tinker --execute='echo bin2hex(random_bytes(32));'`
and store in your secrets manager.

### `max_sessions`

Cap on concurrent daemon-held sessions. Default 100. The daemon
refuses to open a 101st session and emits
`max_sessions_exceeded`.

This is **not** the cap on managed *clients* — many clients can
share one daemon session if their connection config matches
(see [Session reuse](../session-manager/overview.md)). It is the
cap on distinct `(endpoint + credentials + security)` tuples held
open.

### `log_channel`

Laravel log channel the daemon writes to **when launched via
`php artisan opcua:session`**. Falls back to the global
`LOG_CHANNEL` if null.

To get a dedicated daemon log file, define a channel in
`config/logging.php`:

<!-- @code-block language="php" label="config/logging.php" -->
```php
'channels' => [
    'opcua' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua.log'),
        'level'  => env('OPCUA_LOG_LEVEL', 'info'),
        'days'   => 14,
    ],
],
```
<!-- @endcode-block -->

…then set `OPCUA_LOG_CHANNEL=opcua` in `.env`.

### `cache_store`

Laravel cache store the daemon uses for persistent session
caches and per-server protocol features (chunk sizes, certificate
validation cache, …). Falls back to the global `CACHE_STORE` if
null.

For a multi-host deployment with shared daemon state, point this
at `redis`. For single-host, `file` is fine.

### `auto_publish`

When `true`, the daemon publishes subscription
`DataChange`/`Event`/`StatusChange` notifications back to PHP
via PSR-14. The package translates those events to Laravel
events automatically.

See [Session manager · Auto-publish](../session-manager/auto-publish.md)
and [Events · Overview](../events/overview.md).

<!-- @callout type="note" -->
**`auto_publish` only makes sense in managed mode.** In direct
mode, subscriptions are pulled from the application itself —
there is no daemon to publish from.
<!-- @endcallout -->

## When the artisan command reads this

`php artisan opcua:session` is the **only** path where Laravel
config gets injected into the daemon. The command:

1. Reads `session_manager.*` from `config/opcua.php`.
2. Resolves `log_channel` to a PSR-3 `LoggerInterface`.
3. Resolves `cache_store` to a PSR-16 `CacheInterface`.
4. Resolves `auth_token` and bakes it into the daemon's command
   handler.
5. Boots `SessionManagerDaemon` with that wiring.

If you run the daemon outside Laravel (e.g. `vendor/bin/opcua-session-manager`
directly), **none** of the Laravel-specific wiring is read.
You need to pass equivalents on the command line.

See [Session manager · Starting the daemon](../session-manager/starting-the-daemon.md)
for the artisan command surface, and
[Production supervisor](../session-manager/production-supervisor.md)
for systemd/Supervisor/Horizon-aware unit files.

## Direct mode

If `enabled` is `false` (or the daemon is unreachable), the
package operates in **direct mode**. The `session_manager` block
is then ignored at the **client** side, but the values for
`socket_path` etc. remain — they describe *where the daemon
would be* if you turned it on.

## Where to read next

- [Publishing and overriding](./publishing-overriding.md) — last
  configuration page.
- [Session manager · Overview](../session-manager/overview.md) —
  what the daemon does and why.
- [Production supervisor](../session-manager/production-supervisor.md) —
  systemd / Supervisor unit files.
