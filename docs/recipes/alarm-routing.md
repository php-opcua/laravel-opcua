---
eyebrow: 'Docs · Recipes'
lede:    'End-to-end alarm pipeline: subscription, persistence, severity-based routing, acknowledgement endpoint, and audit chain. The Laravel-native shape most plants converge on.'

see_also:
  - { href: '../events/alarm-events.md',                  meta: '5 min' }
  - { href: '../integrations/notifications.md',           meta: '6 min' }
  - { href: '../operations/method-calls.md',              meta: '6 min' }

prev: { label: 'Persistent tag history',  href: './persistent-tag-history.md' }
next: { label: 'Livewire real-time dashboard', href: './livewire-realtime-dashboard.md' }
---

# Alarm routing

A complete alarm pipeline. From the OPC UA subscription that
discovers events, through persistence and routing, to the
operator UI's acknowledgement.

## Architecture

<!-- @code-block language="text" label="alarm pipeline" -->
```text
OPC UA server     Daemon (auto-publish)      Laravel listeners on
─────────────────  ─────────────────────────  ─────────────────────────
EventNotifier ──►  Dispatches the real        PhpOpcua\Client\Event\
                   PhpOpcua\Client\Event\     EventNotificationReceived
                   * classes (incl. the       AlarmActivated
                   AlarmActivated /           LimitAlarmExceeded
                   LimitAlarmExceeded /       │
                   AlarmAcknowledged          ├──► PersistAlarm  (DB)
                   variants when payload      ├──► RouteAlarm    (Notification)
                   matches an alarm shape)    └──► BroadcastAlarm (UI)

[Operator clicks Ack in UI]
                                              │
                                              ▼
                                              AcknowledgeAlarmService
                                              │
                                              ▼
                                              Opcua::call(ConditionType, Acknowledge, [...])
                                              │
                                              ▼ (server emits)
                                              AlarmAcknowledged + EventNotificationReceived
                                              │
                                              ├──► PersistAlarm  (update is_acked)
                                              └──► BroadcastAlarm
```
<!-- @endcode-block -->

## Migrations

<!-- @code-block language="php" label="alarms table" -->
```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plc_alarms', function (Blueprint $table) {
            $table->id();
            $table->string('connection', 64);
            $table->string('event_id', 64)->index();
            $table->string('event_type', 256)->nullable();
            $table->string('source_node_id')->nullable();
            $table->string('source_name')->nullable();
            $table->integer('severity')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('occurred_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_acked')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'is_acked', 'severity']);
        });

        Schema::create('plc_alarm_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plc_alarm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('comment')->nullable();
            $table->timestamp('acked_at');
        });
    }
};
```
<!-- @endcode-block -->

## Models

<!-- @code-block language="php" label="PlcAlarm model" -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\{Model, Relations\HasMany};

class PlcAlarm extends Model
{
    protected $guarded = [];
    protected $casts = [
        'occurred_at' => 'datetime',
        'is_active'   => 'boolean',
        'is_acked'    => 'boolean',
        'severity'    => 'integer',
    ];

    public function acks(): HasMany
    {
        return $this->hasMany(PlcAlarmAck::class);
    }

    public function scopeActive($q)    { return $q->where('is_active', true); }
    public function scopeUnacked($q)   { return $q->where('is_acked', false); }
    public function scopeCritical($q)  { return $q->where('severity', '>=', 800); }
}

class PlcAlarmAck extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['acked_at' => 'datetime'];
}
```
<!-- @endcode-block -->

## The subscription

A scheduled / supervisor-managed command sets up the subscription
at boot:

<!-- @code-block language="php" label="setup command" -->
```php
class SubscribeToAlarms extends Command
{
    protected $signature = 'opcua:subscribe-alarms';

