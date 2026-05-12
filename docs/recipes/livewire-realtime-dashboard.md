---
eyebrow: 'Docs · Recipes'
lede:    'A full plant overview dashboard in Livewire 3: multiple tag tiles, alarm queue, line-state widgets, all updating in real time. The end-to-end build.'

see_also:
  - { href: '../integrations/livewire.md',       meta: '7 min' }
  - { href: '../integrations/broadcasting.md',   meta: '6 min' }
  - { href: './alarm-routing.md',                meta: '5 min' }

prev: { label: 'Alarm routing',     href: './alarm-routing.md' }
next: { label: 'Multi-plant tenant', href: './multi-plant-tenant.md' }
---

# Livewire real-time dashboard

A production-quality plant dashboard. Multiple tiles, alarm
queue, severity-coloured indicators — all updating live as the
PLC pushes data.

## What's in the build

- A `PlantDashboard` component with grid layout.
- `TagTile` for each monitored tag (Speed, Temperature, etc.).
- `AlarmBar` showing the top critical alarms.
- `LineState` showing the line's run mode.
- Broadcasting wired through Reverb.
- Authenticated, role-gated access.

## Prerequisites

- Broadcasting set up — see [Broadcasting](../integrations/broadcasting.md).
- Auto-publish enabled — see [Auto-publish](../session-manager/auto-publish.md).
- The cache-fill listener from
  [Persistent tag history](./persistent-tag-history.md) (or
  equivalent) running.

## Routes

<!-- @code-block language="php" label="routes/web.php" -->
```php
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', \App\Http\Controllers\DashboardController::class)
        ->name('dashboard');
});

Broadcast::channel('plc.live', fn ($user) => true);
Broadcast::channel('plc.alarms', fn ($user) => $user->hasRole('operator'));
```
<!-- @endcode-block -->

## The dashboard layout

<!-- @code-block language="text" label="resources/views/dashboard.blade.php" -->
```text
<x-app-layout>
    <x-slot name="header">Plant Overview</x-slot>

    <div class="p-6 space-y-4">
        <livewire:alarm-bar />

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 lg:col-span-8 grid grid-cols-3 gap-3">
                <livewire:tag-tile node-id="ns=2;s=Speed"        label="Speed"        unit="m/min" />
                <livewire:tag-tile node-id="ns=2;s=Temperature"  label="Temperature"  unit="°C"    />
                <livewire:tag-tile node-id="ns=2;s=Pressure"     label="Pressure"     unit="bar"   />
                <livewire:tag-tile node-id="ns=2;s=Output"       label="Output"       unit="u/h"   />
                <livewire:tag-tile node-id="ns=2;s=Quality"      label="Quality"      unit="%"     />
                <livewire:tag-tile node-id="ns=2;s=PowerKw"      label="Power"        unit="kW"    />
            </div>

            <div class="col-span-12 lg:col-span-4 space-y-3">
                <livewire:line-state />
                <livewire:throughput-chart />
            </div>
        </div>
    </div>
</x-app-layout>
```
<!-- @endcode-block -->

## TagTile component

