---
eyebrow: 'Docs · Using the client'
lede:    'When connections open, when they close, what happens on error, what survives across requests. Lifecycle rules for FPM, queues, Octane.'

see_also:
  - { href: '../integrations/octane-and-frankenphp.md', meta: '7 min' }
  - { href: '../session-manager/overview.md',           meta: '5 min' }

prev: { label: 'Ad-hoc connections', href: './ad-hoc-connections.md' }
next: { label: 'Using builders',     href: './using-builders.md' }
---

# Connection lifecycle

How the package decides when to open, reuse, and close
connections — across the three runtime shapes Laravel apps live
in.

## The lifecycle

1. **Resolve** — `Opcua::connection($name)` or
   `Opcua::connectTo($config)` asks the `OpcuaManager` for a
   client matching that connection.
2. **Open if needed** — if the cache has no entry, the manager
   opens a new client (direct mode) or a `ManagedClient` (managed
   mode).
3. **Use** — calls go through. Errors bubble as
   `OpcUaException` subclasses.
4. **Cache** — the client stays cached on the `OpcuaManager` for
   the lifetime of the manager instance.
5. **Close** — explicit `disconnect()` call, end of request, or
   end of worker.

## Manager scope

The `OpcuaManager` is bound as a **container singleton**. Its
lifetime equals the lifetime of the Laravel container instance:

| Runtime                     | Container lifetime          | Manager lifetime           |
| --------------------------- | --------------------------- | -------------------------- |
| PHP-FPM                     | One request                 | One request                |
| Artisan command             | One process                 | One process                |
| Queue worker (`queue:work`) | Until restart or memory cap | Long-lived                 |
| Horizon worker              | Until restart or memory cap | Long-lived                 |
| Octane / FrankenPHP         | Until worker restart        | Long-lived (multi-request) |
| Tests                       | One test method (per `RefreshApplication` defaults) | One test method |

The implication: in **FPM**, the cache is per-request and
connections always open fresh. In **long-running runtimes**,
the cache persists across requests — which is desirable for
performance but creates the lifecycle questions below.

## Open semantics

`OpcuaManager::connection($name)`:

1. Looks up `$name` in `connections.*`. Throws
   `InvalidArgumentException` if missing.
2. Returns the cached client if present.
3. Otherwise constructs and caches a new client:
   - **Direct mode**: builds an `OpcuaClient` from the config
     and the package's PSR-3/14/16 wiring.
   - **Managed mode** (daemon reachable + `enabled = true`):
     builds a `ManagedClient` pointing at the daemon's socket
     and opens a session there.

Opening is **lazy**. The first method call (`read`, `write`, …)
triggers the connection handshake. Calling `connection('plc-line-a')`
without using the result allocates an instance but doesn't open
a TCP socket.

## Use semantics

A live client is a real (or virtual via the daemon) TCP
session to the server. Calls go over that session until:

- An explicit `disconnect()`.
- A network error severs the session (`ConnectionException`).
- The server-side session times out
  (`InactiveSessionException`).

The package **does not** auto-reconnect transparently. A failed
call surfaces as an exception; the next call attempts a fresh
open if you didn't catch and act on the failure.

## Disconnect semantics

`disconnect()` ends the session and removes the cache entry:

<!-- @code-block language="php" label="disconnect surface" -->
```php
Opcua::disconnect();              // default connection
Opcua::disconnect('plc-line-b');  // by name
Opcua::disconnectAll();           // every cached connection
```
<!-- @endcode-block -->

`disconnect()` accepts a **name** (or `null` for the default).
There is no overload that takes a client instance — for ad-hoc
connections, disconnect by the `$as` name you passed to
`connectTo()` (or, if you didn't pass one,
`'ad-hoc:' . $endpointUrl`).

After `disconnect()`, the **next** call to that connection name
opens fresh. In **managed mode**, `disconnect()` also sends a
`close` IPC frame to the daemon — releasing the daemon-side
session so the next call rebuilds it. Expect the handshake cost
on the rebuild.

## Error semantics

The package surfaces all OPC UA errors as exceptions from
`PhpOpcua\Client\Exception\*` — see
[Reference · Exceptions](../reference/exceptions.md). The
manager's behaviour on failure:

