---
eyebrow: 'Docs · Integrations'
lede:    'Pushing OPC UA value changes to the browser via Laravel Broadcasting. Reverb and Pusher setups, the listener-bridge pattern, and an end-to-end real-time tag widget.'

see_also:
  - { href: '../session-manager/auto-publish.md',           meta: '5 min' }
  - { href: '../events/data-events.md',                     meta: '6 min' }
  - { href: './livewire.md',                                meta: '7 min' }

prev: { label: 'Horizon & queues',   href: './horizon-and-queues.md' }
next: { label: 'Livewire',           href: './livewire.md' }
---

# Broadcasting

OPC UA produces a stream of value changes. Laravel Broadcasting
streams events to the browser. Wire them together and you get a
real-time UI driven by physical equipment.

This page documents the **pattern** for bridging OPC UA events to
Laravel broadcasting — the package does **not** ship any
broadcasting wiring out of the box. You register a tiny Laravel
listener that translates `PhpOpcua\Client\Event\DataChangeReceived`
into your own `ShouldBroadcast` event.

## Choose a driver

| Driver           | When                                                |
| ---------------- | --------------------------------------------------- |
| **Reverb**       | First-party, runs on your infra. Zero ops surface tax for most installs. The recommended default. |
| **Pusher**       | Hosted, paid. Good if you'd rather not run a service. |
| **Soketi**       | Self-hosted Pusher-compatible. Useful in air-gapped plants. |
| Log              | Dev only — write to log instead of broadcasting.    |

Most plant deployments use Reverb. The rest of this page assumes
Reverb; the patterns are identical for Pusher.

## Setting up Reverb

<!-- @code-block language="bash" label="terminal — install Reverb" -->
```bash
php artisan install:broadcasting --reverb
```
<!-- @endcode-block -->

That installs `laravel/reverb`, sets up
`config/broadcasting.php`, and adds JS scaffolding.

`.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=local
REVERB_APP_KEY=local
REVERB_APP_SECRET=local
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME=http
```
<!-- @endcode-block -->

Start Reverb in dev:

<!-- @code-block language="bash" label="terminal — reverb" -->
```bash
php artisan reverb:start
```
<!-- @endcode-block -->

In production, run under Supervisor with `--host=0.0.0.0`.

## The broadcast event

Create an event that implements `ShouldBroadcast`:

<!-- @code-block language="php" label="app/Events/TagUpdated.php" -->
```php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TagUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $nodeId,
        public readonly mixed $value,
        public readonly int $statusCode,
        public readonly ?string $sourceAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('plc.all'),
            new Channel("plc.tag.{$this->nodeId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'node_id'   => $this->nodeId,
            'value'     => $this->value,
            'status'    => $this->statusCode,
            'source_at' => $this->sourceAt,
            'good'      => $this->statusCode === 0,
        ];
    }
}
```
<!-- @endcode-block -->

`ShouldBroadcastNow` skips the queue — sub-100 ms end-to-end.
For very high volume, use `ShouldBroadcast` (queued) instead.

## The bridge listener

Listen to `DataChangeReceived`; emit your broadcast event. Keep
a `clientHandle => nodeId` map on the side because
`DataChangeReceived` only carries the handle, not the nodeId.

<!-- @code-block language="php" label="app/Listeners/BroadcastTagUpdate.php" -->
```php
namespace App\Listeners;

use App\Events\TagUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class BroadcastTagUpdate implements ShouldQueue
{
    public string $queue = 'broadcasts';

    /** @var array<int, string> handle => nodeId map populated when subscribing */
    private const HANDLE_TO_NODE = [
        1 => 'ns=2;s=Speed',
        2 => 'ns=2;s=Temperature',
    ];

    public function handle(DataChangeReceived $event): void
    {
        $nodeId = self::HANDLE_TO_NODE[$event->clientHandle] ?? (string) $event->clientHandle;

        broadcast(new TagUpdated(
            nodeId:     $nodeId,
            value:      $event->dataValue->getValue(),
            statusCode: $event->dataValue->statusCode,
            sourceAt:   $event->dataValue->sourceTimestamp?->format('c'),
        ));
    }
}
```
<!-- @endcode-block -->

Register:

<!-- @code-block language="php" label="EventServiceProvider" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

protected $listen = [
    DataChangeReceived::class => [
        BroadcastTagUpdate::class,
    ],
];
```
<!-- @endcode-block -->

## The browser side

Vite-managed JS:

<!-- @code-block language="text" label="resources/js/bootstrap.js" -->
```text
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```
<!-- @endcode-block -->

A tag widget (vanilla JS + Alpine):

<!-- @code-block language="text" label="resources/views/widgets/speed.blade.php" -->
```text
<div x-data="{ value: null, ok: false }"
     x-init="
        Echo.channel('plc.tag.ns=2;s=Speed')
            .listen('.App\\\\Events\\\\TagUpdated', (payload) => {
                value = payload.value;
                ok = payload.good;
            });
     "
     class="rounded-lg border p-4">
    <div class="text-sm text-gray-500">Speed</div>
    <div class="mt-1 text-3xl font-bold" :class="ok ? '' : 'text-red-600'">
        <span x-text="value !== null ? value.toFixed(2) : '—'"></span>
    </div>
