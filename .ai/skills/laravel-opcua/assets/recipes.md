# Recipes — copy-pasteable Laravel + OPC UA snippets

Each recipe is a complete end-to-end implementation.

## R1 — Read a single tag from a controller

```php
namespace App\Http\Controllers;

use PhpOpcua\LaravelOpcua\Facades\Opcua;

class ServerStateController
{
    public function __invoke()
    {
        $state = Opcua::read('i=2259')->getValue();
        return ['state' => $state, 'running' => $state === 0];
    }
}
```

## R2 — Read multiple tags in one request (fluent builder)

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

public function dashboard()
{
    $results = Opcua::readMulti()
        ->node('ns=2;s=Temperature')->value()
        ->node('ns=2;s=Pressure')->value()
        ->node('ns=2;s=FlowRate')->value()
        ->node('i=2259')->value()
        ->execute();

    return [
        'temperature' => $results[0]->getValue(),
        'pressure'    => $results[1]->getValue(),
        'flow_rate'   => $results[2]->getValue(),
        'state'       => $results[3]->getValue(),
    ];
}
```

One round-trip. Use this anywhere you'd otherwise do N separate `read()` calls.

## R3 — Write a setpoint with auto-detected type

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\StatusCode;

public function setSetpoint(Request $request)
{
    $value = $request->validated()['value'];
    $status = Opcua::write('ns=2;s=Setpoint', $value);

    abort_unless(StatusCode::isGood($status), 422,
        'Write failed: ' . StatusCode::getName($status));

    return response()->json(['ok' => true]);
}
```

`auto_detect_write_type` reads the node's metadata to pick the right `BuiltinType`. Pair with `read_metadata_cache: true` so it's a one-time round-trip per node.

## R4 — Connect to a runtime-discovered endpoint

```php
$client = Opcua::connectTo(
    $tenant->plc_endpoint,
    [
        'security_policy' => 'Basic256Sha256',
        'security_mode'   => 'SignAndEncrypt',
        'username'        => $tenant->plc_user,
        'password'        => decrypt($tenant->plc_pass_encrypted),
    ],
    as: "tenant:{$tenant->id}",
);

$client->read('ns=2;s=Setpoint');
```

The `as:` parameter caches the client; reuse it within the request without rebuilding the connection.

## R5 — Browse the Objects folder recursively

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

public function addressSpace()
{
    $tree = Opcua::browseRecursive('i=85', maxDepth: 3);

    return collect($tree)->map(fn($node) => [
        'browseName' => $node->reference->browseName,
        'nodeId'     => (string) $node->reference->nodeId,
        'children'   => $this->flatten($node->children),
    ]);
}
```

Use `maxDepth` aggressively — recursive browse on a busy server can produce thousands of references.

## R6 — Auto-publish subscription → DB persistence

```php
// config/opcua.php
'session_manager' => ['auto_publish' => true],
'connections' => [
    'plc-1' => [
        'endpoint' => env('PLC1_ENDPOINT'),
        'security_policy' => 'Basic256Sha256',
        'security_mode' => 'SignAndEncrypt',
        'username' => env('PLC1_USER'),
        'password' => env('PLC1_PASS'),
        'auto_connect' => true,
        'subscriptions' => [[
            'publishing_interval' => 500.0,
            'monitored_items' => [
                ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1, 'sampling_interval' => 250.0],
                ['node_id' => 'ns=2;s=Pressure',    'client_handle' => 2, 'sampling_interval' => 250.0],
            ],
        ]],
    ],
],
```

```php
// app/Listeners/StoreReading.php
namespace App\Listeners;

use App\Models\SensorReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreReading implements ShouldQueue
{
    public string $queue = 'opcua-data';

    public function handle(DataChangeReceived $event): void
    {
        $nodeId = match ($event->clientHandle) {
            1 => 'ns=2;s=Temperature',
            2 => 'ns=2;s=Pressure',
            default => null,
        };
        if ($nodeId === null) return;

        SensorReading::create([
            'node_id'    => $nodeId,
            'value'      => $event->dataValue->getValue(),
            'quality'    => $event->dataValue->statusCode,
            'sampled_at' => $event->dataValue->sourceTimestamp,
        ]);
    }
}
```

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \PhpOpcua\Client\Event\DataChangeReceived::class => [
        \App\Listeners\StoreReading::class,
    ],
];
```

