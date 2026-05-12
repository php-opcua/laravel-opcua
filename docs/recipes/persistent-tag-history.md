---
eyebrow: 'Docs · Recipes'
lede:    'A complete persistent tag-history pipeline: Eloquent table, subscription listener, batched inserts, retention policies, and a query API. Drop-in for any plant where the PLC isn''t a historian.'

see_also:
  - { href: '../operations/subscriptions.md',            meta: '7 min' }
  - { href: '../events/data-events.md',                  meta: '6 min' }
  - { href: '../events/queued-listeners.md',             meta: '5 min' }

prev: { label: 'Exceptions',                href: '../reference/exceptions.md' }
next: { label: 'Alarm routing',             href: './alarm-routing.md' }
---

# Persistent tag history

When the OPC UA server isn't a historian — or when you want
short-term history in Laravel for fast queries — this is the
canonical pattern.

## What it gives you

- Every tag change persisted in a `plc_readings` table.
- Batched inserts so throughput scales.
- Auto-purging old rows per retention policy.
- A query API (`PlcReading::for($node)->between(...)`).
- ~50 lines of Laravel code on top of the package.

## Migration

<!-- @code-block language="php" label="database/migrations/...plc_readings.php" -->
```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plc_readings', function (Blueprint $table) {
            $table->id();
            $table->string('connection', 64);
            $table->string('node_id');
            $table->decimal('value_numeric', 20, 6)->nullable();
            $table->string('value_text')->nullable();
            $table->integer('status_code');
            $table->timestamp('source_at', 6);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['node_id', 'source_at']);
            $table->index(['connection', 'source_at']);
        });
    }
};
```
<!-- @endcode-block -->

Two value columns — numeric and text — let you store any
`BuiltinType` without losing precision. Index on
`(node_id, source_at)` is the dominant query pattern.

## The model

<!-- @code-block language="php" label="app/Models/PlcReading.php" -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlcReading extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'source_at'    => 'datetime',
        'created_at'   => 'datetime',
        'value_numeric' => 'decimal:6',
        'status_code'   => 'integer',
    ];

    public function scopeFor($query, string $nodeId)
    {
        return $query->where('node_id', $nodeId);
    }

    public function scopeBetween($query, \DateTimeInterface $from, \DateTimeInterface $to)
    {
        return $query->whereBetween('source_at', [$from, $to]);
    }

    public function scopeGood($query)
    {
        return $query->where('status_code', 0);
    }

    public function getValueAttribute(): mixed
    {
        return $this->value_numeric ?? $this->value_text;
    }
}
```
<!-- @endcode-block -->

Query examples:

<!-- @code-block language="php" label="query patterns" -->
```php
PlcReading::for('ns=2;s=Speed')->between(now()->subHour(), now())->get();
PlcReading::for('ns=2;s=Speed')->good()->latest('source_at')->limit(1)->first();
PlcReading::where('connection', 'plc-line-a')->between($start, $end)->count();
```
<!-- @endcode-block -->

## The listener (batched)

A naive listener does one insert per event. For a high-frequency
subscription (100+ events/sec), that's a bottleneck. Instead,
buffer and batch:

<!-- @code-block language="php" label="app/Listeners/BufferReading.php" -->
```php
namespace App\Listeners;

use Illuminate\Support\Facades\Redis;
use PhpOpcua\Client\Event\DataChangeReceived;

class BufferReading
{
    public function handle(DataChangeReceived $event): void
    {
        Redis::rpush('plc-readings-buffer', json_encode([
            'client_handle' => $event->clientHandle,
            'value'         => $event->dataValue->getValue(),
            'status_code'   => $event->dataValue->statusCode,
            'source_at'     => ($event->dataValue->sourceTimestamp ?? now())
                                 ->format('Y-m-d H:i:s.u'),
        ]));
    }
}
```
<!-- @endcode-block -->

Lightweight, synchronous, no queue dispatch overhead.

## The drain job

<!-- @code-block language="php" label="app/Jobs/DrainReadingsBuffer.php" -->
```php
namespace App\Jobs;

use App\Models\PlcReading;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class DrainReadingsBuffer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'opcua-data';
    public int $timeout = 60;

    public function handle(): void
    {
        $batch = [];
        $limit = 500;

        for ($i = 0; $i < $limit; $i++) {
            $row = Redis::lpop('plc-readings-buffer');
            if ($row === null) break;

            $data = json_decode($row, true);
            $isNumeric = is_numeric($data['value']);

            $batch[] = [
                'client_handle' => $data['client_handle'],
                'value_numeric' => $isNumeric ? $data['value'] : null,
                'value_text'    => $isNumeric ? null           : (string) $data['value'],
                'status_code'   => $data['status_code'],
                'source_at'     => $data['source_at'],
                'created_at'    => now(),
            ];
        }

        if (!empty($batch)) {
            PlcReading::insert($batch);
        }
    }
}
```
<!-- @endcode-block -->

`PlcReading::insert($batch)` is a single SQL `INSERT` with all
rows — fast and atomic.

## Register listener + schedule drain

<!-- @code-block language="php" label="EventServiceProvider" -->
```php
use App\Listeners\BufferReading;
use PhpOpcua\Client\Event\DataChangeReceived;

