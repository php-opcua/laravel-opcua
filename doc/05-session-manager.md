# Session Manager

## Overview

PHP's request/response lifecycle destroys all state at the end of every request — including TCP connections. OPC UA requires a 5-step handshake (TCP → Hello/Ack → OpenSecureChannel → CreateSession → ActivateSession) that adds 50–200ms per request.

The session manager solves this with a long-running daemon that holds OPC UA connections in memory. PHP requests communicate with the daemon via a lightweight Unix socket IPC protocol.

**The session manager is entirely optional.** If the daemon is not running, the package falls back to direct connections with zero code changes.

```
Without daemon:
  Request 1:  [connect 150ms] [read 5ms] [disconnect]  → ~155ms
  Request 2:  [connect 150ms] [read 5ms] [disconnect]  → ~155ms

With daemon:
  Request 1:  [open session 150ms] [read 5ms]           → ~155ms (first only)
  Request 2:                       [read 5ms]           → ~5ms
  Request N:                       [read 5ms]           → ~5ms
```

## Starting the Daemon

```bash
php artisan opcua:session
```

The daemon creates a Unix socket at `storage/app/opcua-session-manager.sock`. The `Opcua` Facade detects this socket and routes traffic through the daemon automatically.

## Command Options

```bash
php artisan opcua:session \
    --timeout=600 \
    --cleanup-interval=30 \
    --max-sessions=100 \
    --socket-mode=0600 \
    --log-channel=stack \
    --cache-store=redis
```

| Option | Default | Description |
|--------|---------|-------------|
| `--timeout` | `600` | Session inactivity timeout in seconds |
| `--cleanup-interval` | `30` | Interval between expired session checks |
| `--max-sessions` | `100` | Maximum concurrent OPC UA sessions |
| `--socket-mode` | `0600` | Unix socket file permissions (octal) |
| `--log-channel` | Laravel default | Laravel log channel for daemon events |
| `--cache-store` | Laravel default | Laravel cache store for browse caching |

All options can also be configured via `.env` or `config/opcua.php`.

## Configuration

```dotenv
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=
OPCUA_SESSION_TIMEOUT=600
OPCUA_CLEANUP_INTERVAL=30
OPCUA_AUTH_TOKEN=my-secret-token
OPCUA_MAX_SESSIONS=100
OPCUA_LOG_CHANNEL=stack
OPCUA_CACHE_STORE=redis
```

## Production Deployment

Use a process manager such as Supervisor to keep the daemon running:

```ini
[program:opcua-session-manager]
command=php /path/to/artisan opcua:session
directory=/path/to/laravel
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/laravel/storage/logs/opcua-session-manager.log
```

Or with systemd:

```ini
[Unit]
Description=OPC UA Session Manager
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/to/laravel
ExecStart=/usr/bin/php artisan opcua:session
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

## Architecture

```
┌──────────────┐         ┌──────────────────────────────┐         ┌──────────────┐
│  PHP Request │ ──IPC──►│  Session Manager Daemon       │ ──TCP──►│  OPC UA      │
│  (short-     │◄──IPC── │                              │◄──TCP── │  Server      │
│   lived)     │         │  ● ReactPHP event loop       │         │              │
└──────────────┘         │  ● Sessions in memory        │         └──────────────┘
                         │  ● Periodic cleanup timer    │
┌──────────────┐         │  ● Signal handlers           │
│  PHP Request │ ──IPC──►│                              │
│  (reuses     │◄──IPC── │  Sessions:                   │
│   session)   │         │   [sess-a1b2] → Client (TCP) │
└──────────────┘         │   [sess-c3d4] → Client (TCP) │
                         └──────────────────────────────┘
```

The daemon:
- Listens on a Unix socket for incoming IPC requests
- Creates OPC UA `Client` instances for each session
- Forwards OPC UA operations from PHP to the client
- Serializes/deserializes all OPC UA types over JSON IPC
- Cleans up expired sessions based on inactivity timeout
- Gracefully disconnects all sessions on SIGTERM/SIGINT
- Tracks active subscriptions and transfers them on reconnection

## Security

The daemon supports multiple security layers:

- **IPC authentication** — `OPCUA_AUTH_TOKEN` validated with timing-safe comparison
- **Socket permissions** — `0600` by default (owner-only access)
- **Method whitelist** — only documented OPC UA operations allowed
- **Session limits** — configurable maximum to prevent resource exhaustion
- **Certificate path restrictions** — `allowed_cert_dirs` constrains certificate file access

## Checking Daemon Status

```php
if (Opcua::isSessionManagerRunning()) {
    // Daemon is running — ManagedClient will be used
} else {
    // Daemon is not running — direct Client will be used
}
```
