# Integrations

How `laravel-opcua` plays with the rest of the Laravel ecosystem.

## Octane / FrankenPHP / Swoole / RoadRunner

`OpcuaManager` is registered as a singleton. In long-running workers, that singleton lives across requests — which is usually what you want, but only if the daemon is enabled. Without the daemon, the singleton holds a direct `Client` whose connection state is shared between requests, which can leak per-request session data.

Two safe patterns:

### Pattern A — daemon enabled (recommended)

The singleton holds a `ManagedClient`. IPC messages carry session context — no per-request state leaks. No special setup.

### Pattern B — no daemon, request-scoped client

Flush the singleton between requests:

```php
// app/Providers/OctaneServiceProvider.php
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;

public function boot(): void
{
    Event::listen(RequestTerminated::class, function () {
        if (! app()->bound(OpcuaManager::class)) return;
        app(OpcuaManager::class)->disconnectAll();
    });
}
```

For maximum safety (per-request client), bind `OpcuaManager` as scoped in your provider:

```php
$this->app->scoped(OpcuaManager::class, function ($app) { ... });
```

But this defeats much of Octane's benefit. Prefer the daemon.

## Horizon / queues

Listeners that implement `ShouldQueue` run on a Horizon worker. Two cautions:

1. **The worker is a separate PHP process.** It needs `OpcuaManager` available. The service provider handles this — no extra setup.
2. **Workers that talk to OPC UA must be on the same host as the daemon** (or have access to the IPC socket via NFS — not recommended) or use the TCP loopback endpoint.

Recommended Horizon supervisor config:

```php
// config/horizon.php
'supervisors' => [
    'opcua-data' => [
        'connection' => 'redis',
        'queue' => ['opcua-data'],
        'balance' => 'auto',
        'maxProcesses' => 4,
        'tries' => 3,
    ],
],
```

Use `$listener->queue = 'opcua-data'` to route OPC UA event handling to this dedicated supervisor.

## Livewire

`laravel-opcua` events + Livewire's `Event::listen()` pattern give you near-real-time dashboards.

```php
// app/Http/Livewire/PlantDashboard.php
use Livewire\Component;
use Livewire\Attributes\On;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class PlantDashboard extends Component
{
    public ?float $temperature = null;
    public ?float $pressure = null;

    public function mount(): void
    {
        $this->refreshReadings();
    }

    #[On('echo:plant,TagChanged')]
    public function tagChanged(array $event): void
    {
        match ($event['nodeId']) {
            'ns=2;s=Temperature' => $this->temperature = $event['value'],
            'ns=2;s=Pressure'    => $this->pressure = $event['value'],
            default              => null,
        };
    }

    public function refreshReadings(): void
    {
        $this->temperature = Opcua::read('ns=2;s=Temperature')->getValue();
        $this->pressure    = Opcua::read('ns=2;s=Pressure')->getValue();
    }

    public function render() { return view('livewire.plant-dashboard'); }
}
```

Hook the broadcast in an event listener:

```php
Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    broadcast(new TagChanged(
        nodeId: Cache::get('opcua:handles')[$e->clientHandle],
        value: $e->dataValue->getValue(),
    ));
});
```

Full recipe: `docs/recipes/livewire-realtime-dashboard.md`.

## Filament

The `OpcuaManager` is auto-resolved. Pages and widgets can inject it:

```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PhpOpcua\LaravelOpcua\OpcuaManager;

class PlantHealthWidget extends BaseWidget
{
    public function __construct(private OpcuaManager $opcua) {}

    protected function getStats(): array
    {
        $state = $this->opcua->read('i=2259')->getValue();
        return [
            Stat::make('Server state', match ($state) {
                0 => 'Running', 1 => 'Failed', default => "State $state",
            })->color($state === 0 ? 'success' : 'danger'),
        ];
    }
}
```

For browse-style admin (tree of nodes), use `Opcua::browseRecursive('i=85', maxDepth: 3)` and render via `TreeBuilder` actions. See `docs/integrations/filament.md`.

## Broadcasting

Two flavors:

### Channel per server

```php
class TagChanged implements ShouldBroadcast
{
    public function __construct(public string $nodeId, public mixed $value) {}
    public function broadcastOn(): array { return [new PrivateChannel('plant')]; }
}
```