- **`ConnectionException`** during use: the call fails. The
  cached client object is **not** automatically invalidated; if
  you want the next call to rebuild the session, call
  `Opcua::disconnect($name)` first (the underlying
  `opcua-client` `auto_retry` setting can also recover individual
  service calls without your involvement — see
  [Configuration · Connections](../configuration/connections.md)).
- **`ServiceException`** (server returned a bad-status code): the
  call fails, the connection stays live — the session is fine,
  the operation was rejected.
- **`SecurityException` / `ConfigurationException`** on open:
  the cache entry is **not** populated; the exception bubbles to
  the caller.

In managed mode, daemon-reported "session expired" comes back as
a `ConnectionException` (the `session_not_found` IPC error is
mapped to it). After such an error, `disconnect()` + retry is the
standard recovery.

## Long-running workers — when to recycle

In `queue:work` / Horizon / Octane workers, a connection can
remain open for hours. Two reasons to recycle:

1. **Session timeout.** OPC UA servers enforce a `MaxSessionTimeout`
   (commonly 1-2 hours). The package only learns about the timeout
   when the next call fails with `InactiveSessionException`.
2. **Server-side state drift.** Subscriptions might fall behind,
   monitored items might be invalidated by the server.

Laravel idioms for proactive recycling:

<!-- @code-block language="php" label="periodic disconnect — Horizon supervisor" -->
```php
// In a scheduled job (every hour for example)
class RecycleOpcuaConnections
{
    public function handle(OpcuaManager $opcua): void
    {
        $opcua->disconnectAll();
    }
}

// Schedule it
$schedule->job(new RecycleOpcuaConnections)->hourly();
```
<!-- @endcode-block -->

A more invasive option — set
[`--max-time`](https://laravel.com/docs/queues#worker-lifespan)
on the worker so it restarts every hour anyway. That naturally
recycles all connections.

## Octane workers — the request boundary

Under Octane / FrankenPHP, a single worker handles many requests
in sequence. The `OpcuaManager` singleton **persists**, so
connections do too. Two implications:

- ✅ Good — opens are amortised, calls are fast.
- ❌ Bad — a request that mutates connection state (e.g.,
  swapping out the username at runtime) leaks into the next
  request handled by the same worker.

The package's defensive posture: it doesn't expose runtime config
mutation. Use named connections (or `connectTo()`) to switch
identities, not config swap-outs.

See [Octane / FrankenPHP](../integrations/octane-and-frankenphp.md)
for the worker-lifecycle deep dive.

## Managed mode — daemon-held sessions

When `session_manager.enabled = true` and the daemon is reachable,
"open" means **acquire a session from the daemon**. The daemon
holds the actual TCP connection; the Laravel process holds an
IPC handle.

Consequences:

- Multiple workers can share the **same** daemon-held session by
  matching on `(endpoint + credentials + security)`.
- Worker restarts don't close server-side sessions.
- The daemon's `session_timeout` controls when an idle session
  is actually closed.

See [Session manager · Overview](../session-manager/overview.md).

## Tests

In tests with `RefreshDatabase` and Laravel's standard
container-per-test isolation, each test starts with a clean
`OpcuaManager`. No leaks between tests.

If you mock the manager (`Opcua::shouldReceive(...)`), the mock
is per-test by definition.

See [Pest setup](../testing/pest-setup.md) for the recommended
harness.

## Disconnect-on-shutdown

The package does **not** register a `register_shutdown_function()`
hook. If you need deterministic cleanup (e.g. release daemon-side
IPC handles at the end of a request), call `Opcua::disconnectAll()`
explicitly — typically from a controller's `terminate()` method,
a queue worker's `afterTerminate` callback, or an Octane
`RequestTerminated` listener.

For classic FPM, PHP's request teardown closes the underlying
sockets anyway — explicit `disconnectAll()` is usually only
needed when you want the daemon to immediately release the
session in managed mode.

## Where to read next

- [Using builders](./using-builders.md) — fluent builders for
  reads/writes/browses.
- [Octane and FrankenPHP](../integrations/octane-and-frankenphp.md)
  — long-running worker patterns.
