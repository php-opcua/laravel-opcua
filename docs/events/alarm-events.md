---
eyebrow: 'Docs · Events'
lede:    'EventNotificationReceived and the AlarmActivated family — alarm and event notifications from OPC UA event-notifier nodes. The right channel for operator-facing alarms with acknowledgement and audit.'

see_also:
  - { href: './data-events.md',                  meta: '6 min' }
  - { href: '../operations/method-calls.md',     meta: '6 min' }
  - { href: '../recipes/alarm-routing.md',       meta: '5 min' }

prev: { label: 'Data events',         href: './data-events.md' }
next: { label: 'Queued listeners',    href: './queued-listeners.md' }
---

# Alarm events

OPC UA distinguishes **data changes** (a value moved) from **events**
(something happened: a threshold was crossed, a condition fired, an
alarm was raised). Events arrive as
`PhpOpcua\Client\Event\EventNotificationReceived`. When the
notification's payload matches a known alarm shape, the client
*also* dispatches one of the specialised classes
(`AlarmActivated`, `AlarmDeactivated`, `LimitAlarmExceeded`, …).

The fields shown below are the **real** fields on each class.

<!-- @callout type="note" -->
**Publish-driven.** Events fire when something drives the publish
loop. In **managed mode** with auto-publish, the daemon drives it
for you. In **direct mode**, your code must call
`Opcua::publish(...)`.
<!-- @endcallout -->

## Subscribing to events

Create a normal subscription, then attach an event-shaped monitored
item to an event-notifier node (typically the Server node,
`ns=0;i=2253`, or an alarm-area node):

<!-- @code-block language="php" label="event subscription" -->
```php
$sub = Opcua::createSubscription(publishingInterval: 1000.0);

Opcua::createEventMonitoredItem(
    subscriptionId: $sub->subscriptionId,
    nodeId:         'ns=0;i=2253',
    selectFields:   [
        'EventId', 'EventType', 'SourceNode', 'SourceName',
        'Time', 'ReceiveTime', 'LocalTime',
        'Message', 'Severity',
        'ConditionName', 'AckedState', 'ActiveState',
    ],
    clientHandle:   10,
);
```
<!-- @endcode-block -->