<!-- @code-block language="php" label="app/Livewire/TagTile.php" -->
```php
namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class TagTile extends Component
{
    public string $nodeId;
    public string $label;
    public string $unit = '';

    public mixed $value = null;
    public bool $good = false;
    public ?string $at = null;

    public function mount(string $nodeId, string $label, string $unit = ''): void
    {
        $this->nodeId = $nodeId;
        $this->label  = $label;
        $this->unit   = $unit;
        $this->loadFromCache();
    }

    public function loadFromCache(): void
    {
        $cached = Cache::get("plc:latest:{$this->nodeId}");
        if ($cached) {
            $this->value = $cached['value'];
            $this->good  = ($cached['status'] ?? 1) === 0;
            $this->at    = isset($cached['at'])
                ? \Carbon\Carbon::parse($cached['at'])->format('H:i:s')
                : null;
            return;
        }

        // Cache miss — fall back to a live read
        try {
            $dv = Opcua::read($this->nodeId);
            $this->value = $dv->getValue();
            $this->good  = $dv->statusCode === 0;
            $this->at    = $dv->sourceTimestamp?->format('H:i:s');
        } catch (\Throwable) {
            $this->good = false;
        }
    }

    public function getListeners(): array
    {
        return [
            "echo:plc.live,App\\Events\\TagUpdated" => 'onTagUpdated',
        ];
    }

    public function onTagUpdated(array $payload): void
    {
        if (($payload['node_id'] ?? null) !== $this->nodeId) {
            return;     // not this tile
        }

        $this->value = $payload['value'];
        $this->good  = $payload['good'];
        $this->at    = isset($payload['source_at'])
            ? \Carbon\Carbon::parse($payload['source_at'])->format('H:i:s')
            : now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.tag-tile');
    }
}
```
<!-- @endcode-block -->

The view:

<!-- @code-block language="text" label="resources/views/livewire/tag-tile.blade.php" -->
```text
<div @class([
    'rounded-lg border p-4 shadow-sm bg-white',
    'border-red-300' => ! $good,
])>
    <div class="flex justify-between items-baseline">
        <h3 class="text-xs font-semibold uppercase text-gray-500">{{ $label }}</h3>
        <span class="text-xs text-gray-400">@if($at) {{ $at }} @endif</span>
    </div>

    <div @class([
        'mt-1 text-2xl font-bold',
        'text-gray-900' => $good,
        'text-red-600'  => ! $good,
    ])>
        @if($value === null)
            <span class="text-gray-300">—</span>
        @else
            {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}
            <span class="text-sm text-gray-400 font-normal ml-1">{{ $unit }}</span>
        @endif
    </div>

    @unless($good)
        <p class="text-xs text-red-500 mt-1">Bad reading</p>
    @endunless
</div>
```
<!-- @endcode-block -->

## AlarmBar component

<!-- @code-block language="php" label="app/Livewire/AlarmBar.php" -->
```php
namespace App\Livewire;

use App\Models\PlcAlarm;
use Livewire\Component;

class AlarmBar extends Component
{
    public function getListeners(): array
    {
        return [
            'echo-private:plc.alarms,App\\Events\\AlarmBroadcasted' => '$refresh',
        ];
    }

    public function render()
    {
        $top = PlcAlarm::active()->unacked()
            ->orderByDesc('severity')->orderByDesc('occurred_at')
            ->limit(5)->get();

        return view('livewire.alarm-bar', ['alarms' => $top]);
    }

    public function ack(int $id, string $comment = '')
    {
        $alarm = PlcAlarm::findOrFail($id);
        $this->authorize('ack', $alarm);

        \Http::withToken(auth()->user()->currentAccessToken()->plainTextToken)
            ->post(route('alarms.ack', $alarm->event_id), ['comment' => $comment]);

        $this->dispatch('alarm-acked', $alarm->event_id);
    }
}
```
<!-- @endcode-block -->

The view:

<!-- @code-block language="text" label="resources/views/livewire/alarm-bar.blade.php" -->
```text
@if($alarms->isEmpty())
    <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-2 text-sm text-green-800">
        ✓ No active alarms
    </div>
@else
    <div class="space-y-2">
        @foreach($alarms as $alarm)
            <div @class([
                'rounded-lg border px-4 py-2 flex items-center justify-between',
                'bg-red-50    border-red-300'    => $alarm->severity >= 900,
                'bg-amber-50  border-amber-300'  => $alarm->severity >= 700 && $alarm->severity < 900,
                'bg-blue-50   border-blue-300'   => $alarm->severity < 700,
            ])>
                <div>
                    <span class="text-xs font-semibold text-gray-500">
                        SEV {{ $alarm->severity }}
                    </span>
                    <span class="ml-2 font-medium">{{ $alarm->source_name }}</span>
                    <span class="ml-2 text-sm text-gray-700">{{ $alarm->message }}</span>
                </div>
                <button wire:click="ack({{ $alarm->id }})"
                        class="px-3 py-1 text-xs bg-white border rounded hover:bg-gray-50">
                    Acknowledge
                </button>
            </div>
        @endforeach
    </div>
@endif
```
<!-- @endcode-block -->

