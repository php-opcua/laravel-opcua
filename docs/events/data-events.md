---
eyebrow: 'Docs · Events'
lede:    'DataChangeReceived — the most common subscription event. Field reference, listener patterns, persistence, broadcast, and the rules for handling thousands per second without melting the worker.'

see_also:
  - { href: '../operations/subscriptions.md',           meta: '7 min' }
  - { href: '../session-manager/auto-publish.md',       meta: '5 min' }
  - { href: './queued-listeners.md',                    meta: '5 min' }
  - { href: '../recipes/persistent-tag-history.md',     meta: '6 min' }

prev: { label: 'Connection events', href: './connection-events.md' }
next: { label: 'Alarm events',      href: './alarm-events.md' }
---

# Data events

`PhpOpcua\Client\Event\DataChangeReceived` fires every time a publish
response carries a monitored-item data change. In a typical real-time
UI it is the event that drives everything.

The fields shown below are the **real** fields on the event object.

<!-- @callout type="note" -->
**Publish-driven.** This event fires whenever a publish response
carries a notification. In **managed mode** with auto-publish, the
daemon drives the publish loop for you. In **direct mode**, your code
must call `Opcua::publish(...)` to receive notifications.
<!-- @endcallout -->

## Field reference

<!-- @code-block language="php" label="DataChangeReceived" -->
```php
namespace PhpOpcua\Client\Event;

final class DataChangeReceived
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $sequenceNumber,
        public int $clientHandle,   // matches the handle you set on createMonitoredItems()
        public DataValue $dataValue,
    ) {}
}
```
<!-- @endcode-block -->

`DataValue` (from `opcua-client`):

