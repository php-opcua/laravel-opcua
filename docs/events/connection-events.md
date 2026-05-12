---
eyebrow: 'Docs · Events'
lede:    'Connection lifecycle events — fired in both direct and managed mode. The real classes live in opcua-client, not under PhpOpcua\\LaravelOpcua\\Events.'

see_also:
  - { href: './overview.md',                     meta: '5 min' }
  - { href: '../using-the-client/connection-lifecycle.md', meta: '5 min' }
  - { href: '../observability/logging.md',       meta: '5 min' }

prev: { label: 'Events overview',  href: './overview.md' }
next: { label: 'Data events',      href: './data-events.md' }
---

# Connection events

The events here come from `opcua-client` — they live under
`PhpOpcua\Client\Event\` and reach your Laravel listeners through
the PSR-14 dispatcher Laravel binds for you (see
[Events overview](./overview.md) for the wiring rationale).

The fields shown below are the **real** fields from each class's
constructor — they are all `public readonly` promoted properties on
the event object.

## ClientConnected

Fires after session activation succeeds.

<!-- @code-block language="php" label="ClientConnected" -->
```php
namespace PhpOpcua\Client\Event;

final class ClientConnected
{
    public function __construct(
        public OpcUaClientInterface $client,
        public string $endpointUrl,
    ) {}
}
```
<!-- @endcode-block -->

There is **no `sessionId`, `managed`, or `serverProduct`** field on
the event. If you need the server product info, read it from the
client (`$event->client->getServerInfo()` if exposed by your version)
or from a cached value populated during your own connect path.

Listener example:

<!-- @code-block language="php" label="listener — log connections" -->
```php
use PhpOpcua\Client\Event\ClientConnected;

class LogOpcuaConnections
{
    public function handle(ClientConnected $event): void
    {
        Log::channel('plc')->info('Connected', [
            'endpoint' => $event->endpointUrl,
        ]);
    }
}
```
<!-- @endcode-block -->

## ClientDisconnected

Fires when disconnect completes — clean or broken. The event carries
only the client reference; there is **no `reason` field**.

<!-- @code-block language="php" label="ClientDisconnected" -->
```php
final class ClientDisconnected
{
    public function __construct(
        public OpcUaClientInterface $client,
    ) {}
}
```
<!-- @endcode-block -->

If you need to distinguish "the app called `disconnect()`" from "the
TCP socket died", correlate with the preceding `ConnectionFailed`
(if any) on the same `$client` instance.

## ConnectionFailed

Fires when `connect()` raised.

<!-- @code-block language="php" label="ConnectionFailed" -->
```php
final class ConnectionFailed
{
    public function __construct(
        public OpcUaClientInterface $client,
        public string $endpointUrl,
        public \Throwable $exception,
    ) {}
}
```
<!-- @endcode-block -->

Use the exception class for routing:

<!-- @code-block language="php" label="alert ops" -->
```php
use PhpOpcua\Client\Event\ConnectionFailed;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\SecurityException;

class AlertOpsTeam
{
    public function handle(ConnectionFailed $event): void
    {
        $payload = [
            'endpoint' => $event->endpointUrl,
            'type'     => $event->exception::class,
            'message'  => $event->exception->getMessage(),
        ];

        // Page only for transport / security failures
        if (! $event->exception instanceof ConnectionException
            && ! $event->exception instanceof SecurityException) {
            Log::channel('plc')->warning('OPC UA connect failed', $payload);
            return;
        }

        Notification::route('slack', config('alerts.ops_channel'))
            ->notify(new PlcConnectionLost(...$payload));
    }
}
```
<!-- @endcode-block -->

## ClientReconnecting

Fires when `reconnect()` starts. There is **no separate
`Reconnected` / `OpcuaReconnected` class** — when the reconnect
succeeds, the same `ClientConnected` event fires again on the same
client instance.

<!-- @code-block language="php" label="ClientReconnecting" -->
```php
final class ClientReconnecting
{
    public function __construct(
        public OpcUaClientInterface $client,
        public string $endpointUrl,
    ) {}
}
```
<!-- @endcode-block -->

To detect "PLC came back up after being down", you usually pair
`ConnectionFailed` (or `ClientDisconnected`) with the next
`ClientConnected` for the same `$client` instance.

## Patterns

### Audit log

<!-- @code-block language="php" label="audit table" -->
```php
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ConnectionFailed;

class WriteConnectionAudit
{
    public function handle(ClientConnected|ClientDisconnected|ConnectionFailed $event): void
    {
        ConnectionAudit::create([
            'endpoint'   => match (true) {
                $event instanceof ClientConnected, $event instanceof ConnectionFailed
                    => $event->endpointUrl,
                default => null,
            },
            'state'      => match (true) {
                $event instanceof ClientConnected    => 'connected',
                $event instanceof ClientDisconnected => 'disconnected',
                $event instanceof ConnectionFailed   => 'failed',
            },
            'detail'     => $event instanceof ConnectionFailed
                ? $event->exception->getMessage()
                : null,
            'logged_at'  => now(),
        ]);
    }
}
```
<!-- @endcode-block -->

Register against multiple events at once:

<!-- @code-block language="php" label="EventServiceProvider" -->
```php
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ClientReconnecting;
use PhpOpcua\Client\Event\ConnectionFailed;

protected $listen = [
    ClientConnected::class    => [WriteConnectionAudit::class],
    ClientDisconnected::class => [WriteConnectionAudit::class],
    ConnectionFailed::class   => [WriteConnectionAudit::class, AlertOpsTeam::class],
    ClientReconnecting::class => [WriteReconnectingMark::class],
];
```
<!-- @endcode-block -->

### Dashboard tile

A real-time tile showing which PLCs are up. Key the cache by the
endpoint URL (a stable identifier you control), not by something on
the client object:

<!-- @code-block language="php" label="status tracker" -->
```php
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ConnectionFailed;

class TrackConnectionState
{
    public function handle(ClientConnected|ClientDisconnected|ConnectionFailed $event): void
    {
        $endpoint = $event instanceof ClientDisconnected
            ? 'unknown'  // ClientDisconnected has no endpoint field
            : $event->endpointUrl;

        $state = match (true) {
            $event instanceof ClientConnected    => 'up',
            $event instanceof ClientDisconnected => 'down',
            $event instanceof ConnectionFailed   => 'failed',
        };

        Cache::put("opcua-state:{$endpoint}", [
            'state' => $state,
            'at'    => now()->toIso8601String(),
        ], minutes: 60);
    }
}
```
<!-- @endcode-block -->

### Reconnect counter

Spot flapping connections by counting `ClientReconnecting` events:

<!-- @code-block language="php" label="flap detector" -->
```php
use PhpOpcua\Client\Event\ClientReconnecting;

class DetectFlapping
{
    public function handle(ClientReconnecting $event): void
    {
        $key = "opcua-flap:{$event->endpointUrl}";
        $count = Cache::increment($key);

        if ($count === 1) {
            Cache::put($key, 1, minutes: 10);
            return;
        }

        if ($count >= 5) {
            Log::channel('plc')->warning("Flapping: {$event->endpointUrl}");
            Cache::forget($key);
        }
    }
}
```
<!-- @endcode-block -->

## Performance notes

Connection events fire **once per connection lifecycle event**, not
on every read/write. They are inherently low-volume. Queue them only
if listeners do expensive work (Slack notifications, external HTTP).
A log-channel listener can stay synchronous.

## Where to read next

- [Data events](./data-events.md) — high-frequency subscription events.
- [Alarm events](./alarm-events.md) — OPC UA alarm pipeline.
