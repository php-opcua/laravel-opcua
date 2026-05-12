---
eyebrow: 'Docs · Integrations'
lede:    'Real-time OPC UA UI with Livewire 3 — server-rendered, event-driven, zero hand-rolled JS. End-to-end example of a tag monitor component with setpoint control.'

see_also:
  - { href: './broadcasting.md',                              meta: '6 min' }
  - { href: '../recipes/livewire-realtime-dashboard.md',     meta: '7 min' }
  - { href: '../events/data-events.md',                      meta: '6 min' }

prev: { label: 'Broadcasting',     href: './broadcasting.md' }
next: { label: 'Notifications',    href: './notifications.md' }
---

# Livewire

Livewire 3 turns Laravel into a real-time UI framework without
hand-written JS. The package itself does not ship any Livewire
components — this page is the **pattern** for combining Livewire
components with the OPC UA facade and the broadcasting bridge from
the [Broadcasting](./broadcasting.md) page.

## What you need

- Livewire 3 (`composer require livewire/livewire`)
- Broadcasting set up — see [Broadcasting](./broadcasting.md)
- Reverb running (or Pusher configured)

## A tag-monitor component

<!-- @code-block language="php" label="app/Livewire/TagMonitor.php" -->
```php
namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class TagMonitor extends Component
{
    public string $nodeId;
    public mixed $value = null;
    public bool $good = false;
    public ?string $updatedAt = null;

    public function mount(string $nodeId): void
    {
        $this->nodeId = $nodeId;
        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $dv = Opcua::read($this->nodeId);
            $this->value     = $dv->getValue();
            $this->good      = $dv->statusCode === 0;
            $this->updatedAt = $dv->sourceTimestamp?->format('H:i:s');
        } catch (\Throwable $e) {
            $this->good = false;
        }
    }

    public function getListeners(): array
    {
        // Subscribe to the broadcast channel for this specific tag
        return [
            "echo:plc.tag.{$this->nodeId},App\\Events\\TagUpdated" => 'onTagUpdated',
        ];
    }

    public function onTagUpdated(array $payload): void
    {
        $this->value     = $payload['value'];
        $this->good      = $payload['good'];
        $this->updatedAt = isset($payload['source_at'])
            ? \Carbon\Carbon::parse($payload['source_at'])->format('H:i:s')
            : now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.tag-monitor');
    }
}
```
<!-- @endcode-block -->

The view:

<!-- @code-block language="text" label="resources/views/livewire/tag-monitor.blade.php" -->
```text
<div class="rounded-lg border p-4 shadow-sm">
    <div class="flex justify-between items-baseline mb-2">
        <h3 class="text-sm font-semibold text-gray-600">{{ $nodeId }}</h3>
        <span class="text-xs text-gray-400">@if($updatedAt) {{ $updatedAt }} @endif</span>
    </div>

    <div @class([
        'text-3xl font-bold',
        'text-green-600' => $good,
        'text-red-500'   => ! $good,
    ])>
        {{ $value !== null ? (is_numeric($value) ? number_format($value, 2) : $value) : '—' }}
    </div>

    <button wire:click="refresh"
            class="mt-3 text-xs text-blue-500 hover:underline">
        Refresh
    </button>
</div>
```
<!-- @endcode-block -->

Use it:

<!-- @code-block language="text" label="resources/views/dashboard.blade.php" -->
```text
<div class="grid grid-cols-3 gap-4">
    <livewire:tag-monitor node-id="ns=2;s=Speed" />
    <livewire:tag-monitor node-id="ns=2;s=Temperature" />
    <livewire:tag-monitor node-id="ns=2;s=Pressure" />
</div>
```
<!-- @endcode-block -->

Without any JavaScript on your part, the values update in real
time. The `getListeners()` method subscribes to the per-tag
broadcast channel and routes incoming events to
`onTagUpdated()`.

## A setpoint control component

A write side — the operator changes a value, Livewire dispatches
the write, the UI confirms.

<!-- @code-block language="php" label="app/Livewire/SetpointControl.php" -->
```php
namespace App\Livewire;

use Livewire\Attributes\{Rule, Validate};
use Livewire\Component;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class SetpointControl extends Component
{
    public string $nodeId;
    public string $label;
    public mixed $currentValue = null;

    #[Validate('required|numeric|min:0|max:100')]
    public string $newValue = '';

    public function mount(string $nodeId, string $label): void
    {
        $this->nodeId = $nodeId;
        $this->label  = $label;
        $this->refresh();
    }

    public function refresh(): void
    {
        $dv = Opcua::read($this->nodeId);
        $this->currentValue = $dv->getValue();
        $this->newValue     = (string) $dv->getValue();
    }

    public function apply(): void
    {
        $this->validate();
        $this->authorize('write-setpoint', $this->nodeId);

        Opcua::write($this->nodeId, (float) $this->newValue);

        // Update the audit table
        \App\Models\SetpointAudit::create([
            'user_id'    => auth()->id(),
            'node_id'    => $this->nodeId,
            'value'      => $this->newValue,
            'applied_at' => now(),
        ]);

        $this->refresh();
        $this->dispatch('setpoint-applied', node: $this->nodeId);
    }

    public function render()
    {
        return view('livewire.setpoint-control');
    }
}
```
<!-- @endcode-block -->

The view:

