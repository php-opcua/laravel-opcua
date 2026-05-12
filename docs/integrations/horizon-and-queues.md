---
eyebrow: 'Docs · Integrations'
lede:    'OPC UA work on the queue. Horizon supervisor config, the queues Laravel apps converge on, end-to-end example of fleet sampling with retry, backoff, and dedicated workers.'

see_also:
  - { href: './octane-and-frankenphp.md',                meta: '7 min' }
  - { href: '../events/queued-listeners.md',             meta: '5 min' }
  - { href: '../recipes/persistent-tag-history.md',      meta: '6 min' }

prev: { label: 'Octane & FrankenPHP', href: './octane-and-frankenphp.md' }
next: { label: 'Broadcasting',        href: './broadcasting.md' }
---

# Horizon and queues

OPC UA work that's bursty (fleet sampling, large history reads,
recipe loads) belongs on the queue. Horizon's supervisor model
gives you per-queue worker pools, retries, and a UI.

## Queue topology — the recommended layout

Different OPC UA workloads have different SLAs. Separate them:

| Queue           | What's on it                            | Priority             |
| --------------- | --------------------------------------- | -------------------- |
| `opcua-control` | Setpoint writes, method calls            | High — operator-visible |
| `opcua-data`    | Tag reads, periodic samples              | Normal               |
| `opcua-history` | History reads, bulk samples              | Low                  |
| `opcua-alarms`  | Alarm-event processing                   | High                 |

Separating prevents a slow history read from blocking a setpoint
change.

## Horizon config

<!-- @code-block language="php" label="config/horizon.php" -->
```php
return [
    'use' => 'default',

    'environments' => [
        'production' => [
            'opcua-control-supervisor' => [
                'connection'    => 'redis',
                'queue'         => ['opcua-control'],
                'balance'       => 'simple',
                'minProcesses'  => 1,
                'maxProcesses'  => 4,
                'tries'         => 1,             // setpoint writes are not idempotent
                'timeout'       => 30,
            ],
            'opcua-data-supervisor' => [
                'connection'    => 'redis',
                'queue'         => ['opcua-data'],
                'balance'       => 'auto',
                'minProcesses'  => 2,
                'maxProcesses'  => 8,
                'tries'         => 3,
                'timeout'       => 60,
            ],
            'opcua-history-supervisor' => [
                'connection'    => 'redis',
                'queue'         => ['opcua-history'],
                'balance'       => 'simple',
                'minProcesses'  => 1,
                'maxProcesses'  => 2,             // history is heavy; few workers
                'tries'         => 3,
                'timeout'       => 600,           // history reads can take minutes
            ],
            'opcua-alarms-supervisor' => [
                'connection'    => 'redis',
                'queue'         => ['opcua-alarms'],
                'balance'       => 'simple',
                'minProcesses'  => 1,
                'maxProcesses'  => 4,
                'tries'         => 5,
                'timeout'       => 30,
            ],
        ],

        'local' => [
            'opcua-supervisor' => [
                'connection'    => 'redis',
                'queue'         => ['opcua-control', 'opcua-data', 'opcua-history', 'opcua-alarms'],
                'balance'       => 'auto',
                'minProcesses'  => 1,
                'maxProcesses'  => 2,
                'tries'         => 3,
            ],
        ],
    ],
];
```
<!-- @endcode-block -->

Production gets four supervisors. Local gets one supervisor
handling all queues with 1-2 workers.

## End-to-end — fleet sampling

A complete fleet sampler: a scheduled job that dispatches one
sample job per PLC, each running on the `opcua-data` queue.

### Migration

<!-- @code-block language="php" label="migration" -->
```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plc_samples', function (Blueprint $table) {
            $table->id();
            $table->string('plc_serial');
            $table->string('node_id');
            $table->decimal('value', 12, 4)->nullable();
            $table->integer('status_code');
            $table->timestamp('source_at');
            $table->timestamps();

            $table->index(['plc_serial', 'source_at']);
        });
    }
};
```
<!-- @endcode-block -->

### Fleet registry

<!-- @code-block language="php" label="app/Models/PlcUnit.php" -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlcUnit extends Model
{
    protected $fillable = ['serial', 'endpoint', 'security_policy', 'security_mode'];

    public function toConnectionConfig(): array
    {
        return [
            'endpoint'         => $this->endpoint,
            'security_policy'  => $this->security_policy,
            'security_mode'    => $this->security_mode,
            'client_cert_path' => config('opcua.connections.default.client_cert_path'),
            'client_key_path'  => config('opcua.connections.default.client_key_path'),
            'username'         => config('opcua.connections.default.username'),
            'password'         => config('opcua.connections.default.password'),
            'timeout'          => 8.0,
        ];
    }
}
```
<!-- @endcode-block -->

### The job

<!-- @code-block language="php" label="app/Jobs/SamplePlc.php" -->
```php
namespace App\Jobs;

use App\Models\{PlcUnit, PlcSample};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Exception\{ConnectionException, InactiveSessionException};