## LineState component

<!-- @code-block language="php" label="app/Livewire/LineState.php" -->
```php
namespace App\Livewire;

use Livewire\Component;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class LineState extends Component
{
    public string $state = '...';
    public ?string $mode = null;

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $values = Opcua::readMulti()
            ->node('ns=2;s=Line.State')
            ->node('ns=2;s=Line.Mode')
            ->execute();

        $this->state = (string) $values[0]->getValue();
        $this->mode  = (string) $values[1]->getValue();
    }

    public function getListeners(): array
    {
        return ['echo:plc.live,App\\Events\\TagUpdated' => 'maybeRefresh'];
    }

    public function maybeRefresh(array $payload): void
    {
        if (in_array($payload['node_id'], ['ns=2;s=Line.State', 'ns=2;s=Line.Mode'])) {
            $this->refresh();
        }
    }

    public function render()
    {
        return view('livewire.line-state');
    }
}
```
<!-- @endcode-block -->

The view (abbreviated):

<!-- @code-block language="text" label="resources/views/livewire/line-state.blade.php" -->
```text
<div class="rounded-lg border p-4 bg-white">
    <h3 class="text-xs font-semibold uppercase text-gray-500 mb-2">Line State</h3>

    <div @class([
        'text-lg font-bold',
        'text-green-600' => $state === 'Running',
        'text-amber-600' => $state === 'Idle' || $state === 'Standby',
        'text-red-600'   => $state === 'Fault',
    ])>{{ $state }}</div>

    <p class="text-sm text-gray-500 mt-1">Mode: {{ $mode ?? 'unknown' }}</p>
</div>
```
<!-- @endcode-block -->

## ThroughputChart component

For the chart, integrate a JS library (Chart.js, ApexCharts).
A minimal wiring:

<!-- @code-block language="php" label="ThroughputChart" -->
```php
class ThroughputChart extends Component
{
    public function getData(): array
    {
        return \App\Models\PlcReading::for('ns=2;s=Output')
            ->between(now()->subHour(), now())
            ->orderBy('source_at')
            ->get(['source_at', 'value_numeric'])
            ->map(fn($r) => [
                'x' => $r->source_at->format('H:i'),
                'y' => (float) $r->value_numeric,
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.throughput-chart', ['data' => $this->getData()]);
    }
}
```
<!-- @endcode-block -->

The view passes `$data` to a Chart.js instance — refresh every
minute via `wire:poll`.

## Performance characteristics

| Component       | Update mechanism                | Network cost                                |
| --------------- | ------------------------------- | ------------------------------------------- |
| TagTile         | Echo subscription               | One websocket message per change            |
| AlarmBar        | Echo subscription → `$refresh`  | One Livewire round-trip per new alarm        |
| LineState       | Echo subscription → re-read     | One websocket message + one OPC UA read     |
| ThroughputChart | `wire:poll.60s`                 | One DB query per minute                     |

For a 6-tile dashboard with 5 tags/sec arrival rate, the
end-to-end load is comfortable on modest hardware.

## Per-user filtering

To show only tags for the user's assigned line, scope at mount
time:

<!-- @code-block language="php" label="scoped dashboard" -->
```php
class PlantDashboard extends Component
{
    public function render()
    {
        $tags = auth()->user()->assignedLine?->tags ?? [];

        return view('livewire.plant-dashboard', compact('tags'));
    }
}
```
<!-- @endcode-block -->

…then iterate `$tags` in the view to spawn `TagTile` components
dynamically.

## Where to read next

- [Multi-plant tenant](./multi-plant-tenant.md) — per-tenant
  isolation.
- [Production deployment](./production-deployment.md) — putting
  it on a server.