    public function handle(\PhpOpcua\LaravelOpcua\OpcuaManager $opcua): int
    {
        $client = $opcua->connection();
        $sub = $client->createSubscription(publishingInterval: 1000.0);

        $client->createEventMonitoredItem(
            subscriptionId: $sub->subscriptionId,
            nodeId:         'ns=0;i=2253',   // Server node
            selectFields:   [
                'EventId', 'EventType', 'SourceNode', 'SourceName',
                'Time', 'Message', 'Severity',
                'ActiveState/Id', 'AckedState/Id',
            ],
            clientHandle:   100,
        );

        $this->info('Subscribed to alarms.');

        // In managed mode, just return — the daemon holds the sub
        return self::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Run once after deploy, or auto-run from a systemd `ExecStartPost`.

## Listeners

### Persist

<!-- @code-block language="php" label="PersistAlarm listener" -->
```php
namespace App\Listeners;

use App\Models\PlcAlarm;
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\EventNotificationReceived;

class PersistAlarm implements ShouldQueue
{
    public string $queue = 'opcua-alarms';

    public function handle(EventNotificationReceived $event): void
    {
        $f = $event->eventFields;
        $eventId = isset($f['EventId']) ? bin2hex($f['EventId']) : null;
        if ($eventId === null) {
            return;
        }

        PlcAlarm::updateOrCreate(
            ['event_id' => $eventId],
            [
                'event_type'      => (string) ($f['EventType'] ?? ''),
                'source_node_id'  => isset($f['SourceNode']) ? (string) $f['SourceNode'] : null,
                'source_name'     => $f['SourceName'] ?? null,
                'severity'        => $f['Severity'] ?? null,
                'message'         => $f['Message'] ?? null,
                'occurred_at'     => $f['Time'] ?? null,
                'is_active'       => (bool) ($f['ActiveState/Id'] ?? $f['ActiveState'] ?? true),
                'is_acked'        => (bool) ($f['AckedState/Id']  ?? $f['AckedState']  ?? false),
            ],
        );
    }
}
```
<!-- @endcode-block -->

`updateOrCreate` handles both "new alarm" and "alarm
state-change" (active → inactive, unacked → acked).

### Route

<!-- @code-block language="php" label="RouteAlarmNotification listener" -->
```php
namespace App\Listeners;

use App\Notifications\PlcAlarmRaised;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PhpOpcua\Client\Event\AlarmActivated;

class RouteAlarmNotification implements ShouldQueue
{
    public string $queue = 'opcua-alarms';

    public function handle(AlarmActivated $event): void
    {
        if (($event->severity ?? 0) < 400) {
            return;     // info / warning level — DB only, no routing
        }

        $notif = new PlcAlarmRaised(
            source:   $event->sourceName ?? 'unknown',
            severity: $event->severity,
            message:  $event->message,
        );

        (new AnonymousNotifiable())
            ->route('slack', config('alarms.recipients.slack_channel'))
            ->route('mail',  config('alarms.recipients.broadcast_email'))
            ->notify($notif);
    }
}
```
<!-- @endcode-block -->

`PlcAlarmRaised` is the Notification class — see
[Integrations · Notifications](../integrations/notifications.md).

### Broadcast

<!-- @code-block language="php" label="BroadcastAlarm listener" -->
```php
namespace App\Listeners;

use App\Events\AlarmBroadcasted;

class BroadcastAlarm
{
    public function handle(\PhpOpcua\Client\Event\EventNotificationReceived $event): void
    {
        $f = $event->eventFields;
        $eventId = isset($f['EventId']) ? bin2hex($f['EventId']) : null;
        if ($eventId === null) return;

        broadcast(new AlarmBroadcasted(
            eventId:  $eventId,
            source:   $f['SourceName'] ?? null,
            severity: $f['Severity'] ?? 0,
            message:  $f['Message'] ?? '',
            isActive: (bool) ($f['ActiveState/Id'] ?? $f['ActiveState'] ?? true),
            isAcked:  (bool) ($f['AckedState/Id']  ?? $f['AckedState']  ?? false),
        ));
    }
}
```
<!-- @endcode-block -->

```php
class AlarmBroadcasted implements \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow
{
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $source,
        public readonly int $severity,
        public readonly string $message,
        public readonly bool $isActive,
        public readonly bool $isAcked,
    ) {}

    public function broadcastOn(): \Illuminate\Broadcasting\Channel
    {
        return new \Illuminate\Broadcasting\PrivateChannel('plc.alarms');
    }
}
```

### Register all three

<!-- @code-block language="php" label="EventServiceProvider" -->
```php
use PhpOpcua\Client\Event\EventNotificationReceived;
use PhpOpcua\Client\Event\AlarmActivated;

protected $listen = [
    EventNotificationReceived::class => [
        PersistAlarm::class,
        BroadcastAlarm::class,
    ],
    AlarmActivated::class => [
        RouteAlarmNotification::class,
    ],
];
```
<!-- @endcode-block -->

## The acknowledge endpoint

<!-- @code-block language="php" label="AcknowledgeAlarmController" -->
```php
namespace App\Http\Controllers;

use App\Models\{PlcAlarm, PlcAlarmAck};
use Illuminate\Http\{Request, JsonResponse};
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\LaravelOpcua\OpcuaManager;

class AcknowledgeAlarmController
{
    public function ack(
        Request $request,
        OpcuaManager $opcua,
        string $eventId,
    ): JsonResponse {
        $request->validate(['comment' => 'nullable|string|max:255']);

        $alarm = PlcAlarm::where('event_id', $eventId)->firstOrFail();
        $this->authorize('ack', $alarm);

        // Call the OPC UA Acknowledge method on the ConditionType node
        $result = $opcua->connection($alarm->connection)->call(
            objectId:        'ns=0;i=2782',
            methodId:        'ns=0;i=9111',
            inputArguments:  [
                new \PhpOpcua\Client\Types\Variant(hex2bin($eventId), BuiltinType::ByteString),
                ['locale' => 'en', 'text' => $request->input('comment') ?? ''],
            ],
        );

        if (! \PhpOpcua\Client\Types\StatusCode::isGood($result->statusCode)) {
            return response()->json([
                'error'  => 'ack-failed',
                'status' => \PhpOpcua\Client\Types\StatusCode::getName($result->statusCode),
            ], 422);
        }

        // Record the ack locally — the server-emitted update arrives via
        // EventNotificationReceived (AckedState=true) and lands in
        // PlcAlarm via PersistAlarm. We separately record who acked it.
        PlcAlarmAck::create([
            'plc_alarm_id' => $alarm->id,
            'user_id'      => $request->user()->id,
            'comment'      => $request->input('comment'),
            'acked_at'     => now(),
        ]);

        return response()->json(['status' => 'acked']);
    }
}
```
<!-- @endcode-block -->

Route:

<!-- @code-block language="php" label="routes/api.php" -->
```php
Route::middleware(['auth:sanctum'])
    ->post('/alarms/{eventId}/ack', [AcknowledgeAlarmController::class, 'ack']);
```
<!-- @endcode-block -->

## Policy

<!-- @code-block language="php" label="PlcAlarmPolicy" -->
```php
namespace App\Policies;

use App\Models\{PlcAlarm, User};

class PlcAlarmPolicy
{
    public function ack(User $user, PlcAlarm $alarm): bool
    {
        if (! $user->hasRole('operator')) return false;

        // Per-line scoping based on the source node
        return $user->canAccessLine($alarm->source_name);
    }
}
```
<!-- @endcode-block -->

Register in `AuthServiceProvider`.

## Operator UI

The alarms list (Filament or plain Livewire):

<!-- @code-block language="php" label="Livewire alarms list" -->
```php
class AlarmsList extends Component
{
    public function mount(): void
    {
        $this->listen('echo:plc.alarms,App\\Events\\AlarmBroadcasted', 'refresh');
    }

    public function render()
    {
        $alarms = PlcAlarm::active()->unacked()->orderByDesc('severity')
            ->orderByDesc('occurred_at')->limit(50)->get();

        return view('livewire.alarms-list', compact('alarms'));
    }

    public function ack(int $alarmId, string $comment = ''): void
    {
        $alarm = PlcAlarm::findOrFail($alarmId);
        $this->authorize('ack', $alarm);

        Http::withToken(auth()->user()->createToken('alarms')->plainTextToken)
            ->post(route('alarms.ack', $alarm->event_id), ['comment' => $comment]);
    }
}
```
<!-- @endcode-block -->

The UI refreshes on every broadcast — operator sees alarms
in real time and ack-able immediately.

## Severity routing config

<!-- @code-block language="php" label="config/alarms.php" -->
```php
return [
    'recipients' => [
        'slack_channel'   => env('ALARM_SLACK_CHANNEL'),
        'broadcast_email' => env('ALARM_EMAIL'),
    ],
    'thresholds' => [
        'route' => 400,    // below this, DB-only
        'slack' => 700,
        'page'  => 900,    // SMS/phone alert
    ],
];
```
<!-- @endcode-block -->

## Where to read next

- [Livewire real-time dashboard](./livewire-realtime-dashboard.md) —
  combining alarms with tag values.
- [Recipes · Production deployment](./production-deployment.md) —
  shipping this pipeline.
