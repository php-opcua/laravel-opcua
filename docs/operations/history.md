---
eyebrow: 'Docs · Operations'
lede:    'Reading historical values from a HistoryServer. Three flat methods (raw, processed, at-time) — no fluent builder, no aggregate keyword. Examples covering time-range queries, aggregates, and bridging history into Eloquent.'

see_also:
  - { href: './reading.md',                       meta: '6 min' }
  - { href: '../recipes/persistent-tag-history.md', meta: '6 min' }

prev: { label: 'Subscriptions', href: './subscriptions.md' }
next: { label: 'Session manager · Overview', href: '../session-manager/overview.md' }
---

# History

Historizing tags (most modern OPC UA servers do this for some
subset) means the server retains a time series. The OPC UA
HistoryRead service lets you query it.

<!-- @callout type="note" -->
**Not every server is a historian.** Check the node's `Historizing`
attribute, or the server's `AccessHistoryData` capability bit, before
assuming history is available.
<!-- @endcallout -->

## The three real methods

The package surfaces three flat methods on the facade / manager —
**there is no `historyBuilder()` fluent API**. All three live on
`PhpOpcua\Client\OpcUaClientInterface` and are proxied through the
facade.

| Method                                                                                                          | Returns       | Purpose                                  |
| --------------------------------------------------------------------------------------------------------------- | ------------- | ---------------------------------------- |
| `historyReadRaw(NodeId\|string, ?DateTimeImmutable, ?DateTimeImmutable, int $numValuesPerNode = 0, bool $returnBounds = false)` | `DataValue[]` | Raw values in a time range               |
| `historyReadProcessed(NodeId\|string, DateTimeImmutable, DateTimeImmutable, float $processingInterval, NodeId $aggregateType)`  | `DataValue[]` | Server-aggregated values                 |
| `historyReadAtTime(NodeId\|string, array $timestamps)`                                                          | `DataValue[]` | Values at specific timestamps            |

## Raw history read

<!-- @code-block language="php" label="raw history" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$values = Opcua::historyReadRaw(
    nodeId:    'ns=2;s=Speed',
    startTime: new \DateTimeImmutable('-1 hour'),
    endTime:   new \DateTimeImmutable('now'),
);

foreach ($values as $dv) {
    echo $dv->sourceTimestamp->format('H:i:s') . '  ' . $dv->getValue() . "\n";
}
```
<!-- @endcode-block -->

Returns a chronologically-ordered list of `DataValue`. Each one
carries the same shape as a live `read()` — `getValue()`,
`statusCode`, `sourceTimestamp`, `serverTimestamp`.

`$numValuesPerNode = 0` means "no limit" (the server still applies
its own caps). `$returnBounds = true` includes one value before
`startTime` and one after `endTime`, useful for stepwise plots.

## Limits and continuation

Servers cap the number of values returned per call. The underlying
`opcua-client` handles continuation points transparently for a
single call. For very large ranges, **paginate yourself** rather
than letting a single call assemble 100 000 values into PHP memory:

<!-- @code-block language="php" label="manual paging" -->
```php
$cursor = new \DateTimeImmutable('-1 day');
$end    = new \DateTimeImmutable('now');

while ($cursor < $end) {
    $chunkEnd = min($cursor->modify('+1 hour'), $end);

    $values = Opcua::historyReadRaw('ns=2;s=Speed', $cursor, $chunkEnd);

    foreach ($values as $dv) {
        PlcReading::create([
            'node_id'   => 'ns=2;s=Speed',
            'value'     => $dv->getValue(),
            'source_at' => $dv->sourceTimestamp,
        ]);
    }

    $cursor = $chunkEnd;
}
```
<!-- @endcode-block -->

Hour-sized chunks fit most usage. Adjust to the data density.

## Aggregates — `historyReadProcessed`

Most servers support server-side aggregates: averages, min, max,
counts over time buckets. Let the server collapse 30 000 raw values
into 60 minute-buckets to save bandwidth.

`historyReadProcessed` takes the aggregate as a **NodeId** — use the
standard `Aggregates` node-id constants (namespace 0):

<!-- @code-block language="php" label="aggregate read" -->
```php
use PhpOpcua\Client\Types\NodeId;

$start = new \DateTimeImmutable('-1 day');
$end   = new \DateTimeImmutable('now');

