---
eyebrow: 'Docs · Operations'
lede:    'Subscriptions stream value changes from the server. The package surfaces the real opcua-client API (createSubscription + createMonitoredItems / createEventMonitoredItem). Notifications arrive as PSR-14 events.'

see_also:
  - { href: '../events/data-events.md',                  meta: '6 min' }
  - { href: '../session-manager/auto-publish.md',        meta: '5 min' }
  - { href: '../recipes/livewire-realtime-dashboard.md', meta: '7 min' }

prev: { label: 'Method calls', href: './method-calls.md' }
next: { label: 'History',      href: './history.md' }
---

# Subscriptions

A subscription tells the OPC UA server: *send me a notification
whenever this value changes or this event fires*. The package
surfaces the underlying `opcua-client` API directly — there is **no
callback-style `subscribe()` / `monitor()` / `run()` / `unsubscribe()`
helper** on the facade.

The real surface is:

| Method                                                                                          | Purpose                                       |
| ----------------------------------------------------------------------------------------------- | --------------------------------------------- |
| `createSubscription(float $publishingInterval = 500.0, …): SubscriptionResult`                  | Create a subscription on the server           |
| `createMonitoredItems(int $subscriptionId, ?array $items = null): array\|MonitoredItemsBuilder` | Attach data-change monitored items            |
| `createEventMonitoredItem(int $subscriptionId, NodeId\|string $nodeId, array $selectFields, int $clientHandle): MonitoredItemResult` | Attach an event-shaped monitored item |
| `publish(array $acknowledgements = []): PublishResult`                                          | Drive one publish round-trip (direct mode)    |
| `deleteSubscription(int $subscriptionId): int`                                                  | Tear down                                     |

Notifications are delivered as PSR-14 events on the client's event
dispatcher (`DataChangeReceived`, `EventNotificationReceived`, alarm
events). In Laravel that dispatcher **is** `Illuminate\Events\Dispatcher`,
so listeners registered via `Event::listen()` receive them — see
[Events · Overview](../events/overview.md).

## The two modes — at a glance

| Aspect                          | Direct mode                                     | Managed mode (with auto-publish)                |
| ------------------------------- | ----------------------------------------------- | ----------------------------------------------- |
| Who drives the publish loop?    | Your PHP process (you call `publish()`)         | The daemon                                      |
| Where do events fire?           | Your PHP process                                | Daemon dispatches PSR-14 → Laravel events       |
| Best for                        | Console / scheduled / queued workers            | Real-time UIs, broadcasting, persistent loops   |
| Survives FPM request boundary?  | No                                              | Yes — daemon holds the subscription             |
| Setup complexity                | Low                                             | Medium (daemon, supervisor, broadcasting wire)  |

## Direct mode

A worker that watches values. In direct mode you must call `publish()`
yourself in a loop:

<!-- @code-block language="php" label="direct subscription — artisan command" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Event\DataChangeReceived;

class WatchSpeed extends Command
{
    protected $signature = 'plc:watch-speed';

    public function handle(OpcuaManager $opcua): int
    {
        $client = $opcua->connection();

        // Listen on the dispatcher Laravel already gave the client
        \Event::listen(function (DataChangeReceived $event) {
            $this->info('Speed = ' . $event->dataValue->getValue());
        });

        $sub = $client->createSubscription(publishingInterval: 500.0);

        $client->createMonitoredItems($sub->subscriptionId)
            ->add('ns=2;s=Speed', clientHandle: 1)
            ->execute();

        // Drive the publish loop until killed
        while (true) {
            $client->publish();
            usleep(50_000);
        }
    }
}
```
<!-- @endcode-block -->

Run it under
[Supervisor](https://laravel.com/docs/queues#supervisor-configuration)
in production.

## Managed mode

Same client API, but the daemon drives `publish()` for you when
`session_manager.auto_publish = true`:

<!-- @code-block language="php" label="managed subscription — from controller" -->
```php
$sub = Opcua::createSubscription(publishingInterval: 500.0);

Opcua::createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Speed',       clientHandle: 1)
    ->add('ns=2;s=Temperature', clientHandle: 2)
    ->execute();

// The daemon keeps the subscription alive after the request ends —
// it will publish on its own schedule and dispatch events to your
// registered Laravel listeners.
```
<!-- @endcode-block -->

You **listen** to the typed events:

<!-- @code-block language="php" label="event listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreSpeedReading
{
    private const SPEED_HANDLE = 1;

    public function handle(DataChangeReceived $event): void
    {
        if ($event->clientHandle !== self::SPEED_HANDLE) {
            return;
        }

        PlcReading::create([
            'client_handle' => $event->clientHandle,
            'value'         => $event->dataValue->getValue(),
            'status'        => $event->dataValue->statusCode,
            'source_at'     => $event->dataValue->sourceTimestamp,
        ]);
    }
}
```
<!-- @endcode-block -->

