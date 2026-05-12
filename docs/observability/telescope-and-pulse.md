---
eyebrow: 'Docs · Observability'
lede:    'Telescope for forensic deep-dives; Pulse for real-time aggregate health. Both work with the package out of the box — these are the patterns that make the data useful.'

see_also:
  - { href: './logging.md',                              meta: '5 min' }
  - { href: './debugging.md',                            meta: '5 min' }
  - { href: '../integrations/horizon-and-queues.md',     meta: '7 min' }

prev: { label: 'Debugging',         href: './debugging.md' }
next: { label: 'Policies & modes',  href: '../security/policies-and-modes.md' }
---

# Telescope and Pulse

Two complementary Laravel first-party observability tools, two
different use cases.

## Telescope — forensic per-request

[Laravel Telescope](https://laravel.com/docs/telescope) captures
every request, every job, every event, every exception, every
cache hit. Heavyweight; not production-grade for large traffic.
**Use for development and staging.**

### What it captures from the package

Without any configuration, Telescope captures:

- Every `opcua-client` PSR-14 event that flows through Laravel's
  dispatcher — `DataChangeReceived`, `ClientConnected`,
  `AlarmActivated`, etc.
- Every **exception** the package raises —
  `ConnectionException`, `ServiceException`, …
- Every **queued listener** — duration, retries, payload.
- **Log entries** the package writes via `LoggerInterface`.

The package doesn't add per-call request tracing — OPC UA calls
are not HTTP, so Telescope's request watcher doesn't see them
directly.

### Filtering noise

In a `auto_publish` deployment with high-frequency subscriptions,
Telescope can capture thousands of `DataChangeReceived` events per
second. That's not useful — it overwhelms the dashboard.

Filter in `App\Providers\TelescopeServiceProvider`:

<!-- @code-block language="php" label="filter events" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

protected function configure(): void
{
    Telescope::filter(function (IncomingEntry $entry) {
        // In production, never record OPC UA data-change events
        if ($entry->type === EntryType::EVENT &&
            $entry->content['name'] === DataChangeReceived::class) {
            return false;
        }

        return $this->app->environment('local', 'staging');
    });
}
```
<!-- @endcode-block -->

Keep alarm events and connection events — they're low-volume and
high-value.

### Per-tag drill-down

When a specific tag misbehaves, temporarily enable detail:

<!-- @code-block language="php" label="conditional capture" -->
```php
Telescope::filter(function (IncomingEntry $entry) {
    if ($entry->type === EntryType::EVENT &&
        $entry->content['name'] === DataChangeReceived::class) {

        // DataChangeReceived carries clientHandle, not nodeId.
        // Build the handle => keep-or-drop map at subscription time.
        $handle = $entry->content['data']['clientHandle'] ?? null;
        return in_array($handle, [1, 2]) || $this->app->environment('local');
    }

    return true;
});
```
<!-- @endcode-block -->

Now Telescope captures changes for **those two tags** only —
manageable volume.

### Capturing OPC UA-related exceptions

The default exception watcher captures everything. To group them:

<!-- @code-block language="php" label="exception tag" -->
```php
Telescope::tag(function (IncomingEntry $entry) {
    if ($entry->type === EntryType::EXCEPTION) {
        $class = $entry->content['class'] ?? '';
        if (str_contains($class, 'PhpOpcua')) {
            return ['opcua', class_basename($class)];
        }
    }
    return [];
});
```
<!-- @endcode-block -->

Filter Telescope's exception view by tag `opcua` and you get
just the OPC UA failures.

## Pulse — real-time aggregate

[Laravel Pulse](https://laravel.com/docs/pulse) is the
opposite — lightweight, sampling-based, production-safe. Shows
**aggregates**: requests per minute, slowest queries, queue
backlog, etc.

### Pulse cards for OPC UA

The package doesn't ship custom Pulse cards. The standard cards
already cover most needs:

| Standard Pulse card     | Useful for                                                 |
| ----------------------- | ---------------------------------------------------------- |
| Exceptions              | Spike in OPC UA exceptions = something's wrong upstream    |
| Queues                  | Backlog on `opcua-data` / `opcua-alarms` queues             |
| Slow Jobs               | Listeners taking too long                                  |
| Servers                 | CPU/mem usage of the daemon host                           |

### Custom card — daemon health

For an at-a-glance daemon-up indicator, ship a custom card:

<!-- @code-block language="php" label="custom recorder" -->
```php
class DaemonHealthRecorder
{
    public function __construct(
        protected Repository $config,
        protected OpcuaManager $opcua,
    ) {}

    public function record(SharedBeat $event): void
    {
        if ($event->time->second % 15 !== 0) return;  // every 15s

        try {
            // isSessionManagerRunning() is a socket-file existence check
            // (not a live ping); see docs/session-manager/monitoring-the-daemon.md
            // for a real ping using the IPC envelope.
            $running = $this->opcua->isSessionManagerRunning();
            Pulse::record('opcua_alive', 'daemon', $running ? 1 : 0);
        } catch (\Throwable $e) {
            Pulse::record('opcua_alive', 'daemon', 0);
        }
    }
}
```
<!-- @endcode-block -->

Register in `config/pulse.php`:

<!-- @code-block language="php" label="config/pulse.php" -->
```php
'recorders' => [
    DaemonHealthRecorder::class => [
        'enabled' => true,
    ],
],
```
<!-- @endcode-block -->

Display with a custom card:

<!-- @code-block language="php" label="custom card" -->
```php
<x-pulse>
    <livewire:opcua-daemon-health cols="6" rows="2" />
</x-pulse>
```
<!-- @endcode-block -->

The card renders a graph of `opcua_sessions` over time and a
big red/green status from `opcua_alive`.

### Custom card — notification throughput

The same pattern for tracking subscription throughput:

<!-- @code-block language="php" label="throughput recorder" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class OpcuaThroughputRecorder
{
    public function record(DataChangeReceived $event): void
    {
        Pulse::record('opcua_throughput', 'notifications', 1);
    }
}
```
<!-- @endcode-block -->

Pulse aggregates by minute, hour, day. A drop in the graph is
your alarm.

## Combining the two

Standard pattern in mature deployments:

| Question                                       | Tool        | Where it lands                   |
| ---------------------------------------------- | ----------- | -------------------------------- |
| "Is everything healthy right now?"             | Pulse       | Dashboard, real-time graphs      |
| "Why did the speed reading fail at 14:30?"     | Telescope   | Per-request forensics            |
| "What was the alarm severity distribution last week?"   | Database queries + Pulse | Aggregated counters    |
| "Show me the last hour of OPC UA exceptions"   | Telescope   | Filtered by tag                  |

Pulse for the dashboard; Telescope for the deep-dive.

## Performance impact

| Tool           | Overhead              | Production-safe?                                |
| -------------- | --------------------- | ----------------------------------------------- |
| Telescope (default) | Significant (every entry stored) | No for high-volume apps. Yes for low-volume       |
| Telescope (filtered) | Low (only what you keep)         | Yes, with filters                                 |
| Pulse          | ~1-2% CPU             | Yes — designed for it                            |

For high-frequency OPC UA traffic, **always filter Telescope**.
Pulse is fine as-is.

## Sampling

Both tools support sampling. For Telescope:

<!-- @code-block language="php" label="sampling" -->
```php
Telescope::filter(function (IncomingEntry $entry) {
    if ($entry->type === EntryType::EVENT) {
        return mt_rand(1, 100) <= 5;   // 5% of events
    }
    return true;
});
```
<!-- @endcode-block -->

5% sampling gives statistically-useful Telescope coverage at a
fraction of the storage cost. Tune to your volume.

## Storage backends

| Tool        | Default backend          | Production options             |
| ----------- | ------------------------ | ------------------------------ |
| Telescope   | MySQL (your DB)          | Separate DB, Redis (driver)    |
| Pulse       | MySQL (your DB)          | Separate DB                    |

Both should write to a **separate database** in production —
storing observability data in the same DB as your business data
risks coupling.

## Where to read next

You've finished **Observability**. Next: [Security](../security/policies-and-modes.md)
for the policy / mode reference.
