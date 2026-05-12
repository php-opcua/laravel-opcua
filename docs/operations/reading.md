---
eyebrow: 'Docs · Operations'
lede:    'Reading values, batching, non-Value attributes, error handling, the DataValue shape. Practical Laravel-shaped examples per scenario.'

see_also:
  - { href: '../using-the-client/using-builders.md', meta: '5 min' }
  - { href: '../reference/exceptions.md',            meta: '4 min' }
  - { href: '../recipes/persistent-tag-history.md',  meta: '6 min' }

prev: { label: 'Using builders', href: '../using-the-client/using-builders.md' }
next: { label: 'Writing',        href: './writing.md' }
---

# Reading

The most common operation. Through the Laravel facade:

<!-- @code-block language="php" label="basic read" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$dv = Opcua::read('ns=2;s=Speed');
// $dv->getValue()     => 75.42
// $dv->statusCode     => 0
// $dv->sourceTimestamp / serverTimestamp => DateTimeImmutable
```
<!-- @endcode-block -->

`Opcua::read()` directly calls
`OpcUaClientInterface::read(NodeId|string, int $attributeId = 13, bool $refresh = false): DataValue`.
`$attributeId` defaults to `13` (Value); pass other `AttributeId::*`
constants for DisplayName / DataType / etc. `$refresh` bypasses
the read-metadata cache when `true`.

## The DataValue shape

| Accessor / property | Type                 | Meaning                                                      |
| ------------------- | -------------------- | ------------------------------------------------------------ |
| `getValue()`        | mixed                | The decoded value — PHP type follows the OPC UA `BuiltinType`. The underlying `$value` property is `private`; always go through `getValue()`. |
| `$dv->statusCode`   | int                  | OPC UA status code. 0 = good, otherwise per-spec failure code |
| `$dv->sourceTimestamp` | `?DateTimeImmutable` | When the device produced the value                       |
| `$dv->serverTimestamp` | `?DateTimeImmutable` | When the OPC UA server timestamped it                    |

There is **no `->type` or `->dimensions` accessor on `DataValue`** —
those concepts belong to the wrapped `Variant` and aren't exposed
through `DataValue`'s public surface.

A `statusCode` of 0 is the only "definitely good" reading. Any
other value means the server reports a problem — uncertain data,
stale data, out of service, etc. Check `statusCode` in
production code.

## Reading many tags

For more than ~3 tags, batch. Call `readMulti()` with **no
arguments** to get a `ReadMultiBuilder`; call `execute()` to run:

<!-- @code-block language="php" label="batch read" -->
```php
$values = Opcua::readMulti()
    ->node('ns=2;s=Speed')
    ->node('ns=2;s=Temperature')
    ->node('ns=2;s=Pressure')
    ->execute();

// $values is array<int, DataValue>, keyed positionally:
// $values[0] => DataValue for Speed
// $values[1] => DataValue for Temperature
// $values[2] => DataValue for Pressure
```
<!-- @endcode-block -->

Order is preserved. One round-trip — much faster than three
sequential `read()` calls.

The package automatically chunks batch reads to the server-advertised
`MaxNodesPerRead` (commonly 1000-2500). You can read 10 000 tags in
a single `execute()` call.

`ReadMultiBuilder::execute()` **always returns an array** —
positional even for a single node. There is no `executeMany()` on
the read builder.

## Reading non-Value attributes

Each OPC UA node has many attributes. `Value` is by far the most
common. To read others, pass the `attributeId` as the second
argument to `read()`:

<!-- @code-block language="php" label="read display name" -->
```php
use PhpOpcua\Client\Types\AttributeId;

// Use the flat read() method with the attribute id, no builder needed:
$dn = Opcua::read('ns=2;s=Speed', AttributeId::DisplayName);

echo $dn->getValue();  // 'Speed'
```
<!-- @endcode-block -->

Common non-Value attributes:

| AttributeId        | Returns                                       | Use case                                |
| ------------------ | --------------------------------------------- | --------------------------------------- |
| `DisplayName`      | Localized text                                | UI labels                               |
| `Description`      | Localized text                                | Tag tooltips                            |
| `DataType`         | NodeId                                        | Inferring the writeable BuiltinType     |
| `NodeClass`        | int (Object, Variable, Method, …)             | Browse-filtering                        |
| `BrowseName`       | QualifiedName                                 | Programmatic identification             |
| `AccessLevel`      | byte bitmask                                  | Is this node writeable / historizable?  |
| `Historizing`      | bool                                          | Is history being recorded?              |
| `ArrayDimensions`  | array of int                                  | Array sizing                            |

The package caches metadata reads when `read_metadata_cache` is
on (see [config](../configuration/config-file.md)) — repeated
reads of `DisplayName` for the same node are O(1) after the
first.

## Casting values

PHP types you get back per `BuiltinType`:

| BuiltinType         | PHP type                                   |
| ------------------- | ------------------------------------------ |
| `Boolean`           | `bool`                                     |
| `SByte`, `Byte`     | `int`                                      |
| `Int16`...`UInt32`  | `int`                                      |
| `Int64`, `UInt64`   | `int` (on 64-bit PHP) or `string` (overflow) |
| `Float`, `Double`   | `float`                                    |
| `String`            | `string`                                   |
| `DateTime`          | `DateTimeImmutable`                        |
| `Guid`              | `string` (UUID format)                     |
| `ByteString`        | `string` (binary)                          |
| `LocalizedText`     | array `{locale, text}`                     |
| `QualifiedName`     | array `{ns, name}`                         |

A reading you persist into an Eloquent column wants explicit
casting:

<!-- @code-block language="php" label="cast to float column" -->
```php
PlcReading::create([
    'node_id' => 'ns=2;s=Speed',
    'value'   => (float) $dv->getValue(),
    'status'  => $dv->statusCode,
    'read_at' => $dv->sourceTimestamp,
]);
```
<!-- @endcode-block -->

## Status code handling

Two common patterns:

<!-- @code-block language="php" label="treat bad status as failure" -->
```php
$dv = Opcua::read('ns=2;s=Speed');

