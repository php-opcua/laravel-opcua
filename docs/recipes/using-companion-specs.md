---
eyebrow: 'Docs · Recipes'
lede:    'Loading OPC UA companion specifications via opcua-client-nodeset to get type-aware browsing and named accessors. The Laravel-side wiring for MachineTool, PackML, and DI specs.'

see_also:
  - { href: 'https://github.com/php-opcua/opcua-client-nodeset', meta: 'external', label: 'opcua-client-nodeset' }
  - { href: '../operations/browsing.md',                         meta: '5 min' }

prev: { label: 'Multi-plant tenant',  href: './multi-plant-tenant.md' }
next: { label: 'Dev with Sail',       href: './dev-with-sail.md' }
---

# Using companion specs

OPC UA companion specifications define **typed** node hierarchies
for specific industries: MachineTool, PackML, Robotics, DI
(Device Information). The `opcua-client-nodeset` package gives
type-aware access to these — and the Laravel package picks them
up automatically.

## Install

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-client-nodeset
```
<!-- @endcode-block -->

The package's discovery mechanism auto-registers nodesets from
`vendor/php-opcua/opcua-client-nodeset/nodesets/`. No additional
config.

## What you get

Without companion specs:

<!-- @code-block language="php" label="raw browse" -->
```php
$nodes = Opcua::browseRecursive('ns=4;s=MachineTool', maxDepth: 5);
// returns: array of ReferenceDescription — generic
```
<!-- @endcode-block -->

With companion specs:

<!-- @code-block language="php" label="typed browse" -->
```php
use PhpOpcua\Client\Nodeset\MachineTool\MachineToolType;

$machine = Opcua::nodeset(MachineToolType::class, 'ns=4;s=MachineTool');

// Strongly-typed access
$alarms      = $machine->getAlarms();          // array of MachineToolAlarm
$production  = $machine->getProduction();      // ProductionType
$equipment   = $machine->getEquipment();       // ToolListType

// No string-fiddling, no walking the address space
```
<!-- @endcode-block -->

The PHP classes correspond to the spec's defined ObjectType
hierarchy.

## Available companion specs

The `opcua-client-nodeset` package bundles:

| Companion spec | PHP namespace                                  | Use case                          |
| -------------- | ---------------------------------------------- | --------------------------------- |
| DI             | `PhpOpcua\Client\Nodeset\Di\`                  | Device information, generic       |
| MachineTool    | `PhpOpcua\Client\Nodeset\MachineTool\`         | CNCs, lathes, mills               |
| Robotics       | `PhpOpcua\Client\Nodeset\Robotics\`            | Industrial robots                 |
| PackML         | `PhpOpcua\Client\Nodeset\PackML\`              | Packaging machinery               |
| Machinery      | `PhpOpcua\Client\Nodeset\Machinery\`           | Generic industrial machinery      |

Plus several more — see the
[opcua-client-nodeset readme](https://github.com/php-opcua/opcua-client-nodeset).

## End-to-end — production monitor for a MachineTool

The OPC UA MachineTool spec defines a `Production` object with
`ActiveProgram`, `ActiveTool`, `OperationMode` properties. A
Laravel-side monitor:

<!-- @code-block language="php" label="model" -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineToolReading extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['read_at' => 'datetime'];
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="migration" -->
```php
Schema::create('machine_tool_readings', function (Blueprint $table) {
    $table->id();
    $table->string('machine_id');
    $table->string('active_program')->nullable();
    $table->string('active_tool')->nullable();
    $table->string('operation_mode')->nullable();
    $table->integer('part_count')->nullable();
    $table->timestamp('read_at');
});
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="poll command" -->
```php
namespace App\Console\Commands;

use App\Models\MachineToolReading;
use Illuminate\Console\Command;
use PhpOpcua\Client\Nodeset\MachineTool\MachineToolType;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class PollMachineTool extends Command
{
    protected $signature = 'machine:poll {machine-id : The MachineTool root node}';

    public function handle(): int
    {
        $machineNodeId = $this->argument('machine-id');

        $machine = Opcua::nodeset(MachineToolType::class, $machineNodeId);

        $production = $machine->getProduction();
        $reading = MachineToolReading::create([
            'machine_id'     => $machineNodeId,
            'active_program' => $production->getActiveProgram()?->getName()->value,
            'active_tool'    => $production->getActiveTool()?->getName()->value,
            'operation_mode' => $production->getOperationMode()->value,
            'part_count'     => (int) $production->getPartCount()->value,
            'read_at'        => now(),
        ]);

        $this->table(['Field', 'Value'], collect($reading->toArray())->map(fn ($v, $k) => [$k, (string) $v])->all());

        return self::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Run every minute via the scheduler:

<!-- @code-block language="php" label="schedule" -->
```php
$schedule->command('machine:poll', ['ns=4;s=MachineA'])->everyMinute();
```
<!-- @endcode-block -->

## Type discovery

To see what methods are available on a typed node:

<!-- @code-block language="bash" label="tinker" -->
```bash
php artisan tinker
> get_class_methods(\PhpOpcua\Client\Nodeset\MachineTool\MachineToolType::class);
```
<!-- @endcode-block -->

…or just look at the class — `opcua-client-nodeset` generates
classes with docblocks listing every typed property.

## When the typed accessor returns null

A `null` from `getActiveProgram()` means the device doesn't
populate that node. Two reasons:

1. **The device doesn't support that part of the spec.** Common.
2. **The node is currently null** (active program might be null
   between jobs).

Always null-check. The PHP types help — typed accessors return
`?T` for nullable nodes.

## Working with alarm types

The MachineTool spec defines alarm types:

<!-- @code-block language="php" label="typed alarms" -->
```php
use PhpOpcua\Client\Nodeset\MachineTool\MachineToolAlarm;