Run `php artisan opcua:session` under Supervisor + a Horizon worker on the `opcua-data` queue.

## R7 — Broadcast tag changes to a Livewire dashboard

```php
// app/Events/TagChanged.php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TagChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $nodeId, public mixed $value) {}
    public function broadcastOn(): array { return [new PrivateChannel('plant')]; }
    public function broadcastAs(): string { return 'TagChanged'; }
}
```

```php
// app/Listeners/BroadcastTagChange.php
use PhpOpcua\Client\Event\DataChangeReceived;
use App\Events\TagChanged;

class BroadcastTagChange
{
    public function handle(DataChangeReceived $e): void
    {
        $nodeId = config("opcua.handles.{$e->clientHandle}");
        broadcast(new TagChanged($nodeId, $e->dataValue->getValue()));
    }
}
```

```php
// resources/views/livewire/plant-dashboard.blade.php
<div wire:poll.5s>
    Temp: {{ $temperature }}
    Pressure: {{ $pressure }}
</div>

@script
<script>
    Echo.private('plant').listen('.TagChanged', (e) => {
        $wire.tagChanged(e);
    });
</script>
@endscript
```

## R8 — Cron probe with `withoutOverlapping`

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        try {
            $state = Opcua::read('i=2259')->getValue();
            if ($state !== 0) {
                Log::warning("OPC UA server state != Running: $state");
            }
        } catch (\Throwable $e) {
            Log::error('OPC UA probe failed', ['exception' => $e]);
        }
    })
    ->everyMinute()
    ->name('opcua-probe')
    ->withoutOverlapping();
}
```

## R9 — Filament stats widget

```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PhpOpcua\LaravelOpcua\OpcuaManager;

class PlantHealth extends BaseWidget
{
    public function __construct(private OpcuaManager $opcua) {}

    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $temp = cache()->get('plant:temperature') ?? $this->opcua->read('ns=2;s=Temperature')->getValue();
        $state = $this->opcua->read('i=2259')->getValue();

        return [
            Stat::make('Temperature', sprintf('%.1f °C', $temp))
                ->color($temp > 90 ? 'danger' : 'success'),
            Stat::make('State', match ($state) {
                0 => 'Running', 1 => 'Failed', default => "Code $state",
            })->color($state === 0 ? 'success' : 'danger'),
        ];
    }
}
```

## R10 — Multi-tenant per-PLC connection

```php
namespace App\Http\Controllers;

use App\Models\Tenant;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class TenantPlantController
{
    public function show(Tenant $tenant)
    {
        $client = Opcua::connectTo(
            $tenant->plc_endpoint,
            [
                'security_policy' => 'Basic256Sha256',
                'security_mode'   => 'SignAndEncrypt',
                'username'        => $tenant->plc_user,
                'password'        => decrypt($tenant->plc_pass_encrypted),
                'trust_store_path' => storage_path("app/tenants/{$tenant->id}/trust"),
            ],
            as: "tenant:{$tenant->id}",
        );

        return [
            'state' => $client->read('i=2259')->getValue(),
        ];
    }
}
```

## R11 — History read with date range

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use Carbon\CarbonImmutable;

public function history(Request $request, string $nodeId)
{
    $start = CarbonImmutable::parse($request->query('from', '-1 hour'))->toDateTimeImmutable();
    $end   = CarbonImmutable::parse($request->query('to', 'now'))->toDateTimeImmutable();

    $values = Opcua::connection('historian')->historyReadRaw($nodeId, $start, $end);

    return collect($values)->map(fn($dv) => [
        't' => $dv->sourceTimestamp?->format(\DateTimeInterface::ATOM),
        'v' => $dv->getValue(),
        'q' => $dv->statusCode,
    ]);
}
```

## R12 — Method call with input arguments

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

public function startMotor(int $rpm)
{
    $result = Opcua::call(
        objectId: 'ns=2;s=MotorController',
        methodId: 'ns=2;s=MotorController.Start',
        inputArguments: [
            new Variant(BuiltinType::Int32, $rpm),
        ],
    );

    if (! StatusCode::isGood($result->statusCode)) {
        throw new \RuntimeException("Start failed: " . StatusCode::getName($result->statusCode));
    }

    return $result->outputArguments;  // method-specific
}
```

## R13 — HistoryUpdate (insert backfill data) — v4.4

```php
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;
use DateTimeImmutable;

