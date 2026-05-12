---
eyebrow: 'Docs · Operations'
lede:    'Writing values to PLC tags. Type detection, explicit types, batch writes, status verification — and the responsibilities Laravel apps inherit when they mutate physical equipment.'

see_also:
  - { href: './reading.md',                       meta: '6 min' }
  - { href: '../reference/exceptions.md',          meta: '4 min' }
  - { href: '../security/credentials.md',          meta: '5 min' }

prev: { label: 'Reading',  href: './reading.md' }
next: { label: 'Browsing', href: './browsing.md' }
---

# Writing

`Opcua::write()` sends a value to a writable OPC UA node. Unlike
reading, writes have real-world consequences — they change
setpoints, command actuators, toggle alarm acknowledgements.

<!-- @callout type="warning" -->
**Writes mutate physical equipment.** Treat them with the same
care as `DELETE FROM` in SQL. Authorisation, audit, and
rate-limiting belong at the application layer.
<!-- @endcallout -->

## Basic write

<!-- @code-block language="php" label="write" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

Opcua::write('ns=2;s=Setpoint', 75.0);
```
<!-- @endcode-block -->

Returns the OPC UA status code as an `int`. Status `0` is "Good".
Anything else means the server rejected the write — typically
`BadTypeMismatch`, `BadNotWritable`, `BadUserAccessDenied`. Check
with the `StatusCode` helper:

```php
use PhpOpcua\Client\Types\StatusCode;

$status = Opcua::write('ns=2;s=Setpoint', 75.0);
if (! StatusCode::isGood($status)) {
    throw new RuntimeException(
        'Setpoint write rejected: ' . StatusCode::getName($status)
    );
}
```

A `ConnectionException` is thrown for transport failures (TCP, etc.).

## Type detection

`Opcua::write()` infers the OPC UA `BuiltinType` from the PHP
value:

| PHP value                | Detected BuiltinType    |
| ------------------------ | ----------------------- |
| `true` / `false`         | `Boolean`               |
| `int` in `[-2^31, 2^31)` | `Int32`                 |
| `int` outside that range | `Int64`                 |
| `float`                  | `Double`                |
| `string`                 | `String`                |
| `DateTimeImmutable`      | `DateTime`              |
| `array<int>`             | `Int32` array           |
| `array<float>`           | `Double` array          |

This is **adequate for most cases** but it has a tell — if the
node's actual type is `Float`, writing a PHP float sends `Double`,
and the server returns `Bad_TypeMismatch`.

## Explicit types

When the server rejects an auto-detected write, set the type
explicitly — pass it as the third argument to `write()`:

<!-- @code-block language="php" label="explicit Float" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

Opcua::write('ns=2;s=Setpoint', 75.0, BuiltinType::Float);
```
<!-- @endcode-block -->

Common reasons to override:

| Server expects   | PHP value you have   | Explicit type    |
| ---------------- | -------------------- | ---------------- |
| `Float`          | `float`              | `BuiltinType::Float` |
| `Int16`          | `int`                | `BuiltinType::Int16` |
| `UInt32`         | `int`                | `BuiltinType::UInt32` |
| `Byte`           | small `int`          | `BuiltinType::Byte`  |
| `String` for a numeric tag | numeric string | `BuiltinType::String` |

## Batch write

Call `writeMulti()` with **no arguments** to get a
`WriteMultiBuilder`; chain `node()` followed by `value()` (or
`typed()` for an explicit type) for each entry, then `execute()`:

<!-- @code-block language="php" label="batch write" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

$statuses = Opcua::writeMulti()
    ->node('ns=2;s=Setpoint')->value(75.0)
    ->node('ns=2;s=Mode')->value('Auto')
    ->node('ns=2;s=Run')->typed(true, BuiltinType::Boolean)
    ->execute();
```
<!-- @endcode-block -->

`value()` takes a **single** argument (auto-detect type). `typed()`
takes `(value, BuiltinType)`. The `node()` call is mandatory before
each `value()` / `typed()` — it sets the target NodeId for the next
write item.

`execute()` returns `int[]` — one OPC UA status code per item, in
the order added. The call is **non-atomic**: a partial failure
still landed the successful writes.

For atomic semantics (call multi-write methods on the server)
see [Method calls](./method-calls.md).

## Status verification

A write that returns successfully **at the OPC UA service level**
might still not have landed at the device:

<!-- @code-block language="php" label="write-then-read" -->
```php
Opcua::write('ns=2;s=Setpoint', 75.0);

usleep(200_000);  // give the PLC a beat

$dv = Opcua::read('ns=2;s=Setpoint');
if (abs((float) $dv->getValue() - 75.0) > 0.01) {
    throw new RuntimeException("Setpoint did not stick");
}
```
<!-- @endcode-block -->

This is **server-specific** — most production PLCs honour writes
immediately, but a small number of complex devices defer the
write to an internal cycle. Match the verification approach to
the device.

## Authorisation

The package itself doesn't gate writes. Three places to put
authorisation:

### 1 — Laravel policy

<!-- @code-block language="php" label="policy" -->
```php
class PlcTagPolicy
{
    public function write(User $user, string $nodeId): bool
    {
        // role-based — operators can write setpoints, only supervisors can toggle Run
        if (str_ends_with($nodeId, 'Setpoint')) {
            return $user->hasRole('operator');
        }
        if (str_ends_with($nodeId, 'Run')) {
            return $user->hasRole('supervisor');
        }
        return false;
    }
}

