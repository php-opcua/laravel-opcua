---
eyebrow: 'Docs · Operations'
lede:    'Walking the OPC UA address space. The flat browse, the recursive browse, filtering by reference type and node class — and pragmatic patterns for tag discovery in Laravel apps.'

see_also:
  - { href: './reading.md',                       meta: '6 min' }
  - { href: '../recipes/persistent-tag-history.md', meta: '6 min' }

prev: { label: 'Writing',  href: './writing.md' }
next: { label: 'Method calls', href: './method-calls.md' }
---

# Browsing

OPC UA models the device as a graph: nodes connected by typed
references. Browsing is how you discover what's available.

## Flat browse

<!-- @code-block language="php" label="basic browse" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$results = Opcua::browse('ns=0;i=85');  // Objects folder
foreach ($results as $ref) {
    echo "{$ref->browseName->name} → {$ref->nodeId}\n";
}
```
<!-- @endcode-block -->

Each result is a `ReferenceDescription`:

| Field            | Type                | Meaning                                          |
| ---------------- | ------------------- | ------------------------------------------------ |
| `nodeId`         | `NodeId`            | OPC UA node ID of the target                     |
| `browseName`     | `QualifiedName`     | Programmatic name (machine-readable)             |
| `displayName`    | `LocalizedText`     | Human-readable label                             |
| `nodeClass`      | `NodeClass` enum    | Object / Variable / Method / View / …            |
| `referenceType`  | `NodeId`            | The reference that links source → target         |
| `typeDefinition` | `?NodeId`           | Type of the target (`BaseDataVariableType`, …)   |
| `isForward`      | `bool`              | True for normal references, false for inverse    |

## Recursive browse

For discovering an entire subtree. The real signature is
`browseRecursive(NodeId|string, BrowseDirection = Forward, ?int $maxDepth = null, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, NodeClass[] $nodeClasses = [])`
— `$maxDepth` is the **third** positional argument. Use the named
argument to skip past `$direction`:

<!-- @code-block language="php" label="recursive browse" -->
```php
$tree = Opcua::browseRecursive('ns=4;s=Tags', maxDepth: 5);

// $tree is BrowseNode[] — each entry has $depth, $reference (the ReferenceDescription),
// and a children-array maintained by the walker.
foreach ($tree as $entry) {
    echo str_repeat('  ', $entry->depth) . $entry->reference->browseName->name . "\n";
}
```
<!-- @endcode-block -->

`maxDepth` caps recursion. The default (when null) falls back to the
client's `defaultBrowseMaxDepth` (10 unless configured otherwise).

## Filtered browse

Use the positional arguments on `browse()`:

<!-- @code-block language="php" label="filter by reference and class" -->
```php
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;

$variables = Opcua::browse(
    nodeId:          'ns=2;s=Folder',
    direction:       BrowseDirection::Forward,
    referenceTypeId: NodeId::numeric(0, 35),   // Organizes
    includeSubtypes: true,
    nodeClasses:     [NodeClass::Variable],
);
```
<!-- @endcode-block -->

Common reference type NodeIds (namespace 0):

| Reference type        | NodeId  | Meaning                                              |
| --------------------- | ------- | ---------------------------------------------------- |
| `Organizes`           | `i=35`  | Folder ↔ children                                    |
| `HasComponent`        | `i=47`  | Composition (object has parts)                       |
| `HasProperty`         | `i=46`  | Variable property attached to a node                 |
| `HasTypeDefinition`   | `i=40`  | Instance ↔ its type                                  |
| `HasSubtype`          | `i=45`  | Type hierarchy                                       |

For most user-facing tag browsing, `Organizes` + `[Variable]` gives
the cleanest list.

## Discovery pattern — fill an Eloquent table

Browse-on-deploy, persist to a `plc_tags` table the app can read
locally:

<!-- @code-block language="php" label="Artisan command — discover tags" -->
```php
class DiscoverPlcTags extends Command
{
    protected $signature = 'plc:discover {connection=default} {root=ns=4;s=Tags}';