$values = collect($readings)->map(fn($r) => new DataValue(
    value: new Variant(BuiltinType::Double, $r['value']),
    statusCode: 0,
    sourceTimestamp: new DateTimeImmutable($r['sampled_at']),
))->all();

$statuses = Opcua::connection('historian')->historyInsertData('ns=2;s=Backfill', $values);
// $statuses[i] = per-value status code
```

## R14 — File transfer (download a file from a UA FileType node) — v4.4

```php
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;

$fileNode = 'ns=2;s=Reports/PdfReport';
$handle   = Opcua::openFile($fileNode, OpenFileMode::Read);

$buffer = '';
$chunk  = 64 * 1024;
while (true) {
    $bytes = Opcua::readFile($fileNode, $handle, $chunk);
    if ($bytes === '') break;
    $buffer .= $bytes;
}
Opcua::closeFile($fileNode, $handle);

return response($buffer, 200, ['Content-Type' => 'application/pdf']);
```

## R15 — TOFU bootstrap then strict trust

```bash
# Step 1 — bootstrap: dev .env
OPCUA_AUTO_ACCEPT=true
OPCUA_TRUST_STORE_PATH=/var/www/storage/app/opcua-trust-store
```

```bash
# Step 2 — first connection auto-trusts
php artisan tinker --execute='Opcua::read("i=2259");'

# Step 3 — verify the cert was added
ls /var/www/storage/app/opcua-trust-store/trusted/
```

```bash
# Step 4 — flip to strict in production .env
OPCUA_AUTO_ACCEPT=false
OPCUA_TRUST_POLICY=fingerprint
```

Any cert change after this point throws `UntrustedCertificateException`.

## R16 — Pest unit test with MockClient

```php
use PhpOpcua\Client\MockClient;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\DataValue;

it('reads the server state from the controller', function () {
    $mock = MockClient::create()
        ->onRead('i=2259', fn() => DataValue::ofInt32(0));

    $this->app->instance(OpcUaClientInterface::class, $mock);

    $this->get('/server-state')
        ->assertOk()
        ->assertJson(['state' => 0, 'running' => true]);
});
```

## R17 — Pulse panel of OPC UA event rate

```php
// app/Providers/PulseServiceProvider.php
use Laravel\Pulse\Facades\Pulse;
use PhpOpcua\Client\Event\DataChangeReceived;

Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    Pulse::record(
        type: 'opcua_data_change',
        key: (string) $e->clientHandle,
        value: 1,
    )->count();
});
```

```php
// resources/views/vendor/pulse/dashboard.blade.php
<x-pulse:card>
    <x-pulse:card-header title="OPC UA data changes (last minute)" />
    {{ Pulse::values('opcua_data_change', ['handle-1', 'handle-2'])->count() }}
</x-pulse:card>
```

## R18 — Octane reset hook

```php
// app/Providers/OctaneServiceProvider.php
namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use PhpOpcua\LaravelOpcua\OpcuaManager;

class OctaneServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->bound('octane')) return;

        Event::listen(RequestTerminated::class, function () {
            if ($this->app->resolved(OpcuaManager::class)) {
                $this->app->make(OpcuaManager::class)->disconnectAll();
            }
        });
    }
}
```

## R19 — Notification on alarm

```php
use PhpOpcua\Client\Event\AlarmActivated;
use Illuminate\Support\Facades\Notification;

Event::listen(AlarmActivated::class, function (AlarmActivated $e) {
    $oncall = \App\Models\User::query()->onCallNow()->get();
    Notification::send($oncall, new \App\Notifications\PlantAlarmActivated(
        severity: $e->severity,
        message: (string) $e->message,
        sourceName: $e->sourceName,
        time: $e->time,
    ));
});
```

## R20 — Healthcheck endpoint

```php
// routes/web.php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

Route::get('/healthz', function () {
    try {
        $state = Opcua::read('i=2259', refresh: true)->getValue();
        return response()->json([
            'opcua' => $state === 0 ? 'ok' : "state=$state",
            'session_manager' => Opcua::isSessionManagerRunning() ? 'up' : 'down',
        ], $state === 0 ? 200 : 503);
    } catch (\Throwable $e) {
        return response()->json([
            'opcua' => 'error',
            'error' => class_basename($e),
        ], 503);
    }
});
```

Wire this into your container orchestrator's liveness probe (k8s, Nomad, ECS).
