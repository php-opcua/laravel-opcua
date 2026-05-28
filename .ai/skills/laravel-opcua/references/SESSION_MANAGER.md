# Session Manager

The `opcua-session-manager` daemon runs alongside your Laravel application. Without it, every Facade call opens a fresh TCP connection (handshake, secure channel, session, activation — typically 200-500ms). With it, all calls share a daemon-side session pool over IPC.

## Architecture

```
worker-1 (php-fpm) ─┐
worker-2 (php-fpm) ─┼─► IPC ─► opcua-session-manager daemon ─► TCP ─► OPC UA server
worker-N (php-fpm) ─┘
                      ↑
                      "shared session pool, one TCP connection per server"
```

The daemon keeps OPC UA sessions alive across PHP-FPM workers. Workers communicate via a Unix socket (Linux/macOS) or TCP loopback (Windows).

## Starting the daemon

```bash
php artisan opcua:session
```

Options (override per-run, otherwise read from `config/opcua.php`):

| Option | Default | Effect |
|---|---|---|
| `--timeout=600` | `session_manager.timeout` | Session inactivity timeout (s) |
| `--cleanup-interval=30` | `session_manager.cleanup_interval` | Sweep cadence (s) |
| `--max-sessions=100` | `session_manager.max_sessions` | Concurrent session cap |
| `--socket-mode=0600` | `session_manager.socket_mode` | Unix socket perms (octal string) |
| `--log-channel=stack` | `session_manager.log_channel` or Laravel default | Daemon PSR-3 channel |
| `--cache-store=redis` | `session_manager.cache_store` or Laravel default | Daemon-side cache store |

`SUCCESS` is returned on SIGTERM/SIGINT. Non-zero exits indicate config or bind errors — see the exit-code table in `SKILL.md`.

## Supervisor setup (Linux production)

```ini
; /etc/supervisor/conf.d/opcua-session.conf
[program:opcua-session]
process_name=%(program_name)s
command=/usr/bin/php /var/www/app/artisan opcua:session --log-channel=daily --cache-store=redis
autostart=true
autorestart=true
startsecs=5
startretries=3
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/opcua-session.log
stopwaitsecs=30
stopsignal=TERM
```

Reload:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status opcua-session
```

## systemd setup

```ini
# /etc/systemd/system/opcua-session.service
[Unit]
Description=OPC UA Session Manager (Laravel)
After=network-online.target redis.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php /var/www/app/artisan opcua:session --log-channel=stack --cache-store=redis
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now opcua-session
sudo systemctl status opcua-session
journalctl -u opcua-session -f
```

## IPC endpoint selection

`socket_path` accepts three forms:

| Form | Example | Used by |
|---|---|---|
| `unix://<path>` | `unix:///var/run/opcua.sock` | Linux, macOS |
| `tcp://127.0.0.1:<port>` | `tcp://127.0.0.1:9990` | Windows, or for unprivileged dev |
| scheme-less path | `/var/run/opcua.sock` | Backwards compat → same as `unix://` |

`TcpLoopbackTransport` enforces loopback-only on both daemon and client. Anything non-127.0.0.1 / non-::1 is refused — no accidental cross-host IPC.

## How `shouldUseSessionManager()` decides

```php
// OpcuaManager::shouldUseSessionManager() pseudo-code
if (! $config['enabled']) return false;
$unixPath = TransportFactory::toUnixPath($config['socket_path']);
return $unixPath !== null
    ? file_exists($unixPath)                 // Unix endpoint: socket must exist
    : true;                                  // TCP endpoint: assume present (fails on first IPC call if not)
```

Implication: under Unix, if the daemon dies, the application transparently falls back to direct `Client` mode after the socket file disappears. Under TCP, the application keeps trying IPC until `TcpLoopbackTransport` raises `DaemonException`. Catch that and fail over manually if you need graceful degradation.

## Auth token

Set `OPCUA_AUTH_TOKEN` to a long random string (≥ 32 bytes). Both the daemon (reads from `config/opcua.php` → `session_manager.auth_token`) and the client (same key) need it. Without it, anyone with filesystem/TCP access to the IPC endpoint can issue commands.

```env
OPCUA_AUTH_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
```

## Health check

```bash
# Unix socket — daemon should be holding it
test -S /var/run/opcua.sock && echo "Socket present"

# Or via Artisan tinker
php artisan tinker --execute='dump(Opcua::isSessionManagerRunning());'

# Daemon stats via opcua-cli (separate package)
opcua-cli session-manager:stats --socket=/var/run/opcua.sock
```

`isSessionManagerRunning()` only checks socket presence (Unix) or assumes-yes (TCP). For richer health info — active session count, memory, message queues — query the daemon via the `opcua-cli session-manager:*` commands.

## Auto-publish lifecycle

When `auto_publish: true`:

1. Daemon `boot()` loads `config('opcua.connections')` and for each connection where `auto_connect: true`:
   - Opens session
   - Creates each entry in `subscriptions[]`
   - Registers each entry's `monitored_items[]` and `event_monitored_items[]`
2. Daemon spins an inner publish loop (`PublishService`) that calls `publish()` periodically per session
3. Notifications are converted to PSR-14 events and dispatched through the `EventDispatcherInterface` (Laravel's event bus, injected by `OpcuaServiceProvider`)
4. Application listeners receive the events — sync or queued

Manual `publish()` is blocked while auto-publish is active. Calling it returns the `auto_publish_active` error code (and the publish loop continues; no harm).

To debug the publish loop:
```bash
php artisan opcua:session --log-channel=stack -vv
# In another shell:
tail -f storage/logs/laravel.log | grep -i publish
```

## Monitoring the daemon

Three signals:

1. **Process supervisor.** Supervisor / systemd `Restart` count.
2. **Daemon log.** Configured via `--log-channel`. Each session lifecycle event (connect, activate, transfer, close) is INFO; publish-loop errors are WARNING.
3. **Inactive session sweep.** Logged every `cleanup_interval` seconds.

For Prometheus, scrape the supervisor/systemd uptime + the application's PSR-14 event rate (count `DataChangeReceived` per minute as a proxy for daemon health).

## Graceful daemon restart

`SIGTERM` triggers:

1. Stop accepting new IPC connections
2. Wait for in-flight requests to complete (`stopwaitsecs=30` is enough for most workloads)
3. Close every daemon-side session via `CloseSessionRequest` (so the server doesn't accumulate ghosts)
4. Unlink the Unix socket file (`umask(0077)` was used at bind, so this is a private operation)
5. Exit 0

After a restart, `ManagedClient` calls hit a momentary `DaemonException` while the daemon comes back up. Configure `auto_retry` on connections that need to survive a restart.

## When NOT to run the daemon

- **CLI-only Artisan commands** that connect once, do work, exit. The TCP overhead is dominated by your work, the daemon adds setup cost.
- **Single-worker queue runners** with low OPC UA throughput. One persistent connection inside a long-lived worker process is enough.
- **CI/test environments.** Use `MockClient` or direct `Client` with a Docker test server.

## Mixed-version deployments

The daemon and the Laravel package version **must** match (within a minor). The daemon's command handlers know a specific set of methods — a v4.4 application calling `historyInsertData` against a v4.3 daemon gets `BadMethodCallException`.

Upgrade order:
1. `composer require php-opcua/opcua-session-manager:^4.4` on the daemon host
2. Restart the daemon
3. `composer require php-opcua/laravel-opcua:^4.4` on the application
4. Redeploy application

The reverse order causes runtime errors until step 2 completes.
