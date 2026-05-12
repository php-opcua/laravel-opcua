---
eyebrow: 'Docs · Using the client'
lede:    'Fluent builders for read, write, browse, and monitored items. You get them by calling readMulti() / writeMulti() / createMonitoredItems() / translateBrowsePaths() with no arguments. Same surface as opcua-client — through the Laravel facade.'

see_also:
  - { href: '../operations/reading.md',     meta: '6 min' }
  - { href: '../operations/writing.md',     meta: '5 min' }
  - { href: '../operations/browsing.md',    meta: '5 min' }
  - { href: '../operations/subscriptions.md', meta: '7 min' }

prev: { label: 'Connection lifecycle', href: './connection-lifecycle.md' }
next: { label: 'Reading',              href: '../operations/reading.md' }
---

# Using builders

`opcua-client` exposes fluent builders for the batched operations.
The convention is the same on every batch method: **call it with
no arguments to get the builder, call it with an array to run
immediately**.

| Builder                   | Got from                                  | Finaliser   | Returns      |
| ------------------------- | ----------------------------------------- | ----------- | ------------ |
| `ReadMultiBuilder`        | `Opcua::readMulti()`                      | `execute()` | `DataValue[]`|
| `WriteMultiBuilder`       | `Opcua::writeMulti()`                     | `execute()` | `int[]`      |
| `MonitoredItemsBuilder`   | `Opcua::createMonitoredItems($subId)`     | `execute()` | `MonitoredItemResult[]` |
| `BrowsePathsBuilder`      | `Opcua::translateBrowsePaths()`           | `execute()` | `BrowsePathResult[]`     |

There is **no** `readBuilder()` / `writeBuilder()` / `browseBuilder()`
/ `callBuilder()` / `historyBuilder()` on the facade. The one-shot
methods (`read`, `write`, `browse`, `call`, `historyRead*`) take
their own positional arguments and don't return a builder.

## Read — one-shot vs builder

<!-- @code-block language="php" label="one-shot read" -->
```php
$dv = Opcua::read('ns=2;s=Speed');                     // Value
$dv = Opcua::read('ns=2;s=Speed', AttributeId::DisplayName);  // any attribute
```
<!-- @endcode-block -->

Batched read via `readMulti()`:

<!-- @code-block language="php" label="builder — batch read" -->
```php
$values = Opcua::readMulti()
    ->node('ns=2;s=Speed')
    ->node('ns=2;s=Temperature')
    ->node('ns=2;s=Pressure')
    ->execute();

// $values is array<int, DataValue>, indexed in the order added
```
<!-- @endcode-block -->

`execute()` always returns an array, even for one entry.

## Write — one-shot vs builder

<!-- @code-block language="php" label="one-shot write" -->
```php
$status = Opcua::write('ns=2;s=Setpoint', 75.0);
// Or with an explicit BuiltinType:
$status = Opcua::write('ns=2;s=Setpoint', 75.0, BuiltinType::Float);
```
<!-- @endcode-block -->

Batched write via `writeMulti()`. Each item is a `node()` followed
by either `value()` (auto-detect) or `typed()` (explicit
`BuiltinType`):

<!-- @code-block language="php" label="builder — batch write" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

$statuses = Opcua::writeMulti()
    ->node('ns=2;s=Setpoint')->value(75.0)
    ->node('ns=2;s=Mode')->value('Auto')
    ->node('ns=2;s=EnableAlarm')->typed(true, BuiltinType::Boolean)
    ->execute();
```
<!-- @endcode-block -->

See [Operations · Writing](../operations/writing.md) for the type
detection rules.

## Browse

`browse()` and `browseRecursive()` are positional — no fluent
builder. Use the positional `BrowseDirection`, `referenceTypeId`,
`includeSubtypes`, and `nodeClasses` arguments to filter:

<!-- @code-block language="php" label="filtered browse" -->
```php
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;