    public function handle(OpcuaManager $opcua): int
    {
        $tree = $opcua->connection($this->argument('connection'))
            ->browseRecursive($this->argument('root'), maxDepth: 10);

        $this->info("Found " . count($tree) . " nodes");

        foreach ($tree as $entry) {
            $ref = $entry->reference;
            if ($ref->nodeClass !== NodeClass::Variable) {
                continue;
            }
            PlcTag::updateOrCreate(
                ['node_id' => (string) $ref->nodeId],
                [
                    'browse_name'   => $ref->browseName->name,
                    'display_name'  => $ref->displayName->text,
                    'depth'         => $entry->depth,
                    'last_seen_at'  => now(),
                ],
            );
        }

        return self::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule it daily — tag discovery rarely needs to be real-time,
and a daily refresh catches additions without hammering the PLC.

<!-- @code-block language="php" label="app/Console/Kernel.php" -->
```php
$schedule->command('plc:discover')->dailyAt('03:00');
```
<!-- @endcode-block -->

## Listing only writable nodes

A common UI need — show the operator which tags are writable:

<!-- @code-block language="php" label="discover writable" -->
```php
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\NodeClass;

$variables = Opcua::browse(
    'ns=2;s=Folder',
    BrowseDirection::Forward,
    null,
    true,
    [NodeClass::Variable],
);

$writable = [];
foreach ($variables as $ref) {
    $access = Opcua::read($ref->nodeId, AttributeId::AccessLevel);

    if ((((int) $access->getValue()) & 0b10) !== 0) {  // CurrentWrite bit
        $writable[] = $ref;
    }
}
```
<!-- @endcode-block -->

The `AccessLevel` byte's bits:

| Bit | Name                |
| --- | ------------------- |
| 0   | CurrentRead         |
| 1   | CurrentWrite        |
| 2   | HistoryRead         |
| 3   | HistoryWrite        |
| 4   | SemanticChange      |
| 5   | StatusWrite         |
| 6   | TimestampWrite      |

## Reverse browse

Walk **upward** — from a node to its parents — by passing
`BrowseDirection::Inverse`:

<!-- @code-block language="php" label="reverse browse" -->
```php
use PhpOpcua\Client\Types\BrowseDirection;

$parents = Opcua::browse('ns=2;s=Speed', BrowseDirection::Inverse);
```
<!-- @endcode-block -->

Useful for breadcrumbs in operator UIs.

## Translate browse paths

Sometimes you have a browse path and need the node ID. The real
method is `translateBrowsePaths()` (plural, takes a batch):

<!-- @code-block language="php" label="translate" -->
```php
$results = Opcua::translateBrowsePaths()
    ->add(startingNodeId: 'ns=2;s=Folder', browsePath: '/Subfolder/Speed')
    ->execute();

$nodeId = $results[0]->targetId;
```
<!-- @endcode-block -->

For a single resolution there is also a convenience helper:

```php
$nodeId = Opcua::resolveNodeId('/Subfolder/Speed', startingNodeId: 'ns=2;s=Folder');
```

This is server-side resolution — pass the browse path, the server
returns the matching node ID.

## Performance

Browse is comparatively cheap but not free:

| Scope                        | Round-trip count            |
| ---------------------------- | --------------------------- |
| Flat browse, ~10 children     | 1                           |
| Filtered browse, ~10 children | 1                           |
| Recursive browse, ~100 nodes  | ~5-10 (chunks `MaxNodesPerBrowse`) |
| Whole address space            | Don't do this from a request — schedule it |

Don't browse on every request. Discovery is an offline / periodic
activity; the result lives in your application's storage.

## Caching browse results

If you must browse live (e.g. operator-driven exploration of an
unknown server), cache aggressively:

<!-- @code-block language="php" label="cached browse" -->
```php
$results = Cache::remember(
    "opcua-browse:{$nodeId}",
    minutes: 60,
    callback: fn() => Opcua::browse($nodeId),
);
```
<!-- @endcode-block -->

The PLC's tag structure changes on the order of weeks — cache
for hours, not seconds.

## Browsing via companion-spec types

If you've loaded `opcua-client-nodeset` ([see the recipe](../recipes/using-companion-specs.md))
the type-aware browse APIs become available. Browsing a `MachineToolType`
instance gives you typed access to `Notification`, `Production`,
`Equipment` — no string-fiddling.

## Where to read next

- [Method calls](./method-calls.md) — invoking server-side methods
  on browsed objects.
- [Recipes · Using companion specs](../recipes/using-companion-specs.md) —
  type-aware browse with nodeset support.
