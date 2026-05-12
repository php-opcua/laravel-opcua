---
eyebrow: 'Docs · Using the client'
lede:    'connectTo() opens a connection from a plain config array — no entry in config/opcua.php required. The right tool for fleet-style deployments where endpoints are discovered at runtime.'

see_also:
  - { href: './named-connections.md',                meta: '5 min' }
  - { href: '../configuration/connections.md',       meta: '8 min' }
  - { href: './connection-lifecycle.md',             meta: '5 min' }

prev: { label: 'Named connections',  href: './named-connections.md' }
next: { label: 'Connection lifecycle', href: './connection-lifecycle.md' }
---

# Ad-hoc connections

`Opcua::connectTo()` opens a connection from a plain PHP array
without needing an entry in `config/opcua.php`. Use it when the
endpoint is genuinely dynamic — discovered from a fleet registry,
derived from a request parameter, or coming from another system.

## The signature

The endpoint URL is the **first positional argument**, not a key
inside the config array:

```php
Opcua::connectTo(
    string $endpointUrl,
    array $config = [],
    ?string $as = null,
): OpcUaClientInterface
```

## The shape

<!-- @code-block language="php" label="basic ad-hoc" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connectTo(
    endpointUrl: "opc.tcp://plc-{$serial}.factory.local:4840",
    config:      ['timeout' => 5.0],
);

$dv = $client->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

The `$config` array accepts the same keys as a `connections.*`
entry in `config/opcua.php` (except for `endpoint`, which is the
first argument). Missing keys take sensible defaults
(security policy `None`, anonymous, default timeout).

If you intend to retrieve the same client later (or close it by
name), pass an `$as` label:

```php
$client = Opcua::connectTo('opc.tcp://...', $cfg, as: 'plc-line-a');
// ...later:
Opcua::connection('plc-line-a');  // same instance
Opcua::disconnect('plc-line-a');
```

Without an `$as`, the manager keys the connection internally as
`'ad-hoc:' . $endpointUrl`.

## Why not just use named connections?

Named connections are best when the set is **closed and known
ahead of time**. Ad-hoc is best when the set is open:

| Scenario                                            | Use                  |
| --------------------------------------------------- | -------------------- |
| Three PLCs in a fixed plant layout                  | Named connections    |
| One historian per tenant, configured statically     | Named connections    |
| 200-PLC fleet discovered from a registry            | Ad-hoc               |
| User-provided endpoint (operator tool)              | Ad-hoc               |
| Edge gateway that connects to whichever PLC is online | Ad-hoc             |

The line: **does the config live in code (`config/opcua.php`) or
in data (a database, a registry, a request)?**

## Caching identity

`connectTo()` stores the resulting client under a name. Two calls
with the same `$as` (or, when `$as` is `null`, the same
`endpointUrl`) return distinct ad-hoc connections in the manager
— but if you pass the same name, the second `connectTo()` creates
a **new** client and overwrites the cached one. To reuse the
cached client, call `Opcua::connection($as)` instead:

<!-- @code-block language="php" label="reuse a named ad-hoc client" -->
```php
$a = Opcua::connectTo('opc.tcp://plc-1:4840', as: 'plc-1');
$b = Opcua::connection('plc-1');
// $a === $b
```
<!-- @endcode-block -->

## Disconnecting an ad-hoc client

`disconnect()` takes the **name** (string), not the client
instance:

<!-- @code-block language="php" label="disconnect by name" -->
```php
Opcua::connectTo('opc.tcp://plc-1:4840', as: 'plc-1');
// ...
Opcua::disconnect('plc-1');          // by name
// OR
Opcua::disconnectAll();              // closes everything
```
<!-- @endcode-block -->

There is **no overload that accepts a client instance** — use
the `$as` name (or `'ad-hoc:' . $endpointUrl` if you didn't pass
one). In managed mode, `disconnect()` also tells the daemon to
close the underlying session.

## Fleet pattern

<!-- @code-block language="php" label="fleet read" -->
```php
class FleetReader
{
    public function __construct(
        private readonly OpcuaManager $opcua,
        private readonly PlcRegistry  $registry,
    ) {}

    public function readSpeed(string $plcSerial): ?float
    {
        $entry = $this->registry->findOrFail($plcSerial);

        $client = $this->opcua->connectTo(
            endpointUrl: $entry->endpoint,
            config: [
                'security_policy'    => $entry->security_policy,
                'security_mode'      => $entry->security_mode,
                'client_certificate' => $entry->cert_path,
                'client_key'         => $entry->key_path,
                'username'           => $entry->username,
                'password'           => $entry->password,
                'timeout'            => 8.0,
            ],
            as: 'plc-' . $plcSerial,
        );

        return $client->read('ns=2;s=Speed')->getValue();
    }
}
```
<!-- @endcode-block -->

`PlcRegistry` is your domain. The package doesn't define a
storage shape for it — store the fleet in Eloquent, in Redis, in
a config file, wherever fits your operational picture.

## Caveat — credentials in config arrays

A config array passed to `connectTo()` contains plaintext
credentials. Two implications:

1. **Don't log it.** The package never logs config payloads, but
   if your application code does (e.g. `Log::debug($config)`),
   secrets leak.
2. **Don't serialise it.** The same constraint as
   [Facade vs injection](./facade-vs-injection.md) — don't store
   a config array in a job's properties unless you accept that
   it ends up on the queue, on disk, and in Horizon's UI.

The right shape for queued jobs is to store the registry **key**,
re-resolve credentials from the registry inside `handle()`:

<!-- @code-block language="php" label="job — fleet-safe" -->
```php
class SamplePlc implements ShouldQueue
{
    public function __construct(public string $plcSerial) {}

    public function handle(FleetReader $reader): void
    {
        $speed = $reader->readSpeed($this->plcSerial);
        // ...
    }
}
```
<!-- @endcode-block -->

## When the endpoint comes from a user

If the endpoint URL is user-controlled (admin tool, operator
interface), **validate it** before passing to `connectTo()`:

<!-- @code-block language="php" label="validation" -->
```php
$request->validate([
    'endpoint' => [
        'required',
        'string',
        'starts_with:opc.tcp://',
        // narrow further: allowed hostnames, allowed port ranges
        function (string $attr, string $value, Closure $fail) {
            $parts = parse_url($value);
            if (!str_ends_with($parts['host'] ?? '', '.factory.local')) {
                $fail('Endpoint must be on the factory network.');
            }
        },
    ],
]);
```
<!-- @endcode-block -->

The package doesn't sandbox connections — if you pass a config,
the package tries to open it. Network-layer egress filtering is
the right place for the hard guarantee.

## Mixed style

Named connections and ad-hoc connections coexist freely. A
typical multi-plant deployment has:

- Named: `historian`, `mes`, `scada-gateway` — fixed
  infrastructure.
- Ad-hoc: per-PLC clients resolved from a fleet registry.

`Opcua::connection('historian')` and
`Opcua::connectTo([...])` resolve through the same manager and
share connection caching.

## Where to read next

- [Connection lifecycle](./connection-lifecycle.md) — what happens
  between `connectTo()` and the eventual `disconnect()`.
- [Configuration · Connections](../configuration/connections.md) —
  the configured-side equivalent.