$alarms = $machine->getAlarms();

foreach ($alarms as $alarm) {
    if ($alarm instanceof \PhpOpcua\Client\Nodeset\MachineTool\AxisAlarm) {
        // Strongly-typed access to axis-specific fields
        echo "Axis {$alarm->getAxisId()->value}: {$alarm->getMessage()->value}\n";
    }
}
```
<!-- @endcode-block -->

Type narrowing with `instanceof` lets you handle subtypes
specifically.

## Subscribing to typed events

The subscription side uses `createSubscription()` +
`createEventMonitoredItem()` directly (see
[Operations · Subscriptions](../operations/subscriptions.md)); the
listener can use type-aware decoding on the
`EventNotificationReceived::$eventFields` array:

<!-- @code-block language="php" label="typed event listener" -->
```php
use PhpOpcua\Client\Event\EventNotificationReceived;

class HandleMachineToolAlarm implements ShouldQueue
{
    public function handle(EventNotificationReceived $event): void
    {
        $f = $event->eventFields;
        if (empty($f['EventType'])) return;

        $alarm = \PhpOpcua\Client\Nodeset\MachineTool\AlarmDecoder::decode($f);

        if ($alarm instanceof \PhpOpcua\Client\Nodeset\MachineTool\AxisAlarm) {
            \App\Models\AxisAlarm::create([
                'client_handle' => $event->clientHandle,
                'axis_id'       => $alarm->axisId,
                'message'       => $alarm->message,
                'severity'      => $f['Severity'] ?? null,
            ]);
        }
    }
}
```
<!-- @endcode-block -->

`AlarmDecoder::decode()` is a `opcua-client-nodeset` helper that
maps the raw event-fields array to a typed class.

## Custom companion specs

For internal / proprietary companion specs (most plants have
some), define your own types:

<!-- @code-block language="php" label="custom type" -->
```php
namespace App\Opcua\Nodeset\Acme;

use PhpOpcua\Client\Nodeset\BaseNodesetType;

class AcmeReactorType extends BaseNodesetType
{
    public function getTemperature(): ?\PhpOpcua\Client\Types\DataValue
    {
        return $this->readChild('Temperature');
    }

    public function getPressure(): ?\PhpOpcua\Client\Types\DataValue
    {
        return $this->readChild('Pressure');
    }

    public function getState(): string
    {
        return (string) $this->readChild('State')->value;
    }
}
```
<!-- @endcode-block -->

Use it identically:

<!-- @code-block language="php" label="usage" -->
```php
$reactor = Opcua::nodeset(\App\Opcua\Nodeset\Acme\AcmeReactorType::class, 'ns=2;s=Reactor1');

echo $reactor->getTemperature()->value;
```
<!-- @endcode-block -->

## The trade-off

| Approach          | Pros                                            | Cons                                                    |
| ----------------- | ----------------------------------------------- | ------------------------------------------------------- |
| Raw browse / read | Universal — works against any OPC UA server     | String-fiddling, no type safety                          |
| Companion specs   | Type-safe, IDE auto-complete, idiomatic         | Only works against spec-conformant servers              |

If your servers conform to the spec (Siemens, Beckhoff, Rockwell
all do for their respective specs), companion specs are
dramatically nicer. If your servers are bespoke, raw is fine.

## Performance

A typed accessor reads the underlying node lazily. `$machine->getProduction()`
makes one round-trip; `$production->getActiveProgram()` makes
another. For multi-property reads, the typed API hides batching
— internally, the package uses `executeMany()` where possible.

To force batch behaviour for a known set of properties:

<!-- @code-block language="php" label="batch typed read" -->
```php
$snapshot = $machine->snapshot([
    'production.active_program',
    'production.active_tool',
    'production.operation_mode',
    'production.part_count',
]);
// $snapshot is an array of resolved values — one round-trip
```
<!-- @endcode-block -->

See the [opcua-client-nodeset README](https://github.com/php-opcua/opcua-client-nodeset)
for the full snapshot API.

## Where to read next

- [opcua-client-nodeset documentation](https://github.com/php-opcua/opcua-client-nodeset) —
  the canonical reference for the typed surface.
- [Production deployment](./production-deployment.md) — putting
  everything together.
