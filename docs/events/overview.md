---
eyebrow: 'Docs · Events'
lede:    'How the package exposes OPC UA events to Laravel. Laravel''s event dispatcher implements PSR-14, so opcua-client''s real events flow through Event::listen(...) natively — no bridge class required.'

see_also:
  - { href: './connection-events.md',  meta: '5 min' }
  - { href: './data-events.md',        meta: '6 min' }
  - { href: './alarm-events.md',       meta: '5 min' }
  - { href: './queued-listeners.md',   meta: '5 min' }

prev: { label: 'Monitoring the daemon', href: '../session-manager/monitoring-the-daemon.md' }
next: { label: 'Connection events',     href: './connection-events.md' }
---

# Events overview

This package does not ship its own event classes. Instead it relies
on the events dispatched by the underlying `opcua-client` library
(catalogued in
[`opcua-client` · Event reference](https://github.com/php-opcua/opcua-client/blob/master/docs/observability/event-reference.md))
and on Laravel's own event dispatcher.

## Why this just works

`OpcuaServiceProvider` resolves
`Psr\EventDispatcher\EventDispatcherInterface` from the container and
hands it to `OpcuaManager`. In Laravel, that PSR-14 interface is
implemented by `Illuminate\Events\Dispatcher` (since Laravel ~7.0).

The chain is:

1. `opcua-client` dispatches a typed event object (e.g.
   `PhpOpcua\Client\Event\DataChangeReceived`) on the PSR-14
   dispatcher it was given.
2. That dispatcher **is** Laravel's `Illuminate\Events\Dispatcher`.
3. Laravel listeners registered with `Event::listen(...)` for the
   event's class name receive it.

In managed mode (`opcua-session-manager`) the same thing happens
inside the daemon: `AutoPublisher` dispatches the same
`PhpOpcua\Client\Event\*` classes on the PSR-14 dispatcher
`SessionCommand` wires up — Laravel's `Dispatcher`.

There is **no `OpcuaEventBridge` class**, and you don't need one.

## The event classes

All event classes live under `PhpOpcua\Client\Event\` —
**not** under `PhpOpcua\LaravelOpcua\Events\`. The full catalogue
(47 classes) is in the
[`opcua-client` event reference](https://github.com/php-opcua/opcua-client/blob/master/docs/observability/event-reference.md).
The most useful slices for Laravel apps:

| Group                | Class                                              | Fields (besides `$client`)                                        |
| -------------------- | -------------------------------------------------- | ----------------------------------------------------------------- |
| Connection lifecycle | `ClientConnecting`                                 | `endpointUrl`                                                     |
|                      | `ClientConnected`                                  | `endpointUrl`                                                     |
|                      | `ClientDisconnecting`                              | —                                                                 |
|                      | `ClientDisconnected`                               | — (no `reason`)                                                   |
|                      | `ClientReconnecting`                               | `endpointUrl` (signals an attempt — there is no separate `Reconnected`) |
|                      | `ConnectionFailed`                                 | `endpointUrl`, `exception`                                        |
| Subscriptions        | `SubscriptionCreated`, `SubscriptionDeleted`, `SubscriptionKeepAlive`, `SubscriptionTransferred` | `subscriptionId` (+ extras)            |
| Monitored items      | `MonitoredItemCreated`, `MonitoredItemModified`, `MonitoredItemDeleted`                          | `subscriptionId`, `monitoredItemId`, … |
| Publish              | `DataChangeReceived`                               | `subscriptionId`, `sequenceNumber`, `clientHandle`, `dataValue`   |
|                      | `EventNotificationReceived`                        | `subscriptionId`, `sequenceNumber`, `clientHandle`, `eventFields` |
|                      | `PublishResponseReceived`                          | `subscriptionId`, `sequenceNumber`, `notificationCount`, `moreNotifications` |
| Alarms               | `AlarmActivated`, `LimitAlarmExceeded`, `AlarmAcknowledged`, …                                  | see alarm events page                  |

> Publish-time events (`DataChangeReceived`, `EventNotificationReceived`,
> alarm events) only fire when something is driving the publish loop.
> In **managed mode with auto-publish** the daemon drives it for you.
> In **direct mode** they only fire when your code calls
> `Opcua::publish(...)` (or the equivalent on an injected client).

## Listening — the basics

In `app/Providers/EventServiceProvider.php`:

<!-- @code-block language="php" label="EventServiceProvider" -->
```php
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ConnectionFailed;
use PhpOpcua\Client\Event\DataChangeReceived;

protected $listen = [
    DataChangeReceived::class => [
        \App\Listeners\Opcua\StoreSpeedReading::class,
        \App\Listeners\Opcua\BroadcastTagUpdate::class,
    ],
    ConnectionFailed::class => [
        \App\Listeners\Opcua\AlertOpsTeam::class,
    ],
    ClientConnected::class => [
        \App\Listeners\Opcua\RecordPlcUp::class,
    ],
];
```
<!-- @endcode-block -->

Listeners are plain Laravel listener classes:

<!-- @code-block language="php" label="listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreSpeedReading
{
    public function handle(DataChangeReceived $event): void
    {
        $value = $event->dataValue->getValue();
        // $event->clientHandle identifies which monitored item produced this
        // ...
    }
}
```
<!-- @endcode-block -->

## Auto-discovery (Laravel 11+)

Laravel can auto-discover listeners by their typed `handle()` /
`__invoke()` parameter, and the `#[AsEventListener]` attribute pins
the binding explicitly:

<!-- @code-block language="php" label="auto-discovered" -->
```php
namespace App\Listeners\Opcua;

use Illuminate\Events\Attributes\AsEventListener;
use PhpOpcua\Client\Event\DataChangeReceived;

#[AsEventListener]
class StoreSpeedReading
{
    public function handle(DataChangeReceived $event): void { /* ... */ }
}
```
<!-- @endcode-block -->

## Closures and `Event::listen`

Inline closures work for trivial cases:

<!-- @code-block language="php" label="inline listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

Event::listen(function (DataChangeReceived $event) {
    Log::channel('plc-data')->info('change', [
        'sub'   => $event->subscriptionId,
        'h'     => $event->clientHandle,
        'value' => $event->dataValue->getValue(),
    ]);
});
```
<!-- @endcode-block -->

## Listening on the wildcard

For diagnostics or a generic audit logger:

<!-- @code-block language="php" label="wildcard listener" -->
```php
Event::listen('PhpOpcua\\Client\\Event\\*', function (string $name, array $payload) {
    Log::channel('plc-events')->info($name, ['payload' => $payload]);
});
```
<!-- @endcode-block -->

Captures every `opcua-client` event. Good for development; in
production, prefer targeted listeners.

## Queued listeners

Listeners that do non-trivial work (DB writes, broadcasts) should
implement `ShouldQueue`:

<!-- @code-block language="php" label="queued" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreSpeedReading implements ShouldQueue
{
    public string $queue = 'opcua-data';

    public function handle(DataChangeReceived $event): void { /* ... */ }
}
```
<!-- @endcode-block -->

The event dispatcher returns immediately; the work runs on a queue
worker. See [Queued listeners](./queued-listeners.md) for the tuning
rules.

> **A note on serialisation.** `DataChangeReceived` carries an
> `$event->client` reference (the live `OpcUaClientInterface`), which
> is not safely serialisable for queued listeners. When queueing,
> extract the primitive fields you need (clientHandle, dataValue
> primitive, subscriptionId) inside `handle()` *before* dispatching
> follow-up jobs.

## Per-connection filtering

The events do not carry a Laravel "connection name" — they carry the
live `$client` instance. If you need to know which named connection
produced the event, compare instances:

<!-- @code-block language="php" label="filter by connection" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreSpeedReading
{
    public function handle(DataChangeReceived $event): void
    {
        if ($event->client !== app('opcua')->connection('plc-line-a')) {
            return; // ignore other lines
        }

        // ...
    }
}
```
<!-- @endcode-block -->

For most apps it is simpler to register a different listener per
connection, by binding the event manually on the dispatcher attached
to that specific client.

## Per-node filtering

Monitored-item events expose `$clientHandle`, the value you assigned
when you called `createMonitoredItems()` / `createEventMonitoredItem()`.
Keep a `clientHandle => nodeId` map on your service and look up the
node ID in the listener:

<!-- @code-block language="php" label="filter by handle" -->
```php
class StoreSpeedReading
{
    public function handle(DataChangeReceived $event): void
    {
        if ($event->clientHandle !== 1) {  // 1 = ns=2;s=Speed
            return;
        }
        // ...
    }
}
```
<!-- @endcode-block -->

`DataChangeReceived` does **not** carry the `nodeId` directly — only
the `clientHandle` you assigned at item-creation time.

## Broadcasting

Bridge an `opcua-client` event to a broadcasted Laravel event:

<!-- @code-block language="php" label="broadcasting" -->
```php
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PhpOpcua\Client\Event\DataChangeReceived;

class TagUpdated implements ShouldBroadcast
{
    public function __construct(
        public readonly int $clientHandle,
        public readonly mixed $value,
    ) {}

    public function broadcastOn(): Channel { return new Channel('plc.live'); }
}

class BroadcastOpcuaChange
{
    public function handle(DataChangeReceived $event): void
    {
        broadcast(new TagUpdated(
            $event->clientHandle,
            $event->dataValue->getValue(),
        ));
    }
}
```
<!-- @endcode-block -->

A separate event keeps the original `DataChangeReceived`
non-broadcasting (no serialisation overhead for listeners that just
write a row).

See [Integrations · Broadcasting](../integrations/broadcasting.md).

## When events fire — the timing

| Event                                | Fires when…                                                       |
| ------------------------------------ | ----------------------------------------------------------------- |
| `ClientConnected`                    | Session activation succeeds                                       |
| `ClientDisconnected`                 | Disconnect (clean or broken) finished                             |
| `ConnectionFailed`                   | `connect()` raised                                                |
| `ClientReconnecting`                 | `reconnect()` started (no separate "Reconnected" event — `ClientConnected` fires again on success) |
| `DataChangeReceived`                 | A publish response carried a data-change notification             |
| `EventNotificationReceived`          | A publish response carried an event notification                  |
| `PublishResponseReceived`            | Any publish response (including keep-alives)                      |
| `SubscriptionKeepAlive`              | Server sent an empty publish response                             |

All events are **synchronous** on the dispatch path. Long listeners
block the event-emitting code. Use `ShouldQueue` for anything heavier
than a few milliseconds.

## Where to read next

- [Connection events](./connection-events.md) — open / close / error
  lifecycle.
- [Data events](./data-events.md) — subscription value changes.
- [Alarm events](./alarm-events.md) — alarm / event notifications.
- [Queued listeners](./queued-listeners.md) — scaling the listener
  side.
