---
eyebrow: 'Docs · Events'
lede:    'When to queue, what to queue, how many workers, what queue connection. The rules that keep auto-publish from melting under load.'

see_also:
  - { href: './data-events.md',                         meta: '6 min' }
  - { href: './alarm-events.md',                        meta: '5 min' }
  - { href: '../integrations/horizon-and-queues.md',    meta: '7 min' }

prev: { label: 'Alarm events', href: './alarm-events.md' }
next: { label: 'Logging',      href: '../observability/logging.md' }
---

# Queued listeners

OPC UA subscriptions can produce thousands of events per minute.
Synchronous listeners that do non-trivial work choke the daemon's
publish loop and starve the OPC UA server's keep-alive cycle.
The fix: queue them.

## When to queue

Quick rules of thumb:

| Listener type                   | Queue?       | Why                                                  |
| ------------------------------- | ------------ | ---------------------------------------------------- |
| Log line, in-memory             | No           | Microseconds — synchronous is fine                   |
| Single Eloquent `insert()`      | Yes (low-throughput exception)| Tens of ms each, adds up               |
| Bulk insert, table-locked       | Yes          | Definitely queue                                     |
| HTTP call (notification, broadcast) | Yes      | Network-bound, slow tail                             |
| Filesystem write                | Yes          | Block on disk                                        |
| Cache `put`                     | Borderline   | Synchronous is fine for Redis. Queue for disk caches |
| Threshold check + early return  | No           | The check is microseconds                            |

The single rule: **if the listener can take >5 ms at p99, queue it.**

## Implementing `ShouldQueue`

<!-- @code-block language="php" label="queued listener" -->
```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class StoreReadings implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'opcua-data';
    public string $connection = 'redis';
    public int    $tries = 3;
    public int    $backoff = 10;       // seconds between retries

    public function handle(\PhpOpcua\Client\Event\DataChangeReceived $event): void { /* ... */ }
}
```
<!-- @endcode-block -->

The event is **serialised onto the queue**. The actual `handle()`
runs on a queue worker. The dispatcher returns to the daemon in
microseconds.

> **Warning — `$event->client` is a live object.** PSR-14 events
> from `opcua-client` carry an `$event->client` reference that
> doesn't serialise cleanly. Either implement `__serialize()` /
> `__sleep()` on your listener to drop it, or — preferably — have a
> tiny synchronous listener extract primitives and dispatch an
> explicit `Job::dispatch(...)` with just the primitives.

## Queue connection and queue name

Use a **dedicated queue** for OPC UA-derived work:

<!-- @code-block language="php" label="config/queue.php" -->
```php
'connections' => [
    'redis' => [
        // ...
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```
<!-- @endcode-block -->

…then in listeners:

<!-- @code-block language="php" label="dedicated queue" -->
```php
public string $queue = 'opcua-data';        // separate from generic 'default'
public string $connection = 'redis';
```
<!-- @endcode-block -->

Run workers specifically on that queue:

<!-- @code-block language="bash" label="terminal — dedicated worker" -->
```bash
php artisan queue:work redis --queue=opcua-data --tries=3
```
<!-- @endcode-block -->

Or via Horizon (see below).

## Horizon supervisor

For Horizon, declare the queue in `config/horizon.php`:

<!-- @code-block language="php" label="config/horizon.php" -->
```php
'environments' => [
    'production' => [
        'opcua-data-supervisor' => [
            'connection'  => 'redis',
            'queue'       => ['opcua-data'],
            'balance'     => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 8,
            'tries'        => 3,
        ],
        'opcua-alarms-supervisor' => [
            'connection'  => 'redis',
            'queue'       => ['opcua-alarms'],
            'balance'     => 'simple',
            'maxProcesses' => 4,
            'tries'        => 5,
        ],
    ],
],
```
<!-- @endcode-block -->

Horizon spins up the right number of workers per queue based on
backlog. Separate supervisors keep slow alarm-routing from
backing up fast data-change persistence.

## Throughput tuning

A typical worker on Redis processes 500-2000 jobs/sec for
lightweight inserts. Tune `maxProcesses` to match:

| Subscription rate    | Per-job time     | maxProcesses              |
| -------------------- | ---------------- | ------------------------- |
| 100 events / sec     | 5 ms             | 1-2                       |
| 1000 events / sec    | 10 ms            | 4-8                       |
| 5000 events / sec    | 20 ms            | 16-32 (consider batching) |

For very high throughput, **batch inserts in the listener**:

