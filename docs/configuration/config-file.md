---
eyebrow: 'Docs · Configuration'
lede:    'config/opcua.php is the single source of truth. Three top-level sections: default connection name, session_manager block, named connections. Every value is env-driven by default.'

see_also:
  - { href: './connections.md',              meta: '5 min' }
  - { href: './environment-variables.md',    meta: '5 min' }
  - { href: './publishing-overriding.md',    meta: '4 min' }

prev: { label: 'Upgrading',   href: '../getting-started/upgrading.md' }
next: { label: 'Connections', href: './connections.md' }
---

# The config file

`config/opcua.php` defines every connection your application
will talk to, plus the optional session-manager daemon
parameters. Three top-level sections — `default`,
`session_manager`, `connections`.

This page walks through the whole file. Other pages dive into
specific blocks (security, session manager, connections).

## File shape

<!-- @code-block language="php" label="config/opcua.php (skeleton)" -->
```php
return [
    'default' => env('OPCUA_CONNECTION', 'default'),

    'session_manager' => [ /* daemon-side configuration */ ],

    'connections' => [
        'default' => [ /* per-connection configuration */ ],
        // 'plc-line-a' => [ ... ],
        // 'historian' => [ ... ],
    ],
];
```
<!-- @endcode-block -->

`default` picks which named connection the facade points at when
no name is given. `session_manager` configures the optional
daemon. `connections` lists every OPC UA server.

## Section 1 — `default`

<!-- @code-block language="php" label="default" -->
```php
'default' => env('OPCUA_CONNECTION', 'default'),
```
<!-- @endcode-block -->

The name of the connection used by `Opcua::read(...)`,
`Opcua::browse(...)`, etc. when no name is specified. Override
per call with `Opcua::connection('plc-line-a')->read(...)`.

Most single-server applications leave this at `default` and never
touch it.

## Section 2 — `session_manager`

<!-- @code-block language="php" label="session_manager" -->
```php
'session_manager' => [
    'enabled'          => env('OPCUA_SESSION_MANAGER_ENABLED', true),

    'socket_path'      => env('OPCUA_SOCKET_PATH')
        ?? (PHP_OS_FAMILY === 'Windows'
            ? 'tcp://127.0.0.1:9990'
            : storage_path('app/opcua-session-manager.sock')),

    'timeout'          => env('OPCUA_SESSION_TIMEOUT', 600),
    'cleanup_interval' => env('OPCUA_CLEANUP_INTERVAL', 30),
    'auth_token'       => env('OPCUA_AUTH_TOKEN'),
    'max_sessions'     => env('OPCUA_MAX_SESSIONS', 100),
    'socket_mode'      => 0600,
    'allowed_cert_dirs'=> null,

    'log_channel'      => env('OPCUA_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),
    'cache_store'      => env('OPCUA_CACHE_STORE', env('CACHE_STORE', 'file')),

    'auto_publish'     => env('OPCUA_AUTO_PUBLISH', false),
],
```
<!-- @endcode-block -->

The block controls the optional session-manager daemon:

| Key                 | Effect                                                                 |
| ------------------- | ---------------------------------------------------------------------- |
| `enabled`           | Master switch. `false` disables the daemon entirely (direct mode only). |
| `socket_path`       | IPC endpoint URI. Per-OS default. Accepts `unix://`, `tcp://127.0.0.1`. |
| `timeout`           | Session inactivity timeout (s). After this many seconds without IPC activity, the daemon drops the session. |
| `cleanup_interval`  | How often the daemon's cleanup loop runs (s).                          |
| `auth_token`        | Optional shared secret. When set, every IPC request must include it.   |
| `max_sessions`      | Hard cap on concurrent OPC UA sessions held by the daemon.             |
| `socket_mode`       | Unix-socket permissions (octal). Default `0600`.                       |
| `allowed_cert_dirs` | Restrict where the daemon will load OPC UA certificates from.          |
| `log_channel`       | **Laravel log channel** for daemon output.                              |
| `cache_store`       | **Laravel cache store** the daemon uses for its OPC UA client cache.   |
| `auto_publish`      | When `true`, daemon auto-publishes subscription notifications via PSR-14 (= Laravel events). |

The two Laravel-specific lines are `log_channel` and `cache_store`:
they reference channels / stores from your existing
`config/logging.php` and `config/cache.php` — no separate logger
or cache backend to configure.

See [Session manager · configuration](./session-manager.md) for
the operational reading.

## Section 3 — `connections`