// In the controller
$this->authorize('write', $request->input('node_id'));
Opcua::write($request->input('node_id'), $request->input('value'));
```
<!-- @endcode-block -->

### 2 — Audit log

<!-- @code-block language="php" label="audit log" -->
```php
$user = $request->user();
$nodeId = $request->input('node_id');
$value = $request->input('value');

$before = Opcua::read($nodeId)->getValue();
Opcua::write($nodeId, $value);
$after = Opcua::read($nodeId)->getValue();

PlcWriteAudit::create([
    'user_id'    => $user->id,
    'node_id'    => $nodeId,
    'before'     => $before,
    'requested'  => $value,
    'after'      => $after,
    'written_at' => now(),
]);
```
<!-- @endcode-block -->

### 3 — Rate limit

<!-- @code-block language="php" label="rate limit" -->
```php
Route::middleware(['auth', 'throttle:10,1'])
    ->post('/plc/write', WriteController::class);
```
<!-- @endcode-block -->

For per-tag rate limits (no more than one setpoint change per
minute, regardless of user), use Laravel's `RateLimiter`:

<!-- @code-block language="php" label="per-tag rate limit" -->
```php
$key = "opcua-write:{$nodeId}";
if (! RateLimiter::attempt($key, maxAttempts: 1, callback: fn() => null, decaySeconds: 60)) {
    abort(429, "Tag updated too recently — wait before changing again");
}

Opcua::write($nodeId, $value);
```
<!-- @endcode-block -->

## Confirmation flows

For setpoints with large impact (a recipe load, a line speed
change of 20%+), introduce a **two-step confirmation**:

<!-- @code-block language="php" label="confirmation token" -->
```php
// step 1 — propose
$token = (string) Str::uuid();
Cache::put("plc-write:{$token}", [
    'node'  => $nodeId,
    'value' => $value,
    'user'  => $user->id,
], minutes: 5);

return response()->json([
    'confirmation_token' => $token,
    'message'            => "Setpoint will change from $before to $value. Confirm within 5 minutes.",
]);

// step 2 — commit
$pending = Cache::pull("plc-write:{$token}");
abort_unless($pending && $pending['user'] === $user->id, 419, 'Invalid token');

Opcua::write($pending['node'], $pending['value']);
```
<!-- @endcode-block -->

The package gives you the wire; the safety layer is yours.

## Writing arrays

<!-- @code-block language="php" label="array write" -->
```php
Opcua::write('ns=2;s=RecipeIngredients', [1.5, 2.0, 3.2, 0.8]);
```
<!-- @endcode-block -->

The package detects array uniformly — all-int → `Int32[]`, all-
float → `Double[]`, mixed → falls back to `Variant[]`.

For arrays of explicit type, pass it as the third argument to
`write()`:

<!-- @code-block language="php" label="typed array write" -->
```php
Opcua::write(
    'ns=2;s=RecipeIngredients',
    [1.5, 2.0, 3.2, 0.8],
    BuiltinType::Float,
);
```
<!-- @endcode-block -->

## Multi-dimensional arrays

Multi-dimensional arrays are written by flattening yourself and
relying on the server's declared `ArrayDimensions` attribute on the
target node. The fluent builder does **not** expose a `dimensions()`
modifier — encode the layout into the node configuration on the
server side, then write the flattened array:

<!-- @code-block language="php" label="2D array write" -->
```php
$matrix = [
    [1.0, 2.0, 3.0],
    [4.0, 5.0, 6.0],
];

// Server's ns=2;s=Matrix is declared with ArrayDimensions = [2, 3].
// The package encodes the array as a flat Double[6] in row-major order.
Opcua::write('ns=2;s=Matrix', array_merge(...$matrix), BuiltinType::Double);
```
<!-- @endcode-block -->

## Writing structures

OPC UA structures use the
[opcua-client structure handling](https://github.com/php-opcua/opcua-client/blob/master/docs/types/extension-objects.md) —
a builder-internal feature, mostly identical between direct and
Laravel paths.

## Error recovery

A failed write should not be silently retried. Setpoint changes
are not idempotent in the user's mental model — "I sent 75, the
operator received feedback that it failed, then 30 seconds later
the PLC accepted 75 anyway" is worse than a clean failure.

If retry is needed, scope it tightly:

<!-- @code-block language="php" label="bounded retry" -->
```php
try {
    Opcua::write($node, $value);
} catch (ConnectionException $e) {
    // single retry on transport error only
    sleep(1);
    Opcua::write($node, $value);
}
```
<!-- @endcode-block -->

`ServiceException` indicates the server **received and rejected**
the write. Retry will not help — surface to the operator.

## Where to read next

- [Browsing](./browsing.md) — discovering writable nodes.
- [Method calls](./method-calls.md) — atomic multi-write
  alternatives.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) —
  acknowledgement writes from Laravel.
