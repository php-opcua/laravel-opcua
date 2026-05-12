---
eyebrow: 'Docs · Operations'
lede:    'Invoking OPC UA methods — server-side functions that take inputs and return outputs. The right tool for atomic operations, recipe loads, and acknowledgement flows.'

see_also:
  - { href: './writing.md',                     meta: '5 min' }
  - { href: '../recipes/alarm-routing.md',      meta: '5 min' }

prev: { label: 'Browsing', href: './browsing.md' }
next: { label: 'Subscriptions', href: './subscriptions.md' }
---

# Method calls

OPC UA method nodes are functions exposed by the server. They
accept typed input arguments, return typed output arguments, and
run atomically server-side. The right tool for any operation
where a single `write()` is insufficient — recipe loads, batch
acknowledgements, lifecycle transitions.

## Anatomy of a call

A method call needs:

1. The **object** — the node on which the method is invoked.
2. The **method** — the method node itself.
3. **Input arguments** — values matching the method's
   `InputArguments` definition.

The result is:

- A **call status** — overall success/failure.
- An array of **output arguments** — typed values matching the
  `OutputArguments` definition.

## Basic call

The method is `call()` (not `callMethod()`) and it returns a
**`CallResult` object** — not a `[status, outputs]` tuple.

<!-- @code-block language="php" label="basic call" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\StatusCode;

$result = Opcua::call(
    objectId:        'ns=2;s=Recipe',
    methodId:        'ns=2;s=Recipe.Load',
    inputArguments:  ['NewRecipeName', 42],
);

if (! StatusCode::isGood($result->statusCode)) {
    throw new RuntimeException(
        'Recipe load failed: ' . StatusCode::getName($result->statusCode),
    );
}

[$success, $message] = $result->outputArguments;
```
<!-- @endcode-block -->

`CallResult` has at least two fields:

| Field               | Type           | Meaning                                            |
| ------------------- | -------------- | -------------------------------------------------- |
| `statusCode`        | `int`          | OPC UA status (0 = good)                           |
| `outputArguments`   | `array`        | Per-position output values                          |

## Typed inputs (when auto-detection misses)

`call()` accepts a flat array of input arguments. When you need
explicit OPC UA types, wrap the value in a `Variant`:

<!-- @code-block language="php" label="typed inputs" -->
```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

$result = Opcua::call(
    objectId:       'ns=2;s=Recipe',
    methodId:       'ns=2;s=Recipe.Load',
    inputArguments: [
        new Variant('NewRecipeName', BuiltinType::String),
        new Variant(42, BuiltinType::UInt32),
    ],
);
```
<!-- @endcode-block -->

There is no `callBuilder()` on the facade or manager.

## Discovering input/output signatures

A method node carries two property nodes: `InputArguments` and
`OutputArguments`. Read them to learn the expected shape:

<!-- @code-block language="php" label="discover signature" -->
```php
$inputs = Opcua::read('ns=2;s=Recipe.Load.InputArguments')->getValue();
// $inputs => array of Argument
foreach ($inputs as $arg) {
    echo "{$arg->name} : {$arg->dataType} ({$arg->description})\n";
}
```
<!-- @endcode-block -->

A typical output:

```text
RecipeName : String (The recipe to load)
TargetLine : UInt32 (Production line index)
```

Always read the signature when integrating a new method —
servers vary widely in argument naming and ordering.

## Batched acknowledge — alarm UX

A common UI need: acknowledge many alarms at once. OPC UA exposes
this as a method call:

<!-- @code-block language="php" label="alarm ack" -->
```php
class AlarmAcknowledgeService
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function acknowledgeMany(array $eventIds, string $comment): bool
    {
        // OPC UA Conditions::Acknowledge takes (EventId, Comment)
        $failed = [];
        foreach ($eventIds as $eventId) {
            $result = $this->opcua->call(
                objectId:        'ns=0;i=2782',  // ConditionType
                methodId:        'ns=0;i=9111',  // Acknowledge
                inputArguments:  [
                    new Variant($eventId, BuiltinType::ByteString),
                    ['locale' => 'en', 'text' => $comment],
                ],
            );

            if (! StatusCode::isGood($result->statusCode)) {
                $failed[] = bin2hex($eventId);
            }
        }

        if (!empty($failed)) {
            throw new RuntimeException("Failed to ack: " . implode(', ', $failed));
        }

        return true;
    }
}
```
<!-- @endcode-block -->

See [Recipes · Alarm routing](../recipes/alarm-routing.md) for
the full pattern with event delivery.

## When status != 0

| Status code             | Likely cause                                              |
| ----------------------- | --------------------------------------------------------- |
| `BadNodeIdInvalid`      | Wrong method node ID                                      |
| `BadMethodInvalid`      | Method exists but is invalid for the calling context      |
| `BadArgumentsMissing`   | Fewer inputs than the method expects                      |
| `BadTypeMismatch`       | An input's type doesn't match `InputArguments`            |
| `BadOutOfRange`         | An input is type-correct but value-out-of-range           |
| `BadUserAccessDenied`   | Session lacks permission                                  |
| `BadNotExecutable`      | The method's `Executable` attribute is `false`            |

If the overall service call fails the client raises
`ServiceException` (call `StatusCode::getName($e->getStatusCode())`
to get the name). Per-call statuses ride on `CallResult::$statusCode`.

## Read first, then call

For methods with state-dependent behaviour (e.g. "load recipe X
only when the line is in Standby"), read the state first:

<!-- @code-block language="php" label="state-gated call" -->
```php
$state = Opcua::read('ns=2;s=Line.State')->getValue();
if ($state !== 'Standby') {
    throw new InvalidStateException(
        "Can't load recipe while line is $state"
    );
}