| Accessor             | Returns               | Meaning                                          |
| -------------------- | --------------------- | ------------------------------------------------ |
| `getValue()`         | mixed                 | The decoded value (the underlying `Variant`'s value) |
| `$dv->statusCode`    | int                   | 0 = good                                         |
| `$dv->sourceTimestamp` | `?DateTimeImmutable` | When the device produced the value             |
| `$dv->serverTimestamp` | `?DateTimeImmutable` | When the OPC UA server timestamped it          |

> `DataValue::$value` is **private** — always use `getValue()`. There
> is no `$dv->type` or `$dv->dimensions` field; those concepts live
> on the underlying `Variant` (which is wrapped, not exposed).

> The event does **not** carry the nodeId directly — only the
> `clientHandle` you assigned at item-creation time. Keep your own
> `clientHandle => nodeId` map (or set the handle to a hash of the
> nodeId).

## Simple listener — log

<!-- @code-block language="php" label="log listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class LogDataChanges
{
    public function handle(DataChangeReceived $event): void
    {
        Log::channel('plc-data')->info(
            "handle={$event->clientHandle} = " . var_export($event->dataValue->getValue(), true),
            [
                'status' => $event->dataValue->statusCode,
                'sub'    => $event->subscriptionId,
            ],
        );
    }
}
```
<!-- @endcode-block -->

## Persistence — Eloquent

<!-- @code-block language="php" label="persistence listener" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreReadings implements ShouldQueue
{
    public string $queue = 'opcua-data';

    public function handle(DataChangeReceived $event): void
    {
        if ($event->dataValue->statusCode !== 0) {
            return;  // skip bad readings
        }

        PlcReading::create([
            'client_handle' => $event->clientHandle,
            'value'         => $event->dataValue->getValue(),
            'source_at'     => $event->dataValue->sourceTimestamp,
            'server_at'     => $event->dataValue->serverTimestamp,
        ]);
    }
}
```
<!-- @endcode-block -->

`ShouldQueue` is **mandatory** for any persistence listener on a
high-frequency subscription — see [Queued listeners](./queued-listeners.md).

> **Beware serialisation.** `DataChangeReceived::$client` is a live
> client object that does not serialise cleanly through Laravel's
> queue. When implementing `ShouldQueue`, copy the primitive fields
> you need (handle, value, timestamps) into a job constructor and
> dispatch that job from a non-queued listener — or use Laravel's
> `__sleep()` / `__serialize()` machinery to drop `$client` before
> the listener is serialised. The safest pattern is a tiny synchronous
> listener that dispatches an explicit job:

```php
class FanOutDataChange
{
    public function handle(DataChangeReceived $event): void
    {
        StoreReadingJob::dispatch(
            $event->clientHandle,
            $event->dataValue->getValue(),
            $event->dataValue->statusCode,
            $event->dataValue->sourceTimestamp,
        );
    }
}
```

## Broadcasting to the UI

A two-step bridge — a tiny synchronous listener fires a separate
broadcast event:

<!-- @code-block language="php" label="broadcast event" -->
```php
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use PhpOpcua\Client\Event\DataChangeReceived;

class TagUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int     $clientHandle,
        public readonly mixed   $value,
        public readonly ?string $sourceAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("plc.handle.{$this->clientHandle}"), new Channel('plc.all')];
    }
}

class BroadcastTagUpdate
{
    public function handle(DataChangeReceived $event): void
    {
        broadcast(new TagUpdated(
            clientHandle: $event->clientHandle,
            value:        $event->dataValue->getValue(),
            sourceAt:     $event->dataValue->sourceTimestamp?->format('c'),
        ));
    }
}
```
<!-- @endcode-block -->

The browser subscribes to `plc.all` for a dashboard or to
`plc.handle.<n>` for a single-tag widget.

`ShouldBroadcastNow` skips the broadcast queue — sub-100 ms
end-to-end. For higher volumes, use `ShouldBroadcast` (which queues)
plus a dedicated `broadcasts` worker.

See [Integrations · Broadcasting](../integrations/broadcasting.md).

## Caching the latest value

A pattern that pairs well with broadcasting — let any reader query
the latest value cheaply:

<!-- @code-block language="php" label="latest-value cache" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class CacheLatestValue implements ShouldQueue
{
    public string $queue = 'opcua-cache';

    public function handle(DataChangeReceived $event): void
    {
        Cache::put(
            "plc:latest:{$event->clientHandle}",
            [
                'value'  => $event->dataValue->getValue(),
                'status' => $event->dataValue->statusCode,
                'at'     => $event->dataValue->sourceTimestamp?->format('c'),
            ],
            minutes: 5,
        );
    }
}
```
<!-- @endcode-block -->

…then in a controller:

<!-- @code-block language="php" label="cache reader" -->
```php
Route::get('/tags/{handle}/latest', function (int $handle) {
    return response()->json(Cache::get("plc:latest:{$handle}", null));
});
```
<!-- @endcode-block -->

No round-trip to OPC UA — the cache is always within
`publishingInterval` ms of fresh.

## Threshold-based alerting

<!-- @code-block language="php" label="threshold alert" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class AlertOnHighTemperature
{
    private const TEMP_HANDLE = 42;  // your assigned handle for the temp tag

    public function handle(DataChangeReceived $event): void
    {
        if ($event->clientHandle !== self::TEMP_HANDLE) {
            return;
        }

        $temp = (float) $event->dataValue->getValue();
        if ($temp < 90.0) {
            return;
        }

        // Throttle to one alert per 5 minutes per handle
        $key = "alert-fired:{$event->clientHandle}";
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, true, minutes: 5);

        Notification::route('slack', config('alerts.ops_channel'))
            ->notify(new HighTemperatureAlert($temp));
    }
}
```
<!-- @endcode-block -->

The throttling cache is essential — without it, a fluctuating sensor
at the threshold can fire 100 alerts in a second.

## Subscription keep-alives and lifecycle

There is no dedicated "subscription expired" event in `opcua-client`.
The events you can observe on a subscription's lifecycle are:

| Event                      | Fires when…                                       |
| -------------------------- | ------------------------------------------------- |
| `SubscriptionCreated`      | `createSubscription()` succeeded                  |
| `SubscriptionKeepAlive`    | The server sent an empty publish response         |
| `SubscriptionDeleted`      | `deleteSubscription()` succeeded                  |
| `SubscriptionTransferred`  | `transferSubscriptions()` returned Good           |

If you stop receiving `DataChangeReceived` for a long stretch (e.g.
2× the keep-alive interval), the server most likely expired the
subscription due to a missed publish acknowledgement — re-create it.

Pair `PublishResponseReceived` with a "last seen" timestamp per
subscription to detect this from your application code; the library
does not raise an event on expiry itself.

## Server status

There is no dedicated "server status" event class in `opcua-client`.
To track the server's running state, poll the
`Server.ServerStatus.State` node (`i=2259`) on a normal subscription
and react to its `DataChangeReceived`:

```php
// Server.ServerStatus.State is a 0-based ServerState enum:
// 0=Running, 1=Failed, 2=NoConfiguration, 3=Suspended,
// 4=Shutdown, 5=Test, 6=CommunicationFault, 7=Unknown
```

## Volume tuning

A tracker for in-flight event throughput:

<!-- @code-block language="php" label="throughput tracker" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class TrackThroughput
{
    public function handle(DataChangeReceived $event): void
    {
        Cache::increment('opcua:rate:' . now()->format('Y-m-d-H-i'));
    }
}
```
<!-- @endcode-block -->

Useful to graph events-per-minute and spot drops or spikes.

## What NOT to do in a listener

- **Synchronous HTTP calls.** Queue them.
- **Synchronous DB transactions across many tables.** Queue them.
- **Blocking I/O of any kind.** Queue it.
- **Heavy computation.** Queue it.
- **In-memory transforms.** Fine synchronously.
- **Single-row inserts.** Fine *only if* the volume is low.

The rule: if the listener can take more than 5 ms in the 99th
percentile, queue it.

## Where to read next

- [Alarm events](./alarm-events.md) — the alarm-specific notification.
- [Queued listeners](./queued-listeners.md) — scaling rules.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  the end-to-end persistence pattern.