### Per-handle (high-fan-out dashboards)

Broadcasts get expensive at >100 events/sec. Either rate-limit at the listener:

```php
class StoreSensorReading implements ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable;

    public function handle(DataChangeReceived $event): void
    {
        Cache::lock("dispatch:{$event->clientHandle}", 1)->get(function () use ($event) {
            broadcast(new TagChanged(...));
        });
    }
}
```

Or batch: accumulate in Redis, flush every 250ms via a scheduled job.

## Notifications

Common pattern: alarm-driven Slack / SMS / email:

```php
namespace App\Listeners;

use App\Notifications\AlarmActivatedNotification;
use Illuminate\Support\Facades\Notification;
use PhpOpcua\Client\Event\AlarmActivated;

class NotifyOnAlarm
{
    public function handle(AlarmActivated $event): void
    {
        $onCall = User::onCallNow()->get();
        Notification::send($onCall, new AlarmActivatedNotification($event));
    }
}
```

The notification class consumes `$event->severity`, `$event->message`, `$event->sourceName`. See `docs/integrations/notifications.md`.

## Telescope

`opcua:session` daemon dispatches events through Laravel's dispatcher → Telescope's `EventWatcher` picks them up. Each event entry shows in the Telescope UI under `Events`.

Use `TelescopeServiceProvider::hideRequestParameters` to redact `OPCUA_PASSWORD` from request entries (Telescope captures `$_ENV` for the request).

To hide low-value events (e.g. `CacheHit`, `CacheMiss`):

```php
// app/Providers/TelescopeServiceProvider.php
Telescope::filter(function (IncomingEntry $entry) {
    if ($entry->type === 'event') {
        $event = $entry->content['name'] ?? '';
        if (str_contains($event, 'CacheHit') || str_contains($event, 'CacheMiss')) {
            return false;
        }
    }
    return true;
});
```

## Pulse

The events show up in Pulse's `Slow Outgoing Requests` if you wrap OPC UA calls with `Pulse::record()`. Out-of-the-box, no integration — `OpcuaManager` calls are PHP-level, not HTTP.

For an opinionated Pulse card, see `docs/integrations/telescope-and-pulse.md`.

## Scheduler (`app/Console/Kernel.php`)

Common cron probes:

```php
$schedule->call(function () {
    $state = Opcua::read('i=2259')->getValue();
    if ($state !== 0) {
        Log::warning("OPC UA server state != Running: $state");
    }
})->everyMinute()->name('opcua-probe')->withoutOverlapping();
```

`withoutOverlapping()` prevents stacked probes if a server hang slows the read past 60s.

## Mocking the Facade in tests

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\DataValue;

Opcua::shouldReceive('read')
    ->with('i=2259')
    ->andReturn(DataValue::ofInt32(0));
```

For richer scenarios (callCount assertions, multiple sequential return values), see `TESTING.md`.

## Sail

`docker-compose.yml` extras for local dev with a UA-.NETStandard test server:

```yaml
services:
  opcua-server:
    image: ghcr.io/php-opcua/uanetstandard-test-suite:v1.5.0
    ports:
      - "4840:4840"   # plaintext
      - "4843:4843"   # SignAndEncrypt
      - "4848:4848"   # ECC NIST
      - "24842:24842" # historizing
    networks: [sail]
```

Connect from your Laravel container with `opc.tcp://opcua-server:4840`. See `docs/recipes/dev-with-sail.md`.

## Multi-tenant (per-tenant connection)

Use `connectTo()` with the tenant ID as the cache key:

```php
$client = Opcua::connectTo(
    "opc.tcp://{$tenant->plc_host}:4840",
    [
        'username' => $tenant->plc_user,
        'password' => decrypt($tenant->plc_pass_encrypted),
        'security_policy' => 'Basic256Sha256',
        'security_mode' => 'SignAndEncrypt',
    ],
    as: "tenant:{$tenant->id}",
);

$client->read('ns=2;s=Setpoint');
```

The `as:` key scopes the connection per tenant. Across requests under the daemon, sessions are reused. Without the daemon, you get a fresh TCP per request — fine for low-traffic tenants.

Full pattern: `docs/recipes/multi-plant-tenant.md`.