Opcua::call('ns=2;s=Recipe', 'ns=2;s=Recipe.Load', ['NewRecipe']);
```
<!-- @endcode-block -->

A read-then-call pair is **not atomic**. The state could change
between the read and the call — that's a race condition you
accept (or mitigate with a session lock at the application layer).

## Concurrency at the device

Many PLCs serialise method execution at the device level. Two
concurrent `Recipe.Load` calls don't run in parallel — the second
waits, or the second returns `Bad_AlreadyExists` / `Bad_ResourceBusy`.

Test the device's actual behaviour:

<!-- @code-block language="bash" label="terminal" -->
```bash
# Open two terminals, fire the same call simultaneously
php artisan tinker
> Opcua::call('ns=2;s=Recipe', 'ns=2;s=Recipe.Load', ['X']);
```
<!-- @endcode-block -->

Observe which terminal succeeds first, what error the loser sees.
Decide on a mutex strategy (queue serialisation, Redis lock)
based on the actual device behaviour.

## In events / queued jobs

Method calls are often dispatched from a queued job to ensure
exactly-once semantics through Horizon retries:

<!-- @code-block language="php" label="queued method call" -->
```php
class LoadRecipe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;  // method calls are NOT idempotent

    public function __construct(public string $recipeName) {}

    public function handle(OpcuaManager $opcua): void
    {
        $result = $opcua->call(
            'ns=2;s=Recipe',
            'ns=2;s=Recipe.Load',
            [$this->recipeName],
        );

        if (! StatusCode::isGood($result->statusCode)) {
            throw new RuntimeException(
                'Recipe load failed: ' . StatusCode::getName($result->statusCode),
            );
        }
    }
}
```
<!-- @endcode-block -->

`$tries = 1`. Retrying a method call on failure is wrong by
default — if the call ran and rejected, retrying makes the
operator's view inconsistent.

## Browsing for methods

To discover the methods on a node, browse with a `NodeClass`
filter — `browse()` returns `ReferenceDescription[]`:

<!-- @code-block language="php" label="browse methods" -->
```php
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\NodeClass;

$refs = Opcua::browse(
    'ns=2;s=Recipe',
    BrowseDirection::Forward,
    null,
    true,
    [NodeClass::Method],
);

foreach ($refs as $ref) {
    echo $ref->browseName->name . "\n";
}
```
<!-- @endcode-block -->

For each method, read `InputArguments` and `OutputArguments` to
build a complete signature map.

## Async / scheduled execution

OPC UA methods are synchronous from the spec's perspective.
There's no protocol-level fire-and-forget. If a method takes
long, dispatch the call to a queued job and notify on completion
via [broadcasting](../integrations/broadcasting.md).

## Where to read next

- [Subscriptions](./subscriptions.md) — react to method-induced
  state changes.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) — full
  alarm-ack pipeline with method calls.
