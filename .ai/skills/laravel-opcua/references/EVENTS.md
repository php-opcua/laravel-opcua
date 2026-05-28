# Events

`laravel-opcua` injects Laravel's `EventDispatcherInterface` into the underlying `Client` / `ManagedClient` via `OpcuaServiceProvider`. Every event the core dispatches becomes a Laravel event with the same class.

## Event namespace

All event classes live under `PhpOpcua\Client\Event\*`. They are immutable `final readonly` DTOs. Public readonly properties — no getters.

## All 56 events

### Connection lifecycle (6)

| Class | When | Key properties |
|---|---|---|
| `ClientConnecting` | Before secure-channel + session creation | `endpointUrl` |
| `ClientConnected` | After session activation | `endpointUrl`, `sessionId` (NodeId) |
| `ClientDisconnecting` | Before close | `endpointUrl` |
| `ClientDisconnected` | After socket close | `endpointUrl` |
| `ClientReconnecting` | When `auto_retry` kicks in | `endpointUrl`, `attempt` (int) |
| `ConnectionFailed` | Connect attempt threw | `endpointUrl`, `exception` |

### Secure channel + session (5)

| Class | When |
|---|---|
| `SecureChannelOpened` | OpenSecureChannelResponse received |
| `SecureChannelClosed` | CloseSecureChannelRequest sent |
| `SessionCreated` | CreateSessionResponse received (before activation) |
| `SessionActivated` | ActivateSessionResponse received |
| `SessionClosed` | CloseSessionResponse received |

### Read / write (5)

| Class | When | Key properties |
|---|---|---|
| `NodeValueRead` | After successful Read | `nodeId`, `attributeId`, `dataValue` |
| `NodeValueWritten` | After successful Write | `nodeId`, `attributeId`, `value`, `dataType` (BuiltinType) |
| `NodeValueWriteFailed` | Write returned non-Good | `nodeId`, `value`, `statusCode` |
| `WriteTypeDetecting` | Auto-detect read-before-write started | `nodeId` |
| `WriteTypeDetected` | Auto-detect resolved | `nodeId`, `dataType` (BuiltinType) |

### Browse (1)

| Class | Key properties |
|---|---|
| `NodeBrowsed` | `nodeId`, `direction`, `references` (ReferenceDescription[]) |

### Subscriptions (5)

| Class | When |
|---|---|
| `SubscriptionCreated` | CreateSubscriptionResponse — `subscriptionId`, `revisedPublishingInterval` |
| `SubscriptionDeleted` | DeleteSubscriptionResponse — `subscriptionId` |
| `SubscriptionKeepAlive` | Server sent keep-alive (no data) — `subscriptionId`, `sequenceNumber` |
| `SubscriptionTransferred` | TransferSubscriptionsResponse — `subscriptionId`, `availableSequenceNumbers` |
| `PublishResponseReceived` | Any PublishResponse, including with data and keep-alive |

### Monitored items (4)

| Class | When |
|---|---|
| `MonitoredItemCreated` | `subscriptionId`, `monitoredItemId`, `nodeId`, `clientHandle` |
| `MonitoredItemModified` | `subscriptionId`, `monitoredItemId`, `revisedSamplingInterval` |
| `MonitoredItemDeleted` | `subscriptionId`, `monitoredItemId` |
| `TriggeringConfigured` | `subscriptionId`, `triggeringItemId`, `addedLinks`, `removedLinks` |

### Notifications (2) — **the ones you most often listen to**

| Class | Key properties |
|---|---|
| `DataChangeReceived` | `subscriptionId`, `clientHandle`, `dataValue` (DataValue with value/timestamp/statusCode) |
| `EventNotificationReceived` | `subscriptionId`, `clientHandle`, `selectFields` (string[]), `eventFields` (mixed[]) |

### Alarms — Part 9 (9)

| Class | When |
|---|---|
| `AlarmEventReceived` | Generic alarm event (raw fields) |
| `AlarmActivated` | Active state went true |
| `AlarmDeactivated` | Active state went false |
| `AlarmAcknowledged` | Operator acknowledged |
| `AlarmConfirmed` | Operator confirmed |
| `AlarmShelved` | Operator shelved (silenced) |
| `AlarmSeverityChanged` | Severity field changed |
| `LimitAlarmExceeded` | A `LimitAlarmType` crossed a limit (`level` enum: High/Low/HighHigh/LowLow) |
| `OffNormalAlarmTriggered` | An `OffNormalAlarmType` left its normal state |

Alarm event payloads include `eventId` (bytestring), `severity` (int), `message` (LocalizedText), `sourceName`, `time` (DateTimeImmutable). See `docs/events/alarm-events.md`.

### History (4)

| Class | When (Part 11 §6.9) |
|---|---|
| `HistoryDataUpdated` | HistoryUpdate `UpdateData` action result Good |
| `HistoryDataDeleted` | HistoryUpdate `DeleteRawModified` or `DeleteAtTime` action Good |
| `HistoryEventUpdated` | HistoryUpdate `UpdateEvent` action Good |
| `HistoryEventDeleted` | HistoryUpdate `DeleteEvent` action Good |

