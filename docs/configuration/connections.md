---
eyebrow: 'Docs · Configuration'
lede:    'Each entry in connections is one OPC UA server. Name it sensibly, configure its security, set its per-connection log channel — then call ::connection(''name'') wherever you need it.'

see_also:
  - { href: './config-file.md',                            meta: '6 min' }
  - { href: '../using-the-client/named-connections.md',    meta: '4 min' }
  - { href: '../recipes/multi-plant-tenant.md',            meta: '6 min' }

prev: { label: 'The config file', href: './config-file.md' }
next: { label: 'Environment variables', href: './environment-variables.md' }
---

# Connections

A "connection" in `laravel-opcua` is a named record under
`connections` in `config/opcua.php` that describes how to reach
one OPC UA server. Pattern: one entry per server, named after
the role the server plays.

## Anatomy of a connection

<!-- @code-block language="php" label="example — full" -->
```php
'plc-line-a' => [
    // Required
    'endpoint' => 'opc.tcp://line-a.plant.local:4840',

    // Channel security
    'security_policy' => 'Basic256Sha256',     // None, Basic256Sha256, Aes256Sha256RsaPss, Ecc*, ...
    'security_mode'   => 'SignAndEncrypt',     // None, Sign, SignAndEncrypt

    // User identity
    'username' => env('OPCUA_LINE_A_USER'),
    'password' => env('OPCUA_LINE_A_PASS'),

    // Application certificate (when security policy != None)
    'client_certificate' => '/etc/opcua/client.pem',
    'client_key'         => '/etc/opcua/client.key',
    'ca_certificate'     => null,

    // User X.509 (alternative to username/password)
    'user_certificate' => null,
    'user_key'         => null,

    // Client behaviour
    'timeout'          => 5.0,
    'auto_retry'       => 3,
    'batch_size'       => null,   // auto-detected from server
    'browse_max_depth' => 10,

    // Trust store (validating the server's certificate)
    'trust_store_path'  => '/var/lib/opcua/trust',
    'trust_policy'      => 'fingerprint+expiry',
    'auto_accept'       => false,
    'auto_accept_force' => false,

    // Library behaviour flags
    'auto_detect_write_type' => true,
    'read_metadata_cache'    => false,

    // Logging — Laravel log channel
    'log_channel' => 'opcua',

    // Auto-connect (with auto_publish)
    'auto_connect' => false,
    'subscriptions' => [],
],
```
<!-- @endcode-block -->

Most keys are optional. The minimum is `endpoint`:

<!-- @code-block language="php" label="minimal connection" -->
```php
'default' => [
    'endpoint' => 'opc.tcp://localhost:4840',
],
```
<!-- @endcode-block -->

## Naming conventions

Connection names appear in:

- `Opcua::connection('plc-line-a')->read(...)` — call site
- `config/opcua.php` keys
- Logs (when `log_channel` is per-connection)
- The session manager's `list` output

Conventions that scale:

| Style                       | Example                | When                                                |
| --------------------------- | ---------------------- | --------------------------------------------------- |
| Role-based                  | `historian`, `live`     | Single-server-per-role architectures                |
| Location-based              | `plc-line-a`, `plc-line-b` | Plant-floor topologies                            |
| Tenant-based                | `tenant-acme`, `tenant-corp` | Multi-tenant SaaS                              |
| Hierarchical                | `factory-1.line-a.station-3` | Deep equipment hierarchies                      |

Avoid:

- Names that change with deployment environment
  (`prod-plc`, `staging-plc`). Use the same name across envs and
  let the `endpoint` change via env vars.
- Embedded host names (`192.168.1.100`). Names should be stable
  even if the IP changes.

## The default connection

Whichever connection is referenced by the `default` key in
`config/opcua.php`:

<!-- @code-block language="php" label="config/opcua.php" -->
```php
'default' => env('OPCUA_CONNECTION', 'default'),

'connections' => [
    'default' => [ /* ... */ ],
    'plc-line-a' => [ /* ... */ ],
],
```
<!-- @endcode-block -->

The facade methods called without `::connection(...)` use this
one:

<!-- @code-block language="php" label="default usage" -->
```php
Opcua::read('ns=2;s=...');
// Same as
Opcua::connection('default')->read('ns=2;s=...');
// Same as (when 'default' resolves to 'plc-line-a' via OPCUA_CONNECTION)
Opcua::connection('plc-line-a')->read('ns=2;s=...');
```
<!-- @endcode-block -->

Override at deployment time with `OPCUA_CONNECTION=plc-line-a`
in `.env` — useful for multi-tenant deployments where each
deployment hits a different physical PLC.

## Connection caching

`OpcuaManager` caches `Client` instances per name **within the
request lifecycle**. Three implications:

- Two `Opcua::read(...)` calls in the same controller method
  share the same `Client`. No duplicate connection cost.
- PHP-FPM destroys the singleton between requests — the next
  request rebuilds the `Client`.
- Octane / FrankenPHP **preserve** the singleton across requests
  in the same worker. The `Client` lives for the worker's
  lifetime. See [Octane and FrankenPHP](../integrations/octane-and-frankenphp.md).

