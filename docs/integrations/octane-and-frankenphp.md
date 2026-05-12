---
eyebrow: 'Docs · Integrations'
lede:    'OPC UA under long-running workers: Octane, FrankenPHP, RoadRunner, Swoole. State that persists across requests, connection pooling, the gotchas, and an end-to-end working setup.'

see_also:
  - { href: '../using-the-client/connection-lifecycle.md', meta: '5 min' }
  - { href: '../session-manager/overview.md',              meta: '5 min' }
  - { href: '../recipes/production-deployment.md',         meta: '6 min' }

prev: { label: 'Integration tests',  href: '../testing/integration-tests.md' }
next: { label: 'Horizon & queues',   href: './horizon-and-queues.md' }
---

# Octane and FrankenPHP

Octane (and similar long-running runtimes — FrankenPHP,
RoadRunner, Swoole) keep PHP processes alive across requests.
That changes the lifecycle of the OPC UA manager and its
connections — usually for the better, with two gotchas.

This page is **operational guidance**. The package itself does
not ship an Octane-aware bootstrapper, a flush handler, or a
worker-lifecycle listener — the patterns here are application
code you wire up yourself.

## What changes

In **classic PHP-FPM**:

- Each request → fresh `OpcuaManager` instance.
- Each request → fresh `OpcuaClient` → fresh OPC UA session.
- TCP handshake, security handshake, every time.

In **Octane / FrankenPHP**:

- Each worker → one `OpcuaManager` instance, shared across many
  requests.
- Sessions persist between requests on the same worker.
- Handshake cost is amortised across thousands of requests.

For a low-traffic app this is invisible. For a 100 req/sec
endpoint, the saving is dramatic — eliminating a 300 ms
handshake from every request.

## Setup