// Aggregate = Average (NodeId ns=0;i=2342)
$buckets = Opcua::historyReadProcessed(
    nodeId:             'ns=2;s=Speed',
    startTime:          $start,
    endTime:            $end,
    processingInterval: 3600.0 * 1000.0,        // 1 hour, in ms
    aggregateType:      NodeId::numeric(0, 2342),
);
```
<!-- @endcode-block -->

`processingInterval` is in **milliseconds**. Standard aggregate
NodeIds (namespace 0):

| Aggregate                     | NodeId  |
| ----------------------------- | ------- |
| `Average`                     | `i=2342` |
| `TimeAverage`                 | `i=2343` |
| `Total`                       | `i=2344` |
| `Minimum`                     | `i=2346` |
| `Maximum`                     | `i=2347` |
| `Count`                       | `i=2352` |
| `StandardDeviationSample`     | `i=2426` |

Check the server's
`Server.ServerCapabilities.AggregateFunctions` node for the
supported subset — they vary.

## At-time reads — `historyReadAtTime`

For values at specific moments (the server interpolates / picks
the surrounding raw values per its rules):

<!-- @code-block language="php" label="at-time" -->
```php
$timestamps = [
    new \DateTimeImmutable('2026-05-15 10:00:00'),
    new \DateTimeImmutable('2026-05-15 10:30:00'),
    new \DateTimeImmutable('2026-05-15 11:00:00'),
];

$values = Opcua::historyReadAtTime('ns=2;s=Speed', $timestamps);

// $values[$i] aligns with $timestamps[$i]
```
<!-- @endcode-block -->

## Multi-node history

There is no built-in "multi-node history" method — issue one call
per node (or build your own concurrency wrapper):

<!-- @code-block language="php" label="multi-node history" -->
```php
$nodes  = ['ns=2;s=Speed', 'ns=2;s=Temperature', 'ns=2;s=Pressure'];
$start  = new \DateTimeImmutable('-1 hour');
$end    = new \DateTimeImmutable('now');

$results = [];
foreach ($nodes as $node) {
    $results[$node] = Opcua::historyReadRaw($node, $start, $end);
}
```
<!-- @endcode-block -->

## Combining live + history in one response

A common analytics pattern: get the last 24 hours of hourly averages
plus the current value:

<!-- @code-block language="php" label="last 24h + current" -->
```php
use PhpOpcua\Client\Types\NodeId;

$start = new \DateTimeImmutable('-1 day');
$end   = new \DateTimeImmutable('now');

$series = Opcua::historyReadProcessed(
    'ns=2;s=Speed', $start, $end,
    3600.0 * 1000.0,
    NodeId::numeric(0, 2342),  // Average
);

$current = Opcua::read('ns=2;s=Speed');

return response()->json([
    'series'  => $series,
    'latest'  => $current,
]);
```
<!-- @endcode-block -->

## When the server isn't a historian

You can build your own historian using a live subscription
persisting to Eloquent — see
[Recipes · Persistent tag history](../recipes/persistent-tag-history.md).
The trade-off:

| Approach                       | Best for                                           |
| ------------------------------ | -------------------------------------------------- |
| OPC UA history on server       | Long retention, large data, low ops surface        |
| Subscription → Eloquent        | Custom retention policies, Laravel-native queries  |
| Both                           | Short-term in Eloquent + long-term on server       |

The third option is most common in mature plants — keep the last
24h in your DB for fast UI queries, fall back to OPC UA history for
older data.

## Performance — what to expect

| Query                           | Approx duration on a typical historian      |
| ------------------------------- | ------------------------------------------- |
| 1 node, 1 hour raw (~3600 vals) | 200-500 ms                                   |
| 1 node, 1 day raw               | 1-3 s                                        |
| 1 node, 1 day @ 1 min average   | 100-300 ms                                   |
| 100 nodes, 1 hour raw           | 1-5 s                                        |

For analytics dashboards, **always** prefer
`historyReadProcessed` over raw — server-side aggregation drops
orders of magnitude of data on the wire.

## In queued jobs

History reads are slow and bursty — dispatch them to queued jobs
rather than running them in the request cycle:

<!-- @code-block language="php" label="queued history" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\Types\NodeId;

class FetchDailyHistory implements ShouldQueue
{
    public string $queue = 'opcua-history';

    public function __construct(public string $nodeId, public string $day) {}

    public function handle(OpcuaManager $opcua): void
    {
        $start = new \DateTimeImmutable($this->day . ' 00:00:00');
        $end   = $start->modify('+1 day');

        $values = $opcua->historyReadProcessed(
            $this->nodeId, $start, $end,
            3600.0 * 1000.0,                   // 1-hour buckets
            NodeId::numeric(0, 2342),          // Average
        );

        foreach ($values as $dv) {
            DailyPlcAggregate::create([
                'node_id'  => $this->nodeId,
                'hour'     => $dv->sourceTimestamp,
                'average'  => $dv->getValue(),
            ]);
        }
    }
}

// Dispatch from a daily schedule:
$schedule->call(function () {
    foreach (PlcTag::historized()->pluck('node_id') as $nodeId) {
        FetchDailyHistory::dispatch($nodeId, now()->subDay()->toDateString());
    }
})->dailyAt('01:00');
```
<!-- @endcode-block -->

## Where to read next

You've finished **Operations**. Continue with [Session manager
overview](../session-manager/overview.md) for the daemon deep-dive,
or jump to [Events](../events/overview.md) for the publish/subscribe
surface in managed mode.
