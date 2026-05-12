---
eyebrow: 'Docs · Session manager'
lede:    'The `php artisan opcua:session` command — every option, what it does, how it wires Laravel logging and caching into the daemon.'

see_also:
  - { href: './overview.md',                meta: '5 min' }
  - { href: './production-supervisor.md',   meta: '6 min' }
  - { href: './monitoring-the-daemon.md',   meta: '5 min' }

prev: { label: 'Overview',                  href: './overview.md' }
next: { label: 'Auto-publish',              href: './auto-publish.md' }
---

# Starting the daemon

The Laravel package ships an artisan command that launches the
daemon with Laravel-wired logging, caching, and config:

<!-- @code-block language="bash" label="terminal — basic" -->
```bash
php artisan opcua:session
```
<!-- @endcode-block -->

That reads `config/opcua.php`'s `session_manager` block and
starts the daemon. Block until `Ctrl+C` or until the process is
signalled.

## Why the artisan command?

Three things `php artisan opcua:session` does that
`vendor/bin/opcua-session-manager` doesn't:

1. **Wires `LoggerInterface`** from `config('logging.channels.{name}')`
   — daemon logs land in your Laravel log channels.
2. **Wires `CacheInterface`** from `config('cache.stores.{name}')`
   — daemon caches use Redis/database/file via Laravel's cache
   abstraction.
3. **Resolves PSR-14 `EventDispatcher`** — necessary for
   [auto-publish](./auto-publish.md).

Run the artisan command unless you have a specific reason not to
(e.g. you don't want Laravel as a runtime dependency for the
daemon).

## Options

The command exposes **six** options. Everything else
(`socket_path`, `auth_token`, `allowed_cert_dirs`, `auto_publish`,
the auto-connect connection list) comes from `config/opcua.php` —
there is **no** `--socket-path`, `--auth-token`,
`--allowed-cert-dirs`, `--auto-publish`, or `--no-auto-connect`
option.

| Option                     | Default                                                | Effect                                       |
| -------------------------- | ------------------------------------------------------ | -------------------------------------------- |
| `--timeout=<sec>`          | `config('opcua.session_manager.timeout')`              | Idle session timeout                         |
| `--cleanup-interval=<sec>` | `config('opcua.session_manager.cleanup_interval')`     | Reaper loop period                           |
| `--max-sessions=<n>`       | `config('opcua.session_manager.max_sessions')`         | Cap on concurrent sessions                   |
| `--socket-mode=<oct>`      | `config('opcua.session_manager.socket_mode')` (`0600`) | Permissions on the Unix socket file          |
| `--log-channel=<name>`     | `config('opcua.session_manager.log_channel')`          | Laravel log channel for the daemon           |
| `--cache-store=<name>`     | `config('opcua.session_manager.cache_store')`          | Laravel cache store for the daemon's client  |

## Examples

### Development

<!-- @code-block language="bash" label="terminal — dev" -->
```bash
php artisan opcua:session
```
<!-- @endcode-block -->

Pure defaults. Socket at `storage/framework/opcua-session-manager.sock`
on POSIX or `tcp://127.0.0.1:9990` on Windows.

### Custom socket location

Set the socket path in `config/opcua.php` (or via
`OPCUA_SOCKET_PATH` in `.env`):

```php
'session_manager' => [
    'socket_path' => env('OPCUA_SOCKET_PATH', '/var/run/opcua/sessions.sock'),
],
```

There is no CLI override for the socket path — the value the
daemon listens on comes from config.

### Production-style

<!-- @code-block language="bash" label="terminal — prod" -->
```bash
# config/opcua.php has socket_path, socket_mode, auth_token,
# allowed_cert_dirs, and auto_publish set. The CLI only tunes
# the per-run knobs:
php artisan opcua:session \
    --log-channel=opcua \
    --cache-store=redis
```
<!-- @endcode-block -->

You'd put this in a Supervisor / systemd unit — see
[Production supervisor](./production-supervisor.md).

## What happens at start

The command, in order:

1. **Boot Laravel** (config, bindings).
2. **Resolve** the log channel and cache store.
3. **Construct** `SessionManagerDaemon` with the wired
   dependencies.
4. **Pre-connect** configured connections if `auto_connect` is on
   — see [Auto-connect](#auto-connect) below.
5. **Bind** the socket, set permissions, start listening.
6. **Block** in the event loop.

The first log line looks like:

<!-- @code-block language="text" label="startup log" -->
```text
[2026-05-15 10:00:00] opcua.INFO: SessionManagerDaemon started
  socket=/var/run/opcua/sessions.sock mode=0660
  timeout=600 cleanup=30 max_sessions=100 auto_publish=true
```
<!-- @endcode-block -->

If you see this, the daemon is ready.

## Auto-connect

By default, the daemon **does not** pre-connect to anything. The
first IPC request that names a connection triggers the actual
OPC UA handshake.

To pre-warm specific connections at daemon boot, mark them
`auto_connect` in `config/opcua.php`:

<!-- @code-block language="php" label="config — auto-connect" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint'     => 'opc.tcp://plc-a.factory.local:4840',
        'auto_connect' => true,
    ],
],
```
<!-- @endcode-block -->

There is no `--no-auto-connect` CLI flag. To skip auto-connect
temporarily, either set `auto_publish` to `false` in
`config/opcua.php` (auto-connect only runs when auto-publish is
enabled) or remove the `auto_connect => true` flag from the
specific connections you want to skip.

## Stopping the daemon

Send `SIGTERM` for graceful shutdown:

<!-- @code-block language="bash" label="terminal — stop" -->
```bash
# By PID (if known)
kill -TERM <pid>

# Or use Supervisor / systemctl
sudo systemctl stop opcua-session-manager
```
<!-- @endcode-block -->

On SIGTERM the daemon:

1. Stops accepting new connections.
2. Closes all OPC UA sessions cleanly.
3. Unlinks the socket file.
4. Exits with status 0.

`SIGINT` (`Ctrl+C`) does the same thing.

## Restarting

The daemon is **stateless across restarts** as far as OPC UA is
concerned — sessions are torn down on stop and re-established on
the next request. Subscriptions are also lost on restart.

For production, run the daemon under a supervisor that restarts
on crash. See [Production supervisor](./production-supervisor.md).

## Multiple daemons

Running multiple daemons requires multiple Laravel installations
(or multiple distinct `config/opcua.php` files served from
separate processes) — the daemon's socket path is per-config,
not per-connection. The per-connection `socket_path` override is
not consumed by the manager.

For multi-tenant patterns see
[Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md).

## Running outside Laravel

If you need to run the daemon without Laravel as a runtime
dependency (e.g. in a separate, minimal container):

<!-- @code-block language="bash" label="terminal — raw CLI" -->
```bash
vendor/bin/opcua-session-manager \
    --socket-path=/var/run/opcua/sessions.sock \
    --timeout=600 \
    --auth-token="${OPCUA_AUTH_TOKEN}"
```
<!-- @endcode-block -->

You lose the Laravel-side wiring — no Laravel log channels, no
Laravel cache. The daemon falls back to a PSR-3-null logger and
an in-memory cache. For most production needs the artisan
command is the right choice.

## Where to read next

- [Auto-publish](./auto-publish.md) — the subscription bridge.
- [Production supervisor](./production-supervisor.md) — putting
  the daemon under a process supervisor.
- [Monitoring the daemon](./monitoring-the-daemon.md) — liveness,
  metrics, logging.