if ($dv->statusCode !== 0) {
    throw new RuntimeException(
        "Bad read on Speed: status=0x" . dechex($dv->statusCode)
    );
}

$speed = $dv->getValue();
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="store both value and status" -->
```php
$dv = Opcua::read('ns=2;s=Speed');

PlcReading::create([
    'node_id'         => 'ns=2;s=Speed',
    'value'           => $dv->getValue(),
    'status_code'     => $dv->statusCode,
    'is_good'         => $dv->statusCode === 0,
    'source_at'       => $dv->sourceTimestamp,
]);
```
<!-- @endcode-block -->

Mission-critical applications often persist both — the value with
a quality marker, not the value alone.

## Status code helpers

`opcua-client` ships a `StatusCode` helper:

<!-- @code-block language="php" label="status helpers" -->
```php
use PhpOpcua\Client\Types\StatusCode;

if (StatusCode::isGood($dv->statusCode))      { /* ... */ }
if (StatusCode::isUncertain($dv->statusCode)) { /* ... */ }
if (StatusCode::isBad($dv->statusCode))       { /* ... */ }

$name = StatusCode::getName($dv->statusCode);   // 'Good' / 'BadCommunicationError' / …
```
<!-- @endcode-block -->

Useful when piping to logs or when alerting on uncertain readings.

## Error handling

Exceptions per failure mode:

| Exception                  | Trigger                                         | Right response                        |
| -------------------------- | ----------------------------------------------- | ------------------------------------- |
| `ConnectionException`      | TCP layer issue or expired session in managed mode | Retry with backoff; recycle conn   |
| `ServiceException`         | Server returned `Bad_*` at the service layer    | Surface to caller (likely permanent)  |
| `SecurityException`        | Cert / crypto / trust violation                 | Fix the config (no runtime recovery)  |
| `EncodingException`        | Wire decode failed                              | Bug — report                          |

Wrap a critical read:

<!-- @code-block language="php" label="resilient read" -->
```php
use Illuminate\Support\Facades\Log;
use PhpOpcua\Client\Exception\ConnectionException;

function readSpeedResilient(): ?float
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $dv = Opcua::read('ns=2;s=Speed');
            return $dv->statusCode === 0 ? (float) $dv->getValue() : null;
        } catch (ConnectionException $e) {
            Log::channel('plc')->warning("Speed read failed, retrying", [
                'attempt' => $attempt, 'error' => $e->getMessage(),
            ]);
            usleep(200_000 * $attempt);  // 0.2s, 0.4s, 0.6s
        }
    }

    Log::channel('plc')->error("Speed read failed after 3 attempts");
    return null;
}
```
<!-- @endcode-block -->

For long-lived workers, this kind of retry can be configured at
the `auto_retry` config level — see
[Configuration · Config file](../configuration/config-file.md).

## Reading from many connections

A controller endpoint that reports across the whole plant:

<!-- @code-block language="php" label="plant-wide read" -->
```php
Route::get('/plant/state', function () {
    $state = [];
    foreach (array_keys(config('opcua.connections')) as $name) {
        $state[$name] = [
            'speed' => Opcua::connection($name)->read('ns=2;s=Speed')->getValue(),
        ];
    }
    return response()->json($state);
});
```
<!-- @endcode-block -->

For 50+ connections, parallelise with queued jobs — see
[Horizon and queues](../integrations/horizon-and-queues.md).

## Caching read results

Don't cache OPC UA reads in your application cache unless you
genuinely want stale data. The values **are** the truth of the
device; caching them inverts the semantics.

If you need a value the UI can poll cheaply, the right pattern
is a **subscription** that maintains the latest value in cache —
see [Subscriptions](./subscriptions.md) and
[Livewire real-time dashboard](../recipes/livewire-realtime-dashboard.md).

## Where to read next

- [Writing](./writing.md) — the dual operation.
- [Subscriptions](./subscriptions.md) — when you read the same
  value repeatedly.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  full Eloquent persistence pattern.