### File transfer (4) — Part 5 §C.2/§C.3

| Class | When |
|---|---|
| `FileOpened` | OpenFile Call succeeded — `fileNodeId`, `fileHandle`, `mode` |
| `FileClosed` | CloseFile Call succeeded |
| `FileBytesRead` | Read Call succeeded — `byteCount` |
| `FileBytesWritten` | Write Call succeeded — `byteCount` |

### Aggregates (1) — Part 13

| Class | When |
|---|---|
| `AggregateComputed` | Client-side aggregate finished — `function` (AggregateFunction enum), `inputCount`, `outputCount` |

### Discovery (1)

| Class | When |
|---|---|
| `DataTypesDiscovered` | `discoverDataTypes()` finished — `namespaceIndex`, `count` |

### Cache (2)

| Class | When |
|---|---|
| `CacheHit` | Read served from `read_metadata_cache` |
| `CacheMiss` | Cache miss, going to server |

### Retry (2)

| Class | When |
|---|---|
| `RetryAttempt` | A request is being retried — `attempt`, `delayMs` |
| `RetryExhausted` | Final attempt failed — `attempts`, `exception` |

### Trust store (5)

| Class | When |
|---|---|
| `ServerCertificateAutoAccepted` | TOFU accept on first contact |
| `ServerCertificateManuallyTrusted` | `trustCertificate()` call succeeded |
| `ServerCertificateRejected` | Server cert validation failed (untrusted, expired, hostname mismatch) |
| `ServerCertificateRemoved` | `untrustCertificate()` |
| `ServerCertificateTrusted` | Cert was already in the trust store — no-op |

## Listener registration

In `app/Providers/EventServiceProvider.php`:

```php
protected $listen = [
    \PhpOpcua\Client\Event\DataChangeReceived::class => [
        \App\Listeners\StoreSensorReading::class,
    ],
    \PhpOpcua\Client\Event\AlarmActivated::class => [
        \App\Listeners\NotifyAlarmRoom::class,
        \App\Listeners\BroadcastAlarmToDashboard::class,
    ],
];
```

Or via `Event::listen()`:

```php
use Illuminate\Support\Facades\Event;
use PhpOpcua\Client\Event\DataChangeReceived;

Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    Log::info('Tag changed', [
        'handle' => $e->clientHandle,
        'value' => $e->dataValue->getValue(),
        'sourceTimestamp' => $e->dataValue->sourceTimestamp,
    ]);
});
```

## Queued listeners (recommended for I/O-heavy work)

```php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreSensorReading implements ShouldQueue
{
    public string $queue = 'opcua-data';

    public function handle(DataChangeReceived $event): void
    {
        SensorReading::create([
            'client_handle' => $event->clientHandle,
            'value' => $event->dataValue->getValue(),
            'sampled_at' => $event->dataValue->sourceTimestamp,
            'quality' => $event->dataValue->statusCode,
        ]);
    }
}
```

The daemon publish loop dispatches synchronously. If your listener implements `ShouldQueue`, Laravel pushes a job; the publish loop returns immediately. Without `ShouldQueue`, slow listeners back-pressure the daemon — fine at low rates, problematic at >100 changes/sec.

## Resolving the client_handle back to a node

The `client_handle` is the integer you supplied at `createMonitoredItems` (or via `subscriptions` config). Best practice: keep a map in DB or a Laravel cache:

```php
// Seeder or boot()
Cache::forever('opcua:handles', [
    1 => 'ns=2;s=Temperature',
    2 => 'ns=2;s=Pressure',
    10 => 'i=2253', // ServerNode for events
]);

// Listener
$nodeId = Cache::get('opcua:handles')[$event->clientHandle];
```

For `event_monitored_items`, the `clientHandle` correlates with `EventNotificationReceived.clientHandle` — the same lookup applies.

## Listening across all subscriptions vs filtering

`DataChangeReceived` fires for every notification on every subscription. To filter:

```php
Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    if ($e->subscriptionId !== 42) {
        return;  // ignore other subscriptions
    }
    // ...
});
```

For Filament-only subscriptions, push events to a dedicated broadcast channel; see `INTEGRATIONS.md`.

## Stopping event propagation

Events are PSR-14 stoppable. Implement `StoppableEventInterface` in a custom subclass if you need to short-circuit later listeners — but the built-in DTOs are plain final readonly classes, so listener order in `EventServiceProvider` is the simpler control.

## Custom event classes

You can listen to a base class (e.g. `AlarmEventReceived`) to catch every alarm subtype:

```php
Event::listen(\PhpOpcua\Client\Event\AlarmEventReceived::class, function ($e) {
    // Fires for AlarmActivated, AlarmDeactivated, ...
});
```

All specific alarm events extend `AlarmEventReceived` (verify in `vendor/php-opcua/opcua-client/src/Event/`).

## Telescope and Pulse

Both work transparently — Telescope records `event` entries for each dispatch, Pulse rolls them up. See `INTEGRATIONS.md`.

## Memory considerations

Each event holds references to `DataValue` / `Variant` / strings. For >1000 events/sec sustained, prefer queued listeners and avoid keeping closures with captured event references in long-lived objects.