</div>
```
<!-- @endcode-block -->

The widget updates in real time as `TagUpdated` events arrive on
`plc.tag.ns=2;s=Speed`.

## End-to-end flow

<!-- @code-block language="text" label="end-to-end" -->
```text
PLC                  Daemon             Laravel app           Reverb              Browser
 │                    │                     │                    │                   │
 │ value change       │                     │                    │                   │
 ├───────────────────►│                     │                    │                   │
 │                    │ DataChangeReceived  │                    │                   │
 │                    ├────────────────────►│                    │                   │
 │                    │                     │ broadcast()        │                   │
 │                    │                     ├───────────────────►│                   │
 │                    │                     │                    │  TagUpdated       │
 │                    │                     │                    ├──────────────────►│
 │                    │                     │                    │                   │ UI updates
```
<!-- @endcode-block -->

Typical latency: PLC → browser is 100-300 ms on a LAN with
managed-mode auto-publish.

## Private and presence channels

For operator-only data, use a private channel:

<!-- @code-block language="php" label="private channel" -->
```php
public function broadcastOn(): array
{
    return [
        new PrivateChannel('plc.operator.live'),
    ];
}
```
<!-- @endcode-block -->

Authorise in `routes/channels.php`:

<!-- @code-block language="php" label="routes/channels.php" -->
```php
Broadcast::channel('plc.operator.live', function (User $user) {
    return $user->hasRole('operator');
});
```
<!-- @endcode-block -->

Now only authenticated users with the `operator` role can
subscribe. Useful for separating public dashboards from operator
controls.

## Throttling — flooding the wire

A high-frequency tag (every 50 ms) is 1200 events/minute. The
browser doesn't need that. Throttle in the listener:

<!-- @code-block language="php" label="throttled broadcaster" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class BroadcastTagUpdate implements ShouldQueue
{
    public function handle(DataChangeReceived $event): void
    {
        $cacheKey = "broadcast-throttle:{$event->clientHandle}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, milliseconds: 250);

        broadcast(new TagUpdated(/* ... */));
    }
}
```
<!-- @endcode-block -->

4 broadcasts/sec maximum per tag. UI stays smooth, network
unchoked.

## Batching multi-tag updates

For a dashboard with 50 tags, fire one batch event rather than
50 individual ones:

<!-- @code-block language="php" label="batched broadcast" -->
```php
class TagBatchUpdated implements ShouldBroadcastNow
{
    public function __construct(public readonly array $updates) {}

    public function broadcastOn(): Channel
    {
        return new Channel('plc.dashboard');
    }

    public function broadcastWith(): array
    {
        return ['updates' => $this->updates];
    }
}

use PhpOpcua\Client\Event\DataChangeReceived;

class BatchBroadcaster implements ShouldQueue
{
    public function handle(DataChangeReceived $event): void
    {
        // Append to a Redis list and a scheduled drainer broadcasts every 250ms
        Redis::rpush('plc-batch', json_encode([
            'client_handle' => $event->clientHandle,
            'value'         => $event->dataValue->getValue(),
            'at'            => $event->dataValue->sourceTimestamp?->format('c'),
        ]));
    }
}

// Scheduled job to drain the buffer
$schedule->call(function () {
    $items = Redis::lrange('plc-batch', 0, -1);
    if (!empty($items)) {
        Redis::del('plc-batch');
        broadcast(new TagBatchUpdated(array_map('json_decode', $items)));
    }
})->everySecond();
```
<!-- @endcode-block -->

The browser receives one batch event per second containing all
tag changes — efficient for high-tag-count dashboards.

## Auth tokens and presence

For a presence channel (who's watching the dashboard):

<!-- @code-block language="php" label="presence channel" -->
```php
public function broadcastOn(): array
{
    return [new PresenceChannel('plc.operators-online')];
}

Broadcast::channel('plc.operators-online', function (User $user) {
    return $user->hasRole('operator')
        ? ['id' => $user->id, 'name' => $user->name]
        : null;
});
```
<!-- @endcode-block -->

Browser side:

<!-- @code-block language="text" label="presence client" -->
```text
Echo.join('plc.operators-online')
    .here((users) => { console.log('Operators online:', users); })
    .joining((user) => { console.log(`${user.name} joined`); })
    .leaving((user) => { console.log(`${user.name} left`); });
```
<!-- @endcode-block -->

## Production deployment

| Component         | Where                                |
| ----------------- | ------------------------------------ |
| Reverb            | Supervisor / systemd, port 8080      |
| Reverb workers    | `--workers=4` for medium load        |
| TLS proxy          | nginx in front of Reverb on 443      |
| Horizon for `broadcasts` queue (if not using `ShouldBroadcastNow`) | Separate supervisor   |

Reverb is single-process by default. For higher throughput, run
multiple Reverb instances behind a sticky load balancer — but
most plants are well under that threshold.

## Where to read next

- [Livewire](./livewire.md) — server-side reactive UI without
  hand-rolling JS.
- [Recipes · Livewire real-time dashboard](../recipes/livewire-realtime-dashboard.md) —
  the canonical end-to-end real-time UI.