<!-- @code-block language="php" label="connections" -->
```php
'connections' => [
    'default' => [
        'endpoint' => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),

        // Channel security
        'security_policy' => env('OPCUA_SECURITY_POLICY', 'None'),
        'security_mode'   => env('OPCUA_SECURITY_MODE', 'None'),

        // User identity
        'username' => env('OPCUA_USERNAME'),
        'password' => env('OPCUA_PASSWORD'),

        // Client (application) certificate
        'client_certificate' => env('OPCUA_CLIENT_CERT'),
        'client_key'         => env('OPCUA_CLIENT_KEY'),
        'ca_certificate'     => env('OPCUA_CA_CERT'),

        // User-identity X.509 certificate
        'user_certificate' => env('OPCUA_USER_CERT'),
        'user_key'         => env('OPCUA_USER_KEY'),

        // Client behaviour
        'timeout'           => env('OPCUA_TIMEOUT', 5.0),
        'auto_retry'        => env('OPCUA_AUTO_RETRY'),
        'batch_size'        => env('OPCUA_BATCH_SIZE'),
        'browse_max_depth'  => env('OPCUA_BROWSE_MAX_DEPTH', 10),

        // Trust store
        'trust_store_path'   => env('OPCUA_TRUST_STORE_PATH'),
        'trust_policy'       => env('OPCUA_TRUST_POLICY'),
        'auto_accept'        => env('OPCUA_AUTO_ACCEPT', false),
        'auto_accept_force'  => env('OPCUA_AUTO_ACCEPT_FORCE', false),

        // Write-type auto-detection
        'auto_detect_write_type' => env('OPCUA_AUTO_DETECT_WRITE_TYPE', true),

        // Read-metadata cache
        'read_metadata_cache' => env('OPCUA_READ_METADATA_CACHE', false),

        // Logging — Laravel log channel name
        'log_channel' => 'stdout',

        // Auto-connect (only relevant when auto_publish is on)
        // 'auto_connect' => false,
        // 'subscriptions' => [ /* see auto-publish docs */ ],
    ],
],
```
<!-- @endcode-block -->

Every connection key has an env-var default. For single-server
setups, you only need:

<!-- @code-block language="bash" label=".env (minimal)" -->
```bash
OPCUA_ENDPOINT=opc.tcp://plc.local:4840
```
<!-- @endcode-block -->

For secured connections:

<!-- @code-block language="bash" label=".env (secured)" -->
```bash
OPCUA_ENDPOINT=opc.tcp://plc.local:4840
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key
OPCUA_USERNAME=integrations
OPCUA_PASSWORD=secret
OPCUA_TRUST_STORE_PATH=/var/lib/opcua/trust
OPCUA_TRUST_POLICY=fingerprint+expiry
```
<!-- @endcode-block -->

See [Configuration · Security](./security.md) and the
[Security section](../security/policies-and-modes.md) for the
detail.

## Multiple connections

Add entries to the `connections` array:

<!-- @code-block language="php" label="multi-connection" -->
```php
'connections' => [
    'default' => [ /* ... */ ],

    'plc-line-a' => [
        'endpoint' => 'opc.tcp://line-a.plant.local:4840',
        'security_policy' => 'Basic256Sha256',
        'security_mode' => 'SignAndEncrypt',
        'client_certificate' => env('OPCUA_CLIENT_CERT'),
        'client_key' => env('OPCUA_CLIENT_KEY'),
        'username' => env('OPCUA_LINE_A_USER'),
        'password' => env('OPCUA_LINE_A_PASS'),
    ],

    'plc-line-b' => [
        'endpoint' => 'opc.tcp://line-b.plant.local:4840',
        // ...
    ],

    'historian' => [
        'endpoint' => 'opc.tcp://historian.plant.local:4840',
        // Read-mostly: enable metadata cache aggressively
        'read_metadata_cache' => true,
        'timeout' => 30.0,
    ],
],
```
<!-- @endcode-block -->

Use them via `Opcua::connection('plc-line-a')->read(...)`. See
[Using the client · Named
connections](../using-the-client/named-connections.md).

## Per-connection log channel

The `log_channel` key inside a connection picks the Laravel log
channel for **client-side** logging from that connection. The
default `stdout` works during artisan commands; in production
you typically point this at a dedicated channel:

<!-- @code-block language="php" label="config/logging.php" -->
```php
'channels' => [
    'opcua' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua.log'),
        'level'  => 'info',
        'days'   => 14,
    ],
    // ...
],
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="config/opcua.php" -->
```php
'connections' => [
    'default' => [
        // ...
        'log_channel' => 'opcua',
    ],
],
```
<!-- @endcode-block -->

See [Observability · Logging](../observability/logging.md).

## How the config is consumed

`OpcuaServiceProvider` merges defaults from the package's own
`config/opcua.php`, then `OpcuaManager` reads
`$app['config']['opcua']` lazily at first connection. Two
implications:

- **Editing config requires no app restart for new requests** —
  PHP-FPM workers pick up changes on the next request (unless
  you've cached config with `php artisan config:cache`).
- **`config:cache` freezes the config**. Run
  `php artisan config:clear` after editing for the change to
  take effect.

## What the file doesn't control

- **The OPC UA protocol details.** NodeIds, attribute IDs,
  builtin types — those are
  [`opcua-client`](https://github.com/php-opcua/opcua-client)
  concerns, accessed via the facade at runtime.
- **Per-call options.** Things like `useCache`, `refresh`,
  `attributeId` are method arguments, not config.
- **Application-level routing.** Which controller calls which
  connection is your application code's call.

The config file is the **deployment** surface. The Laravel app
code is the **runtime** surface.

## Verification

After editing `config/opcua.php`:

<!-- @code-block language="bash" label="terminal — verify" -->
```bash
php artisan config:clear
php artisan tinker
>>> config('opcua.connections.default.endpoint');
=> "opc.tcp://plc.local:4840"

>>> \PhpOpcua\LaravelOpcua\Facades\Opcua::read('i=2261')->getValue();
=> "..."
```
<!-- @endcode-block -->

If the Tinker read returns a value, the config flows through
correctly.

## What to read next

- [Connections](./connections.md) — multiple connections deep
  dive.
- [Environment variables](./environment-variables.md) — full
  `.env` reference.
- [Security](./security.md) — the security block in the
  connection.
- [Session manager](./session-manager.md) — the
  `session_manager` block.