Wire the listener in `app/Providers/EventServiceProvider.php`:

<!-- @code-block language="php" label="event registration" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

protected $listen = [
    DataChangeReceived::class => [
        StoreSpeedReading::class,
    ],
];
```
<!-- @endcode-block -->

See [Events · Data events](../events/data-events.md) for the event
surface.

## Subscription parameters

The real defaults match `OpcUaClientInterface::createSubscription` —
note that `publishingInterval` is a **float** in **milliseconds** and
defaults to **500.0** (not 1000).

<!-- @code-block language="php" label="parameters" -->
```php
$sub = Opcua::createSubscription(
    publishingInterval:         500.0,  // float, ms
    lifetimeCount:              2400,   // publishes before tear-down
    maxKeepAliveCount:          10,
    maxNotificationsPerPublish: 0,      // 0 = no batch cap
    publishingEnabled:          true,
    priority:                   0,
);
```
<!-- @endcode-block -->

For most production cases, defaults are fine. Tune
`publishingInterval` only when the device cycle time or the UI
refresh target really demands it.

## Monitoring parameters

`createMonitoredItems()` (called with no `$items` argument) returns
a `MonitoredItemsBuilder` whose entries accept per-item settings —
see [`opcua-client` docs](https://github.com/php-opcua/opcua-client)
for the full builder surface. Typical knobs:

- `samplingInterval` — how often the server samples the source
- `queueSize` — server-side notification buffer per item
- `discardOldest` — overflow policy
- `deadband` — suppress changes smaller than this

For high-frequency tags where you only care about meaningful
changes, set a `deadband` matched to the engineering tolerance —
the wire stops carrying noise.

## Event-style subscriptions

For OPC UA event notifications (Server node, alarm-area nodes),
use `createEventMonitoredItem()`:

<!-- @code-block language="php" label="event subscription" -->
```php
$sub = Opcua::createSubscription(publishingInterval: 1000.0);

Opcua::createEventMonitoredItem(
    subscriptionId: $sub->subscriptionId,
    nodeId:         'ns=0;i=2253',  // Server node
    selectFields:   ['EventId', 'Time', 'Severity', 'Message'],
    clientHandle:   10,
);
```
<!-- @endcode-block -->

These arrive as `EventNotificationReceived` (plus specialised
`AlarmActivated` / `LimitAlarmExceeded` / … when the payload matches
an alarm shape) — see
[Events · Alarm events](../events/alarm-events.md).

## Lifecycle — managed mode

In managed mode, the subscription survives:

- The HTTP request that created it.
- Worker restarts (the daemon holds the OPC UA session).
- Application redeploys (the daemon is a separate process).

The subscription does **not** survive:

- Daemon restarts. After a daemon restart, all subscriptions are
  gone. Pattern: re-create them on application boot.

To explicitly tear down:

<!-- @code-block language="php" label="teardown" -->
```php
Opcua::deleteSubscription($sub->subscriptionId);
```
<!-- @endcode-block -->

## Lifecycle — direct mode

In direct mode, a subscription dies with the PHP process. The
`while (true) { publish(); }` loop is the only thing keeping the
OPC UA session alive for that subscription. Manage it under
Supervisor.

## Backpressure

A subscription can produce data faster than your application can
process it. Two failure modes:

1. **Server-side queue overflow** — `discardOldest=true` discards
   old notifications, `false` discards new ones. Either way, you
   miss data.
2. **Application-side blocking** — listener takes too long, the
   publish queue backs up.

For listeners that do non-trivial work (DB writes, broadcasts),
**queue them**:

<!-- @code-block language="php" label="queueable listener" -->
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

The event dispatcher returns immediately; the actual work happens
on a queue worker. See
[Events · Queued listeners](../events/queued-listeners.md) for the
caveats around serialising `DataChangeReceived` (it carries the live
`$client` reference).

## When NOT to use subscriptions

- **Reading a value once.** Use `Opcua::read()`.
- **Reading a value every 30 minutes from a scheduled job.** A
  scheduled job is simpler — fewer moving parts.
- **Driving a real-time chart with sub-50ms latency.** OPC UA's
  minimum practical publishing interval is ~50 ms. Tighter than
  that and you would typically use a different protocol (raw
  socket, MQTT).

## Where to read next

- [Events · Data events](../events/data-events.md) — what the
  listener sees.
- [Recipes · Livewire real-time dashboard](../recipes/livewire-realtime-dashboard.md)
  — end-to-end real-time UI.
- [Session manager · Auto-publish](../session-manager/auto-publish.md) —
  daemon-side feature flag and lifecycle.
