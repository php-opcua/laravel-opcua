# Configuration

Single file: `config/opcua.php`. Publish with `php artisan vendor:publish --tag=opcua-config`.

Three top-level keys: `default`, `session_manager`, `connections`.

## `default`

Name of the connection used when `Opcua::*` is called without an explicit name.

```php
'default' => env('OPCUA_CONNECTION', 'default'),
```

Pass any other name via `Opcua::connection('plc-1')->read(...)`.

## `session_manager`

Daemon-related settings — IPC endpoint, session lifecycle, daemon-process logging/cache.

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `OPCUA_SESSION_MANAGER_ENABLED` | `true` | If false, `shouldUseSessionManager()` returns false unconditionally and the manager builds a direct `Client` every time. |
| `socket_path` | `OPCUA_SOCKET_PATH` | platform-dependent | IPC URI: `unix://<path>`, `tcp://127.0.0.1:<port>` (loopback only), or scheme-less path (= unix://). Linux/macOS default: `unix://storage_path('app/opcua-session-manager.sock')`. Windows default: `tcp://127.0.0.1:9990`. |
| `timeout` | `OPCUA_SESSION_TIMEOUT` | `600` | Session inactivity timeout (seconds). |
| `cleanup_interval` | `OPCUA_CLEANUP_INTERVAL` | `30` | Sweep idle sessions every N seconds. |
| `auth_token` | `OPCUA_AUTH_TOKEN` | `null` | Shared secret for IPC (recommended in production). |
| `max_sessions` | `OPCUA_MAX_SESSIONS` | `100` | Hard cap on concurrent daemon-side sessions. |
| `socket_mode` | — | `0600` | Unix-socket permission bits. Daemon owns; consumers must be in the owner's group/UID. |
| `allowed_cert_dirs` | — | `null` | Whitelist of directories the daemon may load `client_certificate` / `client_key` from. `null` = no restriction. |
| `log_channel` | `OPCUA_LOG_CHANNEL` | Laravel default | Laravel log-channel name for daemon's PSR-3 logger. |
| `cache_store` | `OPCUA_CACHE_STORE` | Laravel default | Laravel cache-store name for daemon-side client cache. |
| `auto_publish` | `OPCUA_AUTO_PUBLISH` | `false` | Daemon auto-publishes for sessions with subscriptions, dispatches PSR-14 events through Laravel. See `EVENTS.md`. |

### Platform-aware `socket_path` default

```php
'socket_path' => env('OPCUA_SOCKET_PATH')
    ?? (PHP_OS_FAMILY === 'Windows'
        ? 'tcp://127.0.0.1:9990'
        : storage_path('app/opcua-session-manager.sock')),
```

A scheme-less path (e.g. `/var/run/opcua.sock`) is interpreted as `unix://<path>` for backwards compatibility with pre-v4.2.0 configs.

### Why these defaults make sense

- `timeout=600s` — survives an idle web worker between requests but releases server resources within 10 minutes of inactivity.
- `cleanup_interval=30s` — small enough that ghost sessions don't accumulate, large enough not to thrash on busy daemons.
- `socket_mode=0600` — file perm is the only access control on local socket; lock it down by default.

## `connections`

Map of `name => connection-config`. Every key here is also overridable via env on the default connection.

### Endpoint and security

| Key | Env | Default | Notes |
|---|---|---|---|
| `endpoint` | `OPCUA_ENDPOINT` | `opc.tcp://localhost:4840` | Server URL. |
| `security_policy` | `OPCUA_SECURITY_POLICY` | `None` | `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`, `ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1`. |
| `security_mode` | `OPCUA_SECURITY_MODE` | `None` | `None`, `Sign`, `SignAndEncrypt`. |
| `username` | `OPCUA_USERNAME` | `null` | Username for `UserName` token. |
| `password` | `OPCUA_PASSWORD` | `null` | Plaintext in env — keep `.env` out of git. |
| `client_certificate` | `OPCUA_CLIENT_CERT` | `null` | Path to PEM or DER. Auto-generated in-memory if omitted and a security policy is set. |
| `client_key` | `OPCUA_CLIENT_KEY` | `null` | Path to private key. |
| `ca_certificate` | `OPCUA_CA_CERT` | `null` | CA cert for server cert validation. |
| `user_certificate` | `OPCUA_USER_CERT` | `null` | X.509 user token cert. |
| `user_key` | `OPCUA_USER_KEY` | `null` | X.509 user token key. |

### Client behaviour

| Key | Env | Default | Notes |
|---|---|---|---|
| `timeout` | `OPCUA_TIMEOUT` | `5.0` | Network timeout in seconds (per request, not total). |
| `auto_retry` | `OPCUA_AUTO_RETRY` | `null` | Max reconnection retries on transient failure. `null` = don't retry. |
| `batch_size` | `OPCUA_BATCH_SIZE` | `null` | Max items per service request (split readMulti/writeMulti). `null` = no client-side split (use server's `MaxNodesPerRead/Write`). |
| `browse_max_depth` | `OPCUA_BROWSE_MAX_DEPTH` | `10` | Default `browseRecursive()` depth. |

### Trust store (v4.0+)

| Key | Env | Default | Notes |
|---|---|---|---|
| `trust_store_path` | `OPCUA_TRUST_STORE_PATH` | `null` (in-memory only) | Directory for trusted/rejected server certs. Recommended: `storage_path('app/opcua-trust-store')`. |
| `trust_policy` | `OPCUA_TRUST_POLICY` | `null` | `fingerprint`, `fingerprint+expiry`, `full`. `null` = pick a sensible default per security mode. |
| `auto_accept` | `OPCUA_AUTO_ACCEPT` | `false` | TOFU: first contact auto-trusts. Use during dev, never in prod. |
| `auto_accept_force` | `OPCUA_AUTO_ACCEPT_FORCE` | `false` | Re-trust certs previously rejected. Almost always wrong. |

### Type and metadata

| Key | Env | Default | Notes |
|---|---|---|---|
| `auto_detect_write_type` | `OPCUA_AUTO_DETECT_WRITE_TYPE` | `true` | When `write()` is called without `$type`, client reads node metadata to pick the right `BuiltinType`. One extra round-trip per first-time write per node. |
| `read_metadata_cache` | `OPCUA_READ_METADATA_CACHE` | `false` | Caches node DataType metadata so `auto_detect_write_type` doesn't pay the round-trip on every write. Set to `true` once your address space is stable. |

### Logging (v4.3.1+)

| Key | Default | Notes |
|---|---|---|
| `log_channel` | — | Name of a Laravel log channel. Resolved lazily at connection time — you can reference it in `config/opcua.php` without bootstrapping Laravel first. |

Logger resolution priority:
1. Runtime override via `OpcuaManager::setLogger()` / `useConsoleLogger()`
2. Per-connection config `'logger'` (PSR-3 instance)
3. Per-connection config `'log_channel'` (Laravel channel name)
4. Application default `LoggerInterface`

### Auto-connect / declarative subscriptions (v4.0.1+)

These keys are only honored when the daemon's `auto_publish` is enabled. They tell the daemon to connect on startup and register monitoring without any application code calling `connect()`.

```php
'connections' => [
    'plc-1' => [
        'endpoint' => 'opc.tcp://plc.example:4840',
        // ... security keys ...

        'auto_connect' => true,

        'subscriptions' => [
            [
                'publishing_interval' => 500.0,
                'max_keep_alive_count' => 5,
                'lifetime_count' => 2400,
                'max_notifications_per_publish' => 0,
                'publishing_enabled' => true,
                'priority' => 0,
                'monitored_items' => [
                    [
                        'node_id' => 'ns=2;s=Temperature',
                        'client_handle' => 1,
                        'sampling_interval' => 250.0,
                        'queue_size' => 1,
                        'discard_oldest' => true,
                    ],
                ],
                'event_monitored_items' => [
                    [
                        'node_id' => 'i=2253',
                        'client_handle' => 10,
                        'select_fields' => ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                    ],
                ],
            ],
        ],
    ],
],
```

`client_handle` is the application-side correlation key for `DataChangeReceived` / `EventNotificationReceived` events. Choose deterministic integers per node.

## Defining multiple connections

```php
'connections' => [
    'plc-1' => [
        'endpoint' => 'opc.tcp://plc-1.factory.lan:4840',
        'security_policy' => 'Basic256Sha256',
        'security_mode' => 'SignAndEncrypt',
        'username' => env('PLC1_USER'),
        'password' => env('PLC1_PASS'),
    ],
    'plc-2' => [
        'endpoint' => 'opc.tcp://plc-2.factory.lan:4840',
        // ...
    ],
    'historian' => [
        'endpoint' => 'opc.tcp://historian.factory.lan:4840',
        'browse_max_depth' => 3,
        'timeout' => 30.0,  // history queries are slow
    ],
],
```

```php
Opcua::connection('plc-1')->read('ns=2;s=Temp');
Opcua::connection('historian')->historyReadRaw(...);
```

## Per-call config override (ad-hoc)

```php
$client = Opcua::connectTo(
    'opc.tcp://discovered.example:4840',
    [
        'security_policy' => 'Basic256Sha256',
        'security_mode' => 'SignAndEncrypt',
        'username' => 'guest',
        'password' => '',
    ],
    as: 'discovered-1', // cache key — reuse this client on subsequent calls in the same request
);
```

Without `as:`, the connection is throwaway (built and used once). With `as:`, it's cached in the `OpcuaManager`'s connections array under that name.

## Environment variable cheat-sheet

```env
# Connection
OPCUA_ENDPOINT=opc.tcp://server:4840
OPCUA_USERNAME=operator
OPCUA_PASSWORD=changeme
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key
OPCUA_TRUST_STORE_PATH=/var/lib/opcua/trust
OPCUA_AUTO_ACCEPT=false
OPCUA_AUTO_DETECT_WRITE_TYPE=true
OPCUA_READ_METADATA_CACHE=true

# Session manager
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=unix:///var/run/opcua.sock
OPCUA_AUTH_TOKEN=long-random-shared-secret
OPCUA_MAX_SESSIONS=200
OPCUA_AUTO_PUBLISH=true
OPCUA_LOG_CHANNEL=stack
OPCUA_CACHE_STORE=redis
```

## Common config mistakes

- **Referencing a Facade inside `config/opcua.php`.** Config is loaded before the Facade root is bound. Use channel/store *names*, not resolved instances.
- **Hard-coded `storage_path(...)` in the published config** when the storage path moves in CI. Use `env('OPCUA_SOCKET_PATH')` so CI can override.
- **`auto_accept: true` in production.** It bypasses the trust store entirely on first contact. Fine for dev, never for prod.
- **`auto_detect_write_type: true` without `read_metadata_cache: true`.** Doubles every first-time-write round-trip. Always pair them.
- **TCP loopback `socket_path` with non-loopback host.** `TcpLoopbackTransport` refuses anything that's not 127.0.0.1 / ::1. Use a proper TCP server if you need cross-host IPC.
