---
eyebrow: 'Docs · Session manager'
lede:    'Auto-publish — the daemon-side feature that drives the publish loop and dispatches the real opcua-client events to whatever PSR-14 dispatcher was injected. In Laravel, that dispatcher is Illuminate\\Events\\Dispatcher, so the events arrive at your listeners natively.'

see_also:
  - { href: '../operations/subscriptions.md',  meta: '7 min' }
  - { href: '../events/data-events.md',        meta: '6 min' }
  - { href: '../events/queued-listeners.md',   meta: '5 min' }
  - { href: '../recipes/livewire-realtime-dashboard.md', meta: '7 min' }

prev: { label: 'Starting the daemon', href: './starting-the-daemon.md' }
next: { label: 'Production supervisor', href: './production-supervisor.md' }
---

# Auto-publish

When `session_manager.auto_publish` is on, the daemon drives the
OPC UA publish loop itself for every active session. As
notifications arrive (data changes, events, alarms), the daemon
dispatches the real `PhpOpcua\Client\Event\*` classes on the
PSR-14 `EventDispatcherInterface` that `opcua:session` injected.

Because Laravel's `Illuminate\Events\Dispatcher` implements
PSR-14, those events are delivered to listeners registered with
`Event::listen(...)` — no bridge class is needed.

## What it gives you

<!-- @code-block language="text" label="data flow" -->
```text
OPC UA Server              Daemon                                 Laravel
   │                        │                                       │
   │ PublishResponse        │                                       │
   ├───────────────────────►│                                       │
   │                        │ PSR-14: DataChangeReceived            │
   │                        ├──────────────────────────────────────►│ (Illuminate\Events\Dispatcher)
   │                        │                                       ├─► registered Laravel listeners
   │                        │                                       │
```
<!-- @endcode-block -->

In application code, you write listeners on the real event class:

<!-- @code-block language="php" label="listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreSpeedReading
{
    public function handle(DataChangeReceived $event): void
    {
        PlcReading::create([
            'client_handle' => $event->clientHandle,
            'value'         => $event->dataValue->getValue(),
            'source_at'     => $event->dataValue->sourceTimestamp,
        ]);
    }
}
```
<!-- @endcode-block -->

The daemon is doing the OPC UA work. Your listener just reacts.

## Enabling

Two requirements:

1. `session_manager.auto_publish` is `true` in `config/opcua.php`
   (or `OPCUA_AUTO_PUBLISH=true` in `.env`).
2. The daemon was started with the Laravel-wired
   `php artisan opcua:session` command (so Laravel's PSR-14
   dispatcher is the one the daemon publishes onto).

That is it. With auto-publish on, the daemon walks every active
session on every tick of its publish loop. **Application code
does not "opt in"** — the daemon drives publishing for any session
held by the daemon. Your job is to register listeners.

## Event types

The daemon dispatches the **real** `opcua-client` events — not
package-specific re-namings. The most common ones in this
context:

| Class                                            | When the daemon dispatches it          |
| ------------------------------------------------ | -------------------------------------- |
| `PhpOpcua\Client\Event\DataChangeReceived`       | Monitored item data change             |
| `PhpOpcua\Client\Event\EventNotificationReceived`| Event from an event-notifier node      |
| `PhpOpcua\Client\Event\AlarmActivated`           | Alarm-shaped event with ActiveState=Active |
| `PhpOpcua\Client\Event\LimitAlarmExceeded`       | Limit alarm trip                       |
| `PhpOpcua\Client\Event\PublishResponseReceived`  | Every publish response (incl. keep-alives) |
| `PhpOpcua\Client\Event\SubscriptionKeepAlive`    | Empty publish response                 |

The package does **not** ship classes like `OpcuaStatusChange` or
`OpcuaSubscriptionExpired` — there is no direct equivalent in
`opcua-client`. To track server-status changes, subscribe to the
`Server.ServerStatus.State` node (`ns=0;i=2259`) and react in a
`DataChangeReceived` listener. To detect a subscription that
silently died, pair `PublishResponseReceived` with a "last seen"
timestamp and re-subscribe when the gap exceeds the keep-alive
interval.

See [Events](../events/overview.md) for the full reference.

## What the daemon actually emits

The daemon doesn't know about Laravel — it dispatches the typed
`PhpOpcua\Client\Event\*` objects on the
`Psr\EventDispatcher\EventDispatcherInterface` it was given.
`OpcuaServiceProvider` resolves that interface from Laravel's
container and `SessionCommand::resolveEventDispatcher()` passes
the resolved instance into the daemon when `auto_publish` is on.

Because Laravel's `Illuminate\Events\Dispatcher` implements PSR-14,
events flow directly to `Event::listen(...)` listeners — **no
`OpcuaEventBridge` class exists or is needed**.

Any other PSR-14 listener wired up in the daemon's container also
receives the notifications — useful for daemon-internal metrics.

## Subscription lifecycle in managed mode

<!-- @code-block language="php" label="end-to-end subscription" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

// 1. Start the subscription (from a controller, command, anywhere)
$sub = Opcua::createSubscription(publishingInterval: 500.0);

Opcua::createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Speed', clientHandle: 1)
    ->execute();

// 2. Done — the request returns. The daemon now holds the subscription
//    and will drive publishing on its own schedule when auto-publish is on.

// 3. Listeners receive data changes asynchronously
class WriteSpeedToCache
{
    public function handle(DataChangeReceived $event): void
    {
        if ($event->clientHandle === 1) {
            Cache::put('live:speed', $event->dataValue->getValue());
        }
    }
}
```
<!-- @endcode-block -->