protected $listen = [
    DataChangeReceived::class => [BufferReading::class],
];
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="app/Console/Kernel.php" -->
```php
use App\Jobs\DrainReadingsBuffer;

protected function schedule(Schedule $schedule): void
{
    $schedule->call(fn () => DrainReadingsBuffer::dispatch())
        ->everyFiveSeconds()
        ->name('drain-plc-buffer')
        ->onOneServer()
        ->withoutOverlapping();
}
```
<!-- @endcode-block -->

`withoutOverlapping()` and `onOneServer()` keep the drain
single-threaded — important because Redis `LPOP` is non-atomic
across the whole batch.

## Throughput characteristics

| Subscription rate    | Per-batch size (500 cap) | Drain interval | Sustained?  |
| -------------------- | ------------------------- | --------------- | ----------- |
| 100 events / sec     | ~500                      | 5 s             | Yes         |
| 500 events / sec     | 500 (full)                | 5 s            | Tight (5s × 500 = 2500/event budget) |
| 1000 events / sec    | 500 (full each time)      | 5 s            | No — change to 1s drain   |

Tune the cap and the schedule period to your event rate.

## Retention

A row per reading grows fast. A scheduled job purges old data:

<!-- @code-block language="php" label="app/Jobs/PrunePlcReadings.php" -->
```php
class PrunePlcReadings implements ShouldQueue
{
    public string $queue = 'opcua-data';

    public function handle(): void
    {
        // Keep 7 days for free, 30 days for aggregated, drop the rest
        $cutoff = now()->subDays(7);

        do {
            $deleted = PlcReading::where('source_at', '<', $cutoff)
                ->limit(10_000)
                ->delete();
            sleep(1);   // give the DB a beat
        } while ($deleted > 0);
    }
}
```
<!-- @endcode-block -->

Schedule daily:

<!-- @code-block language="php" label="schedule" -->
```php
$schedule->job(new PrunePlcReadings)
    ->dailyAt('02:00')
    ->onOneServer();
```
<!-- @endcode-block -->

Chunked delete avoids long table locks.

## Aggregation

For longer retention without exploding storage, aggregate to
1-minute buckets:

<!-- @code-block language="php" label="migration — aggregates" -->
```php
Schema::create('plc_reading_aggregates_1m', function (Blueprint $table) {
    $table->id();
    $table->string('node_id');
    $table->timestamp('bucket_at');           // start of the minute
    $table->decimal('avg_value', 20, 6);
    $table->decimal('min_value', 20, 6);
    $table->decimal('max_value', 20, 6);
    $table->integer('sample_count');

    $table->unique(['node_id', 'bucket_at']);
});
```
<!-- @endcode-block -->

Aggregation job:

<!-- @code-block language="php" label="aggregate job" -->
```php
class AggregatePlcReadings implements ShouldQueue
{
    public string $queue = 'opcua-data';

    public function handle(): void
    {
        \DB::statement("
            INSERT INTO plc_reading_aggregates_1m
                (node_id, bucket_at, avg_value, min_value, max_value, sample_count, created_at, updated_at)
            SELECT node_id,
                   DATE_FORMAT(source_at, '%Y-%m-%d %H:%i:00') AS bucket_at,
                   AVG(value_numeric),
                   MIN(value_numeric),
                   MAX(value_numeric),
                   COUNT(*),
                   NOW(), NOW()
            FROM plc_readings
            WHERE source_at >= ? AND source_at < ?
              AND value_numeric IS NOT NULL
            GROUP BY node_id, bucket_at
            ON DUPLICATE KEY UPDATE
              avg_value    = VALUES(avg_value),
              min_value    = VALUES(min_value),
              max_value    = VALUES(max_value),
              sample_count = VALUES(sample_count)
        ", [now()->subMinutes(2), now()->subMinute()]);
    }
}

// Schedule:
$schedule->job(new AggregatePlcReadings)->everyMinute();
```
<!-- @endcode-block -->

Two-minute lag tolerates buffer-drain delays.

## Query API

A simple controller endpoint:

<!-- @code-block language="php" label="controller" -->
```php
class PlcReadingsController
{
    public function show(Request $request, string $nodeId): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after:from',
            'agg'  => 'in:raw,1m',
        ]);

        $from = \Carbon\Carbon::parse($request->input('from'));
        $to   = \Carbon\Carbon::parse($request->input('to'));

        if ($request->input('agg') === '1m' || $from->diffInHours($to) > 2) {
            $series = \DB::table('plc_reading_aggregates_1m')
                ->where('node_id', $nodeId)
                ->whereBetween('bucket_at', [$from, $to])
                ->orderBy('bucket_at')
                ->get();
        } else {
            $series = PlcReading::for($nodeId)->between($from, $to)
                ->orderBy('source_at')
                ->get();
        }

        return response()->json($series);
    }
}
```
<!-- @endcode-block -->

The endpoint serves raw data for short ranges, aggregates for
long ranges — transparently.

## Multi-tenant variant

For per-tenant tables, add `tenant_id` to the schema and scope
queries via global scope:

<!-- @code-block language="php" label="tenant scope" -->
```php
class PlcReading extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function ($query) {
            if (auth()->check()) {
                $query->where('tenant_id', auth()->user()->tenant_id);
            }
        });
    }
}
```
<!-- @endcode-block -->

The listener needs to set `tenant_id` based on connection
metadata — see [Recipes · Multi-plant tenant](./multi-plant-tenant.md).

## Where to read next

- [Alarm routing](./alarm-routing.md) — the alarms-table sibling.
- [Livewire real-time dashboard](./livewire-realtime-dashboard.md) —
  reading from this table in real time.