The session manager changes none of this — the `Client` is
`ManagedClient` in managed mode, still cached per-name on the
manager.

## Multiple-connection patterns

### Pattern 1 — One PLC per production line

<!-- @code-block language="php" label="multi-line factory" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint' => 'opc.tcp://plc-a.factory.local:4840',
        // ...
    ],
    'plc-line-b' => [
        'endpoint' => 'opc.tcp://plc-b.factory.local:4840',
        // ...
    ],
    'plc-line-c' => [
        'endpoint' => 'opc.tcp://plc-c.factory.local:4840',
        // ...
    ],
],
```
<!-- @endcode-block -->

Each line has its own connection. Application code:

<!-- @code-block language="php" label="usage" -->
```php
foreach (['plc-line-a', 'plc-line-b', 'plc-line-c'] as $line) {
    $speed = Opcua::connection($line)->read('ns=2;s=PLC/Speed')->getValue();
    // ...
}
```
<!-- @endcode-block -->

### Pattern 2 — Different roles per server

<!-- @code-block language="php" label="historian + live" -->
```php
'connections' => [
    'live' => [
        'endpoint' => 'opc.tcp://scada.plant.local:4840',
        'read_metadata_cache' => false,  // values change constantly, no point
        'timeout' => 2.0,                 // fast-fail for UI responsiveness
    ],
    'historian' => [
        'endpoint' => 'opc.tcp://historian.plant.local:4840',
        'read_metadata_cache' => true,   // metadata is stable
        'timeout' => 30.0,                // history reads can take time
    ],
],
```
<!-- @endcode-block -->

Different settings per server role. The application picks the
right one:

<!-- @code-block language="php" label="usage" -->
```php
$current = Opcua::connection('live')->read('ns=2;s=Tag')->getValue();
$past    = Opcua::connection('historian')
    ->historyReadRaw(
        'ns=2;s=Tag',
        new \DateTimeImmutable('-1 hour'),
        new \DateTimeImmutable(),
    );
```
<!-- @endcode-block -->

### Pattern 3 — Multi-tenant

For multi-tenant SaaS where each tenant has its own OPC UA
server, connections come from the database:

<!-- @code-block language="php" label="dynamic — ad-hoc connection" -->
```php
$tenantConfig = auth()->user()->tenant->opcua_config;

$client = Opcua::connectTo(
    $tenantConfig['endpoint'],
    $tenantConfig,
    as: 'tenant-' . auth()->user()->tenant_id,
);
```
<!-- @endcode-block -->

See [Using the client · Ad-hoc connections](../using-the-client/ad-hoc-connections.md)
and [Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md).

## Per-connection log channel

`log_channel` inside a connection points at a channel in
`config/logging.php`. The OPC UA client of that connection
writes diagnostic logs there.

<!-- @code-block language="php" label="config/logging.php" -->
```php
'channels' => [
    'opcua' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua.log'),
        'level'  => 'info',
        'days'   => 14,
    ],
    'opcua-line-a' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua-line-a.log'),
        'level'  => 'info',
        'days'   => 14,
    ],
],
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="config/opcua.php" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint' => 'opc.tcp://line-a.plant.local:4840',
        'log_channel' => 'opcua-line-a',
    ],
],
```
<!-- @endcode-block -->

Per-connection channels keep each PLC's logs isolated —
invaluable when diagnosing a single line's issues without
sifting through unrelated traffic.

See [Observability · Logging](../observability/logging.md).

## Per-call connection switching

You don't have to commit to one connection at the call site:

<!-- @code-block language="php" label="switching" -->
```php
public function compare(): array
{
    $a = Opcua::connection('plc-line-a')->read('ns=2;s=PLC/Speed')->getValue();
    $b = Opcua::connection('plc-line-b')->read('ns=2;s=PLC/Speed')->getValue();
    return ['a' => $a, 'b' => $b];
}
```
<!-- @endcode-block -->

Both `Client` instances are cached on the manager. The two reads
each pay their own OPC UA round-trip (the protocol is per-session)
but no extra connection cost.

## What if a connection name is unknown?

<!-- @code-block language="php" label="unknown connection" -->
```php
Opcua::connection('not-defined');
// throws InvalidArgumentException
```
<!-- @endcode-block -->

The manager rejects unknown names at the connection step rather
than when the first method is called — fail-fast against typos
and stale routing code.

## Hot-reloading connections

Adding a new connection to `config/opcua.php`:

<!-- @code-block language="bash" label="terminal" -->
```bash
# Edit config/opcua.php
php artisan config:clear     # if config:cache is enabled
```
<!-- @endcode-block -->

The next HTTP request picks up the new connection. In Octane,
the worker may still have the old config cached:

<!-- @code-block language="bash" label="terminal — Octane reload" -->
```bash
php artisan octane:reload
```
<!-- @endcode-block -->

## What to read next

- [Environment variables](./environment-variables.md) — every
  env var the package reads.
- [Using the client · Named connections](../using-the-client/named-connections.md)
  — the call-site mechanics.
- [Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md)
  — dynamic per-tenant connections.