`selectFields` is the list of event attributes you want each
notification to carry. Server-side EventFilters (`Severity > N`,
`EventType = X`) are configured via the `createMonitoredItems()`
builder; see [`opcua-client` docs](https://github.com/php-opcua/opcua-client)
for the filter API.

## EventNotificationReceived — the generic class

<!-- @code-block language="php" label="EventNotificationReceived" -->
```php
namespace PhpOpcua\Client\Event;

final class EventNotificationReceived
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $sequenceNumber,
        public int $clientHandle,
        public array $eventFields,   // associative: field name => decoded value
    ) {}
}
```
<!-- @endcode-block -->

`$eventFields` is keyed by the field name from your `selectFields`.
There are **no `$eventId`, `$severity`, `$message`, `$sourceName`,
`$isActive`, `$isAcked` properties** on the event — read them out of
`$eventFields`:

```php
$severity   = $event->eventFields['Severity']     ?? null;
$message    = $event->eventFields['Message']      ?? null;
$sourceName = $event->eventFields['SourceName']   ?? null;
$eventIdHex = bin2hex($event->eventFields['EventId'] ?? '');
$isActive   = (bool) ($event->eventFields['ActiveState'] ?? false);
$isAcked    = (bool) ($event->eventFields['AckedState'] ?? false);
```

## Specialised alarm events

When `EventNotificationReceived` carries alarm-shaped fields, the
client also dispatches one of these:

| Class                       | Extra fields (beyond `$client`)                                            |
| --------------------------- | -------------------------------------------------------------------------- |
| `AlarmEventReceived`        | `sourceName`, `message`, `severity`, `eventType`                            |
| `AlarmActivated`            | `subscriptionId`, `clientHandle`, `sourceName`, `severity`, `message`       |
| `AlarmDeactivated`          | `sourceName`                                                                 |
| `AlarmAcknowledged`         | `sourceName`, `acknowledger`                                                 |
| `AlarmConfirmed`            | `sourceName`                                                                 |
| `AlarmShelved`              | `sourceName`, `shelved`                                                      |
| `AlarmSeverityChanged`      | `sourceName`, `oldSeverity`, `newSeverity`                                   |
| `LimitAlarmExceeded`        | `subscriptionId`, `clientHandle`, `sourceName`, `limitState`, `severity`     |
| `OffNormalAlarmTriggered`   | `subscriptionId`, `clientHandle`, `sourceName`, `severity`                   |

Note that `LimitAlarmExceeded` carries a **single `$limitState`
string** (e.g. `"HighHigh"`), not `limitName`/`limitValue` pairs.

## Listener — basic alarm record

<!-- @code-block language="php" label="record alarm" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\EventNotificationReceived;

class RecordAlarm implements ShouldQueue
{
    public string $queue = 'opcua-alarms';

    public function handle(EventNotificationReceived $event): void
    {
        $f = $event->eventFields;
        PlcAlarm::create([
            'event_id'    => isset($f['EventId']) ? bin2hex($f['EventId']) : null,
            'event_type'  => (string) ($f['EventType'] ?? ''),
            'source'      => $f['SourceName'] ?? null,
            'severity'    => $f['Severity'] ?? null,
            'message'     => $f['Message'] ?? null,
            'occurred_at' => $f['Time'] ?? null,
            'is_active'   => (bool) ($f['ActiveState'] ?? false),
            'is_acked'    => (bool) ($f['AckedState'] ?? false),
        ]);
    }
}
```
<!-- @endcode-block -->

## Routing to operators (using a specialised class)

Listening for `AlarmActivated` lets you skip the field unpacking for
the most common case:

<!-- @code-block language="php" label="alarm routing" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\AlarmActivated;

class RouteAlarmToOperator implements ShouldQueue
{
    public string $queue = 'opcua-alarms';

    public function handle(AlarmActivated $event): void
    {
        if (($event->severity ?? 0) < 800) {
            return;
        }

        $operator = $this->findOperator($event->sourceName);
        if (!$operator) {
            return;
        }

        $operator->notify(new PlcAlarmRaised(
            source:   $event->sourceName,
            severity: $event->severity,
            message:  $event->message,
        ));
    }

    private function findOperator(?string $sourceName): ?User
    {
        return User::role('operator')
            ->whereHas('assignedLines', fn ($q) => $q->where('plc_source', $sourceName))
            ->first();
    }
}
```
<!-- @endcode-block -->

`PlcAlarmRaised` is a Laravel Notification — it can dispatch to mail,
Slack, broadcast, database, SMS, etc. See
[Integrations · Notifications](../integrations/notifications.md).

## Acknowledgement

Acknowledging an alarm is an OPC UA method call. The real client API
is `call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult`:

<!-- @code-block language="php" label="ack endpoint" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Types\StatusCode;

class AcknowledgeAlarmController
{
    public function ack(Request $request, OpcuaManager $opcua): JsonResponse
    {
        $request->validate([
            'event_id' => ['required', 'string'],
            'comment'  => ['nullable', 'string', 'max:255'],
        ]);

        $eventId = hex2bin($request->input('event_id'));

        $result = $opcua->call(
            objectId: 'ns=0;i=2782',   // ConditionType
            methodId: 'ns=0;i=9111',   // Acknowledge
            inputArguments: [
                $eventId,
                ['locale' => 'en', 'text' => $request->input('comment', '')],
            ],
        );

        if (! StatusCode::isGood($result->statusCode)) {
            return response()->json([
                'error' => 'Ack failed: ' . StatusCode::getName($result->statusCode),
            ], 422);
        }

        PlcAlarmAck::create([
            'event_id' => $request->input('event_id'),
            'user_id'  => $request->user()->id,
            'comment'  => $request->input('comment'),
            'acked_at' => now(),
        ]);

        return response()->json(['status' => 'acked']);
    }
}
```
<!-- @endcode-block -->

The server emits a follow-up `AlarmAcknowledged` (and an
`EventNotificationReceived` with `AckedState = true`).

## Audit chain

Every alarm and every ack is recorded. A 3-table schema does this
cleanly:

| Table             | Records                                            |
| ----------------- | -------------------------------------------------- |
| `plc_alarms`      | Alarm events (active, inactive)                    |
| `plc_alarm_acks`  | Acknowledgements                                   |
| `plc_alarm_chain` | Chain of state changes for a single event id       |

See [Recipes · Alarm routing](../recipes/alarm-routing.md) for the
full migration and listener set.

## Severity → routing

| Severity     | Common usage                  | Route                          |
| ------------ | ----------------------------- | ------------------------------ |
| 1 - 100      | Info / diagnostic              | Database only                  |
| 101 - 400    | Low warning                    | Database + UI banner           |
| 401 - 700    | Warning                        | Operator dashboard + Slack     |
| 701 - 900    | Critical                       | Slack + page on-call           |
| 901 - 1000   | Emergency                      | Page + phone tree              |

Configure the thresholds in config:

<!-- @code-block language="php" label="config/alarms.php" -->
```php
return [
    'severity_thresholds' => [
        'page'   => 900,
        'slack'  => 700,
        'email'  => 400,
        'record' => 0,
    ],
];
```
<!-- @endcode-block -->

…and route in the listener:

<!-- @code-block language="php" label="threshold routing" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\AlarmActivated;

class RouteBySeverity implements ShouldQueue
{
    public function handle(AlarmActivated $event): void
    {
        $sev = $event->severity ?? 0;
        $thresh = config('alarms.severity_thresholds');

        if ($sev >= $thresh['page'])  { $this->page($event); }
        if ($sev >= $thresh['slack']) { $this->slack($event); }
        if ($sev >= $thresh['email']) { $this->email($event); }

        $this->record($event);
    }
    // ...
}
```
<!-- @endcode-block -->

## Broadcasting alarms to the UI

<!-- @code-block language="php" label="alarm broadcast" -->
```php
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use PhpOpcua\Client\Event\AlarmActivated;

class AlarmBroadcasted implements ShouldBroadcastNow
{
    public function __construct(
        public readonly ?string $source,
        public readonly ?int    $severity,
        public readonly ?string $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('plc.alarms');
    }
}

class BroadcastAlarmToUI
{
    public function handle(AlarmActivated $event): void
    {
        broadcast(new AlarmBroadcasted(
            source:   $event->sourceName,
            severity: $event->severity,
            message:  $event->message,
        ));
    }
}
```
<!-- @endcode-block -->

Operator UIs (Livewire, Filament) subscribe to `plc.alarms` and
update in real time.

## Filters

Server-side filtering (via OPC UA EventFilter) is much cheaper than
listener-side filtering — a noisy plant can emit thousands of
low-severity events per minute and you do not want to deliver them
all to PHP. See the `createMonitoredItems()` filter options in
[`opcua-client`](https://github.com/php-opcua/opcua-client) for the
exact API.

## Where to read next

- [Queued listeners](./queued-listeners.md) — scaling rules.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) — full
  pipeline with migrations, listeners, ack endpoint.
- [Integrations · Notifications](../integrations/notifications.md) —
  the routing options.