<!-- @code-block language="text" label="resources/views/livewire/setpoint-control.blade.php" -->
```text
<form wire:submit="apply" class="rounded-lg border p-4">
    <label class="block text-sm font-medium text-gray-700 mb-1">
        {{ $label }}
    </label>

    <p class="text-xs text-gray-500 mb-2">
        Current: <span class="font-mono">{{ $currentValue }}</span>
    </p>

    <div class="flex gap-2">
        <input type="text"
               wire:model="newValue"
               class="flex-1 rounded border-gray-300 text-sm">
        <button type="submit"
                class="px-3 py-1 bg-blue-600 text-white rounded text-sm">
            Apply
        </button>
    </div>

    @error('newValue')
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror
</form>
```
<!-- @endcode-block -->

Live-updating, validated, authorised, audited. Roughly 80 lines
of Laravel for a complete operator UI.

## Polling fallback

When broadcasting isn't available (development without Reverb,
or a deployment where you don't want the socket), Livewire's
`wire:poll` works as a fallback:

<!-- @code-block language="text" label="polling version" -->
```text
<div wire:poll.2s="refresh" class="rounded-lg border p-4">
    {{-- same body as broadcast version --}}
</div>
```
<!-- @endcode-block -->

Polling every 2 seconds. Less elegant than broadcasting but
works everywhere.

## Optimistic updates

For setpoint controls, show the new value immediately, roll back
on failure:

<!-- @code-block language="php" label="optimistic apply" -->
```php
public function apply(): void
{
    $this->validate();

    $previous = $this->currentValue;
    $this->currentValue = (float) $this->newValue;   // optimistic

    try {
        Opcua::write($this->nodeId, (float) $this->newValue);
    } catch (\Throwable $e) {
        $this->currentValue = $previous;
        $this->addError('newValue', "Failed: {$e->getMessage()}");
        return;
    }

    $this->refresh();
}
```
<!-- @endcode-block -->

UI feels instant; failure cases are clearly signalled.

## Multi-tag dashboard pattern

A single Livewire component that holds many tags, listening on
the `plc.all` channel:

<!-- @code-block language="php" label="dashboard component" -->
```php
class PlcDashboard extends Component
{
    public array $tags = [];

    public array $tagDefs = [
        'ns=2;s=Speed'        => ['label' => 'Line Speed',    'unit' => 'm/min'],
        'ns=2;s=Temperature'  => ['label' => 'Temperature',   'unit' => '°C'],
        'ns=2;s=Pressure'     => ['label' => 'Pressure',      'unit' => 'bar'],
        'ns=2;s=Output'       => ['label' => 'Output',        'unit' => 'units/h'],
    ];

    public function mount(): void
    {
        $this->refreshAll();
    }

    public function refreshAll(): void
    {
        $builder = Opcua::readMulti();
        foreach (array_keys($this->tagDefs) as $node) {
            $builder->node($node);
        }
        $results = $builder->execute();

        foreach (array_keys($this->tagDefs) as $i => $node) {
            $this->tags[$node] = [
                'value'  => $results[$i]->getValue(),
                'good'   => $results[$i]->statusCode === 0,
                'at'     => $results[$i]->sourceTimestamp?->format('H:i:s'),
            ];
        }
    }

    public function getListeners(): array
    {
        return ['echo:plc.all,App\\Events\\TagUpdated' => 'onTagUpdated'];
    }

    public function onTagUpdated(array $payload): void
    {
        $node = $payload['node_id'];
        if (!isset($this->tagDefs[$node])) return;

        $this->tags[$node] = [
            'value'  => $payload['value'],
            'good'   => $payload['good'],
            'at'     => $payload['source_at'] ?? now()->format('H:i:s'),
        ];
    }

    public function render()
    {
        return view('livewire.plc-dashboard');
    }
}
```
<!-- @endcode-block -->

One round-trip on mount (`executeMany`), then live updates via
the broadcast channel. Scales to dozens of tags without
performance issues.

## Authorization

Livewire honours Laravel policies. For a setpoint control:

<!-- @code-block language="php" label="policy" -->
```php
// app/Policies/PlcPolicy.php
public function writeSetpoint(User $user, string $nodeId): bool
{
    if (! $user->hasRole('operator')) return false;

    // Per-line scoping
    if (str_starts_with($nodeId, 'ns=2;s=LineA.')) {
        return $user->canAccess('line-a');
    }

    return false;
}
```
<!-- @endcode-block -->

`$this->authorize('writeSetpoint', $this->nodeId)` in the component
throws on unauthorised.

## Loading states

<!-- @code-block language="text" label="loading indicator" -->
```text
<button wire:click="refresh" wire:loading.attr="disabled">
    <span wire:loading.remove>Refresh</span>
    <span wire:loading>Loading…</span>
</button>
```
<!-- @endcode-block -->

For OPC UA reads that take a few hundred ms (cold connections),
loading indicators are essential UX.

## Testing Livewire components

<!-- @code-block language="php" label="livewire test" -->
```php
use Livewire\Livewire;
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\DataValue;

it('shows the current speed', function () {
    Opcua::shouldReceive('read')
        ->with('ns=2;s=Speed')
        ->andReturn(DataValue::ofDouble(75.0));

    Livewire::test(TagMonitor::class, ['nodeId' => 'ns=2;s=Speed'])
        ->assertSee('75.00')
        ->assertSet('good', true);
});

it('refreshes on demand', function () {
    Opcua::shouldReceive('read')->andReturn(
        DataValue::ofDouble(70.0),
        DataValue::ofDouble(72.0),
    );

    Livewire::test(TagMonitor::class, ['nodeId' => 'ns=2;s=Speed'])
        ->assertSee('70.00')
        ->call('refresh')
        ->assertSee('72.00');
});
```
<!-- @endcode-block -->

## Where to read next

- [Recipes · Livewire real-time dashboard](../recipes/livewire-realtime-dashboard.md) —
  full plant overview with multiple components.
- [Filament](./filament.md) — Filament-specific patterns.