class SamplePlc implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'opcua-data';
    public int $tries = 3;
    public int $backoff = 5;
    public int $timeout = 30;

    public function __construct(public string $plcSerial, public array $nodeIds) {}

    public function handle(OpcuaManager $opcua): void
    {
        $unit = PlcUnit::where('serial', $this->plcSerial)->firstOrFail();

        $cfg = $unit->toConnectionConfig();
        $client = $opcua->connectTo(
            endpointUrl: $cfg['endpoint'],
            config:      $cfg,
            as:          'plc-' . $this->plcSerial,
        );

        $builder = $client->readMulti();
        foreach ($this->nodeIds as $node) {
            $builder->node($node);
        }
        $results = $builder->execute();

        $rows = [];
        foreach ($this->nodeIds as $i => $node) {
            $v = $results[$i]->getValue();
            $rows[] = [
                'plc_serial'   => $this->plcSerial,
                'node_id'      => $node,
                'value'        => is_numeric($v) ? $v : null,
                'status_code'  => $results[$i]->statusCode,
                'source_at'    => $results[$i]->sourceTimestamp ?? now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        PlcSample::insert($rows);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::channel('plc')->error("Sample failed for {$this->plcSerial}", [
            'nodes' => $this->nodeIds,
            'error' => $exception->getMessage(),
        ]);
    }
}
```
<!-- @endcode-block -->

`retryUntil()` is more useful than `$tries` for transient
failures — give it 5 minutes total wall-time, not just 3
attempts back-to-back.

### Schedule the dispatch

<!-- @code-block language="php" label="app/Console/Kernel.php" -->
```php
use App\Jobs\SamplePlc;
use App\Models\PlcUnit;

protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        $nodes = ['ns=2;s=Speed', 'ns=2;s=Temperature', 'ns=2;s=Pressure'];

        PlcUnit::query()
            ->where('active', true)
            ->chunkById(50, function ($units) use ($nodes) {
                foreach ($units as $unit) {
                    SamplePlc::dispatch($unit->serial, $nodes);
                }
            });
    })->everyMinute()->name('plc-fleet-sample')->onOneServer();
}
```
<!-- @endcode-block -->

`onOneServer()` is essential — without it, every Laravel cron
host would dispatch the same jobs.

## Watching Horizon

<!-- @code-block language="bash" label="terminal — Horizon UI" -->
```bash
php artisan horizon                           # start (Supervisor manages this)
php artisan horizon:status                    # health
php artisan horizon:list                      # workers / queues
```
<!-- @endcode-block -->

The Horizon UI at `/horizon` shows per-queue throughput, runtime
percentiles, failed-job inspection. Watch `opcua-data` and
`opcua-history` for backlog growth.

## Failed jobs

`failed_jobs` table records every terminal failure. Inspect:

<!-- @code-block language="bash" label="terminal" -->
```bash
php artisan queue:failed
php artisan queue:retry all                  # retry all
php artisan queue:retry <uuid>               # retry one
php artisan queue:forget <uuid>              # remove from failed list
```
<!-- @endcode-block -->

For OPC UA failures, set up an alert that triggers on
`failed_jobs > 10/hour`:

<!-- @code-block language="php" label="failed job listener" -->
```php
use Illuminate\Queue\Events\JobFailed;

Event::listen(JobFailed::class, function (JobFailed $event) {
    if (! str_contains($event->job->resolveName(), 'Plc')) {
        return;
    }

    Notification::route('slack', config('alerts.ops_channel'))
        ->notify(new PlcJobFailed(
            jobName:   $event->job->resolveName(),
            exception: $event->exception->getMessage(),
        ));
});
```
<!-- @endcode-block -->

## Performance — chunking and batching

For very large fleets (1000+ PLCs), chunk the dispatch:

<!-- @code-block language="php" label="chunked dispatch with delay" -->
```php
PlcUnit::query()->where('active', true)->chunkById(100, function ($units, $page) {
    foreach ($units as $i => $unit) {
        // Spread the dispatch over time to avoid all hitting at once
        SamplePlc::dispatch($unit->serial, $nodes)
            ->delay(now()->addSeconds($i % 10));
    }
});
```
<!-- @endcode-block -->

Each minute's sample is spread over 10 seconds. Smoother queue
behaviour and less likely to saturate the OPC UA layer at the
start of each minute.

## Job batching

For history reads where a batch needs to succeed or fail
together:

<!-- @code-block language="php" label="batched history" -->
```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

Bus::batch([
    new FetchDailyHistory('plc-1', '2026-05-15'),
    new FetchDailyHistory('plc-2', '2026-05-15'),
    new FetchDailyHistory('plc-3', '2026-05-15'),
])->then(function (Batch $batch) {
    // All succeeded
    Log::info("Daily history complete for {$batch->totalJobs} PLCs");
})->catch(function (Batch $batch, \Throwable $e) {
    // First failure within the batch
    Log::error("History batch failed: {$e->getMessage()}");
})->onQueue('opcua-history')->dispatch();
```
<!-- @endcode-block -->

## OPC UA connection cache across workers

By default, each queue worker has its own `OpcuaManager`. So 8
`opcua-data` workers each open their own connection — 8 server-
side sessions. Three options to reduce this:

1. **Run fewer workers** with batching inside.
2. **Use managed mode** — all workers share one daemon-held
   session per (endpoint+identity).
3. **Run a single worker with concurrency** in newer Laravel
   versions (still experimental).

For production deployments with security cost (RSA signing),
managed mode is the better answer. See [Session manager ·
Overview](../session-manager/overview.md).

## Worker memory budget

Long-running PHP workers can accumulate memory over time. The
standard Laravel `queue:work` flags `--memory` and `--max-time`
let workers restart cleanly:

| Setting          | Typical value |
| ---------------- | ------------- |
| `--memory`       | `512`         |
| `--timeout`      | per-job       |
| `--max-time`     | `3600`        |

These are **standard Laravel queue-worker flags** (not anything
this package adds). Horizon's per-supervisor config exposes the
same knobs.

## Where to read next

- [Broadcasting](./broadcasting.md) — pushing OPC UA data to the
  browser.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  the persistence end of fleet sampling.