### Install Octane + FrankenPHP

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
```
<!-- @endcode-block -->

### Configure `config/octane.php`

The standard config works. The single bit specific to OPC UA:

<!-- @code-block language="php" label="config/octane.php — flush listener" -->
```php
return [
    // ...
    'flush' => [
        // ...
    ],

    'listeners' => [
        // ...

        WorkerStarting::class => [
            // Bootstrap the OPC UA manager once per worker
            EnsureFrameworkIsPrepared::class,
        ],

        RequestReceived::class => [
            // Nothing OPC UA-specific
        ],

        RequestTerminated::class => [
            // Nothing OPC UA-specific — DO NOT add disconnect here
        ],
    ],
];
```
<!-- @endcode-block -->

<!-- @callout type="warning" -->
**Don't `Opcua::disconnectAll()` on `RequestTerminated`.** That
would close connections at the end of every request, defeating
the entire point of Octane. There is no automatic cleanup —
register a `WorkerStopping` listener (see below) if you want
deterministic teardown.
<!-- @endcallout -->

### `Opcua` facade — works out of the box

No changes needed. The facade resolves through the container,
which Octane keeps alive between requests.

## End-to-end example

A real-time speed endpoint that benefits from persistent
connections:

<!-- @code-block language="php" label="config/opcua.php" -->
```php
return [
    'default' => 'plc',
    'connections' => [
        'plc' => [
            'endpoint'         => env('OPCUA_ENDPOINT'),
            'security_policy'  => 'Basic256Sha256',
            'security_mode'    => 'SignAndEncrypt',
            'client_cert_path' => env('OPCUA_CLIENT_CERT'),
            'client_key_path'  => env('OPCUA_CLIENT_KEY'),
            'username'         => env('OPCUA_USERNAME'),
            'password'         => env('OPCUA_PASSWORD'),
            'timeout'          => 5.0,
        ],
    ],
    'session_manager' => [
        'enabled' => false,    // direct mode; Octane handles persistence
    ],
];
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="app/Http/Controllers/SpeedController.php" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class SpeedController
{
    public function show(): JsonResponse
    {
        // On worker 1's 1000th request, this hits a cached connection.
        // Sub-millisecond round-trip on the LAN.
        $dv = Opcua::read('ns=2;s=Speed');

        return response()->json([
            'value' => $dv->getValue(),
            'at'    => $dv->sourceTimestamp?->format('c'),
            'good'  => $dv->statusCode === 0,
        ]);
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="routes/api.php" -->
```php
Route::get('/speed', SpeedController::class);
```
<!-- @endcode-block -->

Run:

<!-- @code-block language="bash" label="terminal — Octane" -->
```bash
php artisan octane:start --server=frankenphp --workers=4 --host=0.0.0.0
```
<!-- @endcode-block -->

The first request per worker opens the OPC UA session
(~300 ms with security). Subsequent requests reuse it (~5-15 ms).

## Gotcha #1 — State leakage

A worker handles many requests. State that lives on the
`OpcuaManager` is shared.

**Don't** mutate global state:

<!-- @code-block language="php" label="wrong" -->
```php
public function show(): JsonResponse
{
    // ❌ Bad: mutates the manager's config for the worker
    config(['opcua.connections.default.username' => $request->user()->username]);

    $dv = Opcua::read('ns=2;s=Speed');
    return response()->json(['value' => $dv->getValue()]);
}
```
<!-- @endcode-block -->

The next request hitting the same worker inherits the previous
user's username. Catastrophic.

**Do** use connection switching or ad-hoc connections — note that
`connectTo()` takes the endpoint URL as its **first positional
argument**, not as a key inside the config array:

<!-- @code-block language="php" label="right" -->
```php
public function show(Request $request): JsonResponse
{
    $client = Opcua::connectTo(
        endpointUrl: config('opcua.connections.default.endpoint'),
        config: [
            'username' => $request->user()->username,
            'password' => $request->user()->plcPassword,
        ],
        as: 'user-' . $request->user()->id,
    );

    $dv = $client->read('ns=2;s=Speed');
    return response()->json(['value' => $dv->getValue()]);
}
```
<!-- @endcode-block -->

`connectTo()` is keyed by config — different users get different
cached connections, no leakage.

## Gotcha #2 — Worker recycling

Octane workers restart periodically (memory cap, request cap,
explicit reload). On restart, the OPC UA connection is lost and
the next request reopens.

Tune Octane to **avoid** restarts in the request path:

<!-- @code-block language="php" label="config/octane.php — sensible limits" -->
```php
'max_requests' => 1000,         // restart after N requests per worker
```
<!-- @endcode-block -->

For 5 req/sec, that's a restart every 3-4 minutes per worker.
A worker restart means ~300 ms of re-handshake on the **next**
request to that worker — usually invisible.

## Direct vs managed mode under Octane

| Mode      | Octane behaviour                                                    |
| --------- | ------------------------------------------------------------------- |
| Direct    | Connections persist per-worker. Worker restarts close them.         |
| Managed   | Connections persist daemon-side. Worker restarts only drop IPC handles. |

**Both work.** Managed mode has one extra hop (IPC), but
isolates connection lifetime from worker lifetime. Direct mode is
simpler.

For Laravel-only deployments, direct mode under Octane is
typically enough. For deployments where the same OPC UA session
is shared across **multiple processes** (Octane + Horizon + cron
scheduler), managed mode is the cleaner choice.

## Connection reuse across requests — order of magnitude

A controller that reads 5 tags has very different cost profiles
depending on whether the OPC UA session is cold or warm. **Cold
path** dominates first-request latency under FPM; **warm path**
is what every request gets after the first under Octane.

| Step                                    | Cold path    | Warm path (same worker) |
| --------------------------------------- | ------------ | ------------------------ |
| Open TCP                                | tens of ms   | reused                   |
| Security handshake (Basic256Sha256)     | ~hundreds ms | reused                   |
| ActivateSession                         | tens of ms   | reused                   |
| 5 × read                                | tens of ms   | tens of ms               |

The actual numbers depend on the server, network, and security
policy — measure in your own environment. The qualitative point
holds: under Octane, every request after the first to a given
worker skips the handshake.

## Octane events

Octane fires events at lifecycle moments. To act on them:

<!-- @code-block language="php" label="OctaneServiceProvider" -->
```php
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;

Event::listen(WorkerStarting::class, function () {
    // Optional: pre-warm a connection at worker boot
    Opcua::read('i=2256');
});

Event::listen(WorkerStopping::class, function () {
    // Clean shutdown — closes sessions properly
    Opcua::disconnectAll();
});
```
<!-- @endcode-block -->

Pre-warming saves first-request latency at the cost of slower
boot. Worth it for low-traffic apps.

## FrankenPHP-specific

FrankenPHP runs PHP as goroutines under a Caddy server. From
PHP's perspective, it looks like Octane — same persistence
guarantees. The OPC UA package needs no FrankenPHP-specific
configuration.

One detail: FrankenPHP's worker mode shares **opcache** across
all workers, but **not** global state. The OPC UA manager is
per-worker, not per-host.

## RoadRunner

RoadRunner is older Octane-compatible. Same model:
worker-persistent state. The package works identically.

One quirk — RoadRunner workers can run in different modes
(HTTP, queue, gRPC). The OPC UA manager works in HTTP mode but
needs explicit setup for queue mode. See [Horizon and queues](./horizon-and-queues.md)
for queue-mode patterns.

## Swoole

Swoole's coroutine model is different — multiple coroutines per
process, all sharing state. The OPC UA manager is **not
coroutine-safe** in v4.x — concurrent reads from the same client
within one process can interleave on the wire.

If you must use Swoole, run one OPC UA session per coroutine
context using `connectTo()` with unique identifiers, or wait
for v5 which has coroutine-safe wire handling.

## Health probes under Octane

Add a `/health/octane-opcua` route that reads a known node — if
the per-worker connection is broken, this surfaces it
immediately:

<!-- @code-block language="php" label="health probe" -->
```php
Route::get('/health/octane-opcua', function () {
    try {
        $start = microtime(true);
        Opcua::read('i=2256');
        $duration = round((microtime(true) - $start) * 1000);

        return response()->json([
            'status'      => 'ok',
            'duration_ms' => $duration,
            'octane'      => 'on',
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error'  => $e->getMessage(),
        ], 503);
    }
});
```
<!-- @endcode-block -->

The first probe per worker hits the cold path (~300 ms);
subsequent probes hit the warm path (~5 ms). Both are useful
information.

## Where to read next

- [Horizon and queues](./horizon-and-queues.md) — queue-worker
  patterns.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  putting Octane + the OPC UA daemon together.