The subscription persists across requests, worker restarts, and
deploys (as long as the daemon stays up).

## Listener registration

Register listeners the standard Laravel way:

<!-- @code-block language="php" label="app/Providers/EventServiceProvider.php" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\EventNotificationReceived;

protected $listen = [
    DataChangeReceived::class => [
        WriteSpeedToCache::class,
        StoreSpeedReading::class,
    ],
    EventNotificationReceived::class => [
        RouteAlarmToOperator::class,
    ],
];
```
<!-- @endcode-block -->

Or in Laravel 11+, with `#[AsEventListener]`:

<!-- @code-block language="php" label="auto-discovered listener" -->
```php
use Illuminate\Events\Attributes\AsEventListener;
use PhpOpcua\Client\Event\DataChangeReceived;

#[AsEventListener]
class WriteSpeedToCache
{
    public function handle(DataChangeReceived $event): void { /* ... */ }
}
```
<!-- @endcode-block -->

## Queued listeners

For listeners that do non-trivial work (DB writes, broadcasts,
HTTP calls), implement `ShouldQueue`:

<!-- @code-block language="php" label="queued listener" -->
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

The event delivery becomes async — fast event dispatch into the
queue, slow work runs on a worker. Essential for high-throughput
subscriptions. See [Queued listeners](../events/queued-listeners.md)
for the serialisation caveat (`$event->client` is a live object
and does not serialise cleanly).

## Broadcasting

A common pattern — relay OPC UA data changes to the browser via
Laravel broadcasting:

<!-- @code-block language="php" label="broadcasting wrapper event" -->
```php
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PhpOpcua\Client\Event\DataChangeReceived;

class TagUpdated implements ShouldBroadcast
{
    public function __construct(public int $clientHandle, public mixed $value) {}

    public function broadcastOn(): Channel
    {
        return new Channel('plc.live');
    }
}

class BroadcastOpcuaChange
{
    public function handle(DataChangeReceived $event): void
    {
        event(new TagUpdated($event->clientHandle, $event->dataValue->getValue()));
    }
}
```
<!-- @endcode-block -->

A Livewire / Alpine.js / vanilla JS client subscribes to `plc.live`
and updates the UI in real time.

End-to-end example: [Recipes · Livewire real-time dashboard](../recipes/livewire-realtime-dashboard.md).

## Performance characteristics

| Workload                                            | What to watch                                    |
| --------------------------------------------------- | ------------------------------------------------ |
| 10 monitored items, 1 Hz                            | Trivial. No tuning needed                        |
| 100 items, 1 Hz                                     | Watch listener time. If > 100 ms, queue them     |
| 1000 items, 1 Hz                                    | Queue listeners. Group writes. Consider batching |
| 100 items at 100 Hz                                 | Pretty rare — queue ruthlessly; deadband heavily |

The daemon itself handles thousands of notifications per second
without trouble. The bottleneck is what listeners do with them.

## When NOT to enable auto-publish

- You don't use subscriptions at all (only on-demand reads /
  writes).
- You run the daemon as a generic IPC service and don't want it
  emitting Laravel events.
- You're testing — turn it off to isolate test failures.

The setting is **per daemon process**, not per connection. To
have selective subscriptions, filter inside listeners on
`$event->clientHandle` (the only identifier
`DataChangeReceived` / `EventNotificationReceived` carry — the
nodeId is not on the event itself).

## Recovery after daemon restart

When the daemon restarts, all subscriptions are gone. The
package doesn't auto-restore them — the application must
re-subscribe.

A common pattern: re-subscribe on application boot, or via a
scheduled command that checks the daemon's session list and
recreates anything missing. The `auto_connect` plus
`subscriptions` keys in `config/opcua.php` make the daemon itself
do this for you (when `auto_publish` is on) — see the example
block in the published config file.

<!-- @code-block language="php" label="re-subscribe command" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

class ReSubscribeOpcua extends Command
{
    protected $signature = 'opcua:resubscribe';

    public function handle(OpcuaManager $opcua): int
    {
        $client = $opcua->connection();
        $sub = $client->createSubscription(publishingInterval: 500.0);

        $builder = $client->createMonitoredItems($sub->subscriptionId);
        foreach (PlcTag::tracked()->get() as $tag) {
            $builder->add($tag->node_id, clientHandle: $tag->id);
        }
        $builder->execute();

        $this->info('Re-subscribed ' . PlcTag::tracked()->count() . ' items');
        return 0;
    }
}
```
<!-- @endcode-block -->

Run this command from a systemd `ExecStartPost=` or similar after
the daemon comes up.

## Where to read next

- [Events · Data events](../events/data-events.md) — field
  reference for `DataChangeReceived`.
- [Events · Queued listeners](../events/queued-listeners.md) —
  scaling the listener side.
- [Recipes · Livewire real-time dashboard](../recipes/livewire-realtime-dashboard.md)
  — the canonical end-to-end pattern.