<!-- @code-block language="php" label="batched insert" -->
```php
class StoreReadingsBatched implements ShouldQueue
{
    public string $queue = 'opcua-data-batch';

    public function handle(\PhpOpcua\Client\Event\DataChangeReceived $event): void
    {
        Cache::lock('opcua-batch-lock', 1)->get(function () use ($event) {
            $buffer = Cache::get('opcua-batch-buffer', []);
            $buffer[] = [
                'client_handle' => $event->clientHandle,
                'value'         => $event->dataValue->getValue(),
                'source_at'     => $event->dataValue->sourceTimestamp,
            ];

            if (count($buffer) >= 100) {
                PlcReading::insert($buffer);
                Cache::put('opcua-batch-buffer', []);
            } else {
                Cache::put('opcua-batch-buffer', $buffer, minutes: 1);
            }
        });
    }
}

// Drain the buffer every 5 seconds via a scheduled job
$schedule->call(function () {
    $buffer = Cache::pull('opcua-batch-buffer', []);
    if (!empty($buffer)) {
        PlcReading::insert($buffer);
    }
})->everyFiveSeconds();
```
<!-- @endcode-block -->

This is a coarse pattern — for production batching, see
[Recipes · Persistent tag history](../recipes/persistent-tag-history.md)
for a cleaner implementation.

## Retry and failure

Three failure modes worth handling:

### 1 — Transient DB failure

`tries = 3`, `backoff = 10` gives 3 attempts with backoff. Adequate
for occasional connection blips.

### 2 — Persistent processing failure

Define a `failed()` method to handle terminal failure:

<!-- @code-block language="php" label="failed handler" -->
```php
public function failed(\PhpOpcua\Client\Event\DataChangeReceived $event, \Throwable $exception): void
{
    Log::channel('plc')->error("Listener failed permanently", [
        'client_handle' => $event->clientHandle,
        'value'         => $event->dataValue->getValue(),
        'error'         => $exception->getMessage(),
    ]);

    Notification::route('slack', config('alerts.ops_channel'))
        ->notify(new ListenerFailed($event, $exception));
}
```
<!-- @endcode-block -->

The failed job lands on `failed_jobs`. Inspect with
`php artisan queue:failed`.

### 3 — Worker memory exhaustion

Long-running queue workers leak memory in PHP. Set
`--max-time=3600` (restart hourly) or `--memory=512` (restart at
512 MB) on the worker config. Horizon does this by default.

## Don't re-create your own queue from scratch

A common anti-pattern: persist the event to a custom table,
then poll the table from another worker. The framework already
has a queue — use it.

## Don't broadcast on the synchronous path

`ShouldBroadcastNow` skips the queue for sub-100 ms broadcasts.
That's **fine for a Broadcasting event**, but a listener that
broadcasts should itself implement `ShouldQueue`:

<!-- @code-block language="php" label="right shape" -->
```php
class BroadcastTagUpdate implements ShouldQueue   // listener is queued
{
    public string $queue = 'opcua-broadcast';

    public function handle(\PhpOpcua\Client\Event\DataChangeReceived $event): void
    {
        broadcast(new TagUpdated($event));       // event might be ShouldBroadcastNow
    }
}
```
<!-- @endcode-block -->

The listener goes to the queue; from the queue worker, the
broadcast goes out immediately. This keeps the daemon's publish
loop unblocked.

## Idempotency

If a listener can be retried, it must be idempotent. Two
strategies:

### 1 — Natural keys

<!-- @code-block language="php" label="upsert pattern" -->
```php
PlcReading::updateOrCreate(
    [
        'client_handle' => $event->clientHandle,
        'source_at'     => $event->dataValue->sourceTimestamp,
    ],
    [
        'value' => $event->dataValue->getValue(),
    ],
);
```
<!-- @endcode-block -->

The `source_at` timestamp is naturally a unique key — retries
update the same row.

### 2 — Deduplication keys

<!-- @code-block language="php" label="dedup cache" -->
```php
$dedupKey = "dedup:{$event->clientHandle}:" .
            $event->dataValue->sourceTimestamp?->format('YmdHisu');

if (Cache::add($dedupKey, true, minutes: 10)) {
    // first time seeing this — process
    PlcReading::create([/* ... */]);
}
// else — duplicate, skip silently
```
<!-- @endcode-block -->

Use the dedup-cache approach when there's no natural primary key.

## Monitoring queue health

| Metric                              | Where                               | Alert threshold                    |
| ----------------------------------- | ----------------------------------- | ---------------------------------- |
| Backlog                             | Horizon dashboard                   | > 1000 jobs                        |
| Failed jobs                         | `failed_jobs` table                 | > 10 per hour                      |
| Average runtime                     | Horizon metrics                     | > 2× normal                        |
| Workers running                     | Horizon                             | < `minProcesses`                   |

A backlog growing without bound indicates listeners aren't keeping
up — add workers or batch more aggressively.

## Where to read next

- [Horizon and queues](../integrations/horizon-and-queues.md) —
  Horizon-specific patterns.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  the canonical batched-persistence pattern.
- [Observability](../observability/logging.md) — what to log
  about queue runtime.