$results = Opcua::browse(
    nodeId:          'ns=2;s=Folder',
    direction:       BrowseDirection::Forward,
    referenceTypeId: NodeId::numeric(0, 35),       // Organizes
    includeSubtypes: true,
    nodeClasses:     [NodeClass::Variable],
);
```
<!-- @endcode-block -->

Recursive browse — useful for tag discovery (`$maxDepth` is the
**third** positional argument, so use the named form):

<!-- @code-block language="php" label="browseRecursive" -->
```php
$tree = Opcua::browseRecursive('ns=4;s=Tags', maxDepth: 5);
```
<!-- @endcode-block -->

See [Operations · Browsing](../operations/browsing.md).

## Translate browse paths

<!-- @code-block language="php" label="translate browse paths" -->
```php
$results = Opcua::translateBrowsePaths()
    ->add(startingNodeId: 'ns=2;s=Folder', browsePath: '/Speed')
    ->add(startingNodeId: 'ns=2;s=Folder', browsePath: '/Temperature')
    ->execute();
```
<!-- @endcode-block -->

## Subscriptions / monitored items

Subscriptions are created with `createSubscription()`; monitored
items are attached with `createMonitoredItems()` (which returns the
`MonitoredItemsBuilder` when called with no `$items` array):

<!-- @code-block language="php" label="subscription + monitored items" -->
```php
$sub = Opcua::createSubscription(publishingInterval: 500.0);

Opcua::createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Speed',       clientHandle: 1)
    ->add('ns=2;s=Temperature', clientHandle: 2)
    ->execute();
```
<!-- @endcode-block -->

Listen via `Event::listen(DataChangeReceived::class, …)` for
notifications. See [Operations · Subscriptions](../operations/subscriptions.md)
and [Events · Data events](../events/data-events.md).

## Method calls

`call()` takes its arguments positionally — no `callBuilder()`:

<!-- @code-block language="php" label="method call" -->
```php
$result = Opcua::call(
    objectId:        'ns=2;s=Recipe',
    methodId:        'ns=2;s=Recipe.Load',
    inputArguments:  ['NewRecipe', 42],
);

// $result->statusCode + $result->outputArguments
```
<!-- @endcode-block -->

See [Operations · Method calls](../operations/method-calls.md).

## Chainable with `connection()`

Builders are rooted in a connection — call them on the result of
`Opcua::connection('name')`:

<!-- @code-block language="php" label="builder on named connection" -->
```php
$values = Opcua::connection('historian')
    ->readMulti()
    ->node('ns=4;s=Tag1')
    ->node('ns=4;s=Tag2')
    ->execute();
```
<!-- @endcode-block -->

The chain reads left-to-right: choose connection, open the
builder, add items, execute.

## Async / queued builders

There's no async builder. PHP OPC UA is synchronous. To run a read
off the request thread, dispatch a job:

<!-- @code-block language="php" label="job → builder" -->
```php
class SampleBatch implements ShouldQueue
{
    public function __construct(public array $nodeIds) {}

    public function handle(OpcuaManager $opcua): void
    {
        $b = $opcua->readMulti();
        foreach ($this->nodeIds as $node) {
            $b->node($node);
        }
        $values = $b->execute();
        // ... persist
    }
}
```
<!-- @endcode-block -->

See [Horizon and queues](../integrations/horizon-and-queues.md).

## When to skip the builder

If the call is one node, one value, one attribute — use the
shortcut form. The builder is for batching.

| Operation                  | Shortcut             | Use builder when…                       |
| -------------------------- | -------------------- | --------------------------------------- |
| Read one Value             | `Opcua::read()`      | Batching multiple nodes                 |
| Write one Value            | `Opcua::write()`     | Batching multiple writes                |
| Browse one folder          | `Opcua::browse()`    | No builder — use positional filters     |
| Method call                | `Opcua::call()`      | No builder                              |
| History read               | `Opcua::historyReadRaw()` / etc. | No builder — three flat methods |

## Reference

The builder classes are documented in
[`opcua-client`'s reference docs](https://github.com/php-opcua/opcua-client/blob/master/docs/reference/builder-api.md).
The Laravel package adds nothing on top — the chain you call
through `Opcua::readMulti()` (etc.) is the same one documented
upstream.

## Where to read next

You've finished **Using the client**. Move on to
[Operations](../operations/reading.md) for the per-operation deep
dives.
