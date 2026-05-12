---
eyebrow: 'Docs · Integrations'
lede:    'Filament admin panels backed by OPC UA — resource pages over Eloquent-backed tag tables, widget overviews of plant state, action buttons for setpoints. End-to-end example.'

see_also:
  - { href: './livewire.md',                          meta: '7 min' }
  - { href: '../events/alarm-events.md',              meta: '5 min' }
  - { href: '../recipes/alarm-routing.md',            meta: '5 min' }

prev: { label: 'Notifications',  href: './notifications.md' }
next: { label: 'Facade methods reference', href: '../reference/facade-methods.md' }
---

# Filament

[Filament](https://filamentphp.com) is a Livewire-based admin
panel framework. For OPC UA-driven Laravel apps, it's the fastest
way to build an operator UI — tag tables, plant widgets, alarm
queues — without hand-rolling resources.

This page documents **patterns** for combining Filament resources
and widgets with the OPC UA facade. The package does **not** ship
any Filament resources, widgets, or columns out of the box.

## Setup

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require filament/filament
php artisan filament:install --panels
php artisan make:filament-user
```
<!-- @endcode-block -->

Browse to `/admin`, log in.

## End-to-end — a Tag resource

Suppose you have a `PlcTag` model with `node_id`, `display_name`,
`unit`, `writable`. Filament resources give you full CRUD plus
custom actions.

### Migration / Model

<!-- @code-block language="php" label="model" -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlcTag extends Model
{
    protected $fillable = [
        'node_id', 'display_name', 'unit', 'writable', 'connection',
    ];

    protected $casts = [
        'writable' => 'boolean',
    ];
}
```
<!-- @endcode-block -->

### The Filament resource

<!-- @code-block language="php" label="app/Filament/Resources/PlcTagResource.php" -->
```php
namespace App\Filament\Resources;

use App\Filament\Resources\PlcTagResource\Pages;
use App\Models\PlcTag;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class PlcTagResource extends Resource
{
    protected static ?string $model = PlcTag::class;
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Plant';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('connection')
                ->options(fn () => array_combine(
                    array_keys(config('opcua.connections')),
                    array_keys(config('opcua.connections')),
                ))
                ->required(),
            Forms\Components\TextInput::make('node_id')->required(),
            Forms\Components\TextInput::make('display_name')->required(),
            Forms\Components\TextInput::make('unit'),
            Forms\Components\Toggle::make('writable'),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')->searchable(),
                Tables\Columns\TextColumn::make('node_id')->fontFamily('mono')->copyable(),
                Tables\Columns\TextColumn::make('connection'),

                Tables\Columns\TextColumn::make('live_value')
                    ->label('Live value')
                    ->state(function (PlcTag $record): string {
                        try {
                            $dv = Opcua::connection($record->connection)->read($record->node_id);
                            $v  = $dv->getValue();
                            return is_numeric($v)
                                ? number_format((float) $v, 2) . " {$record->unit}"
                                : (string) $v;
                        } catch (\Throwable $e) {
                            return '—';
                        }
                    }),

                Tables\Columns\IconColumn::make('writable')->boolean(),
            ])
            ->actions([
                Action::make('read')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (PlcTag $r) => "Read {$r->display_name}")
                    ->modalContent(fn (PlcTag $r) => view('filament.tag-read', [
                        'tag' => $r,
                        'dv'  => Opcua::connection($r->connection)->read($r->node_id),
                    ])),

                Action::make('write')
                    ->visible(fn (PlcTag $r) => $r->writable && auth()->user()->can('write-tag', $r))
                    ->icon('heroicon-o-pencil')
                    ->form([
                        Forms\Components\TextInput::make('value')->required()
                            ->numeric(fn (PlcTag $r) => is_numeric(Opcua::connection($r->connection)->read($r->node_id)->getValue())),
                    ])
                    ->action(function (array $data, PlcTag $record) {
                        Opcua::connection($record->connection)
                            ->write($record->node_id, $data['value']);

                        \App\Models\TagWriteAudit::create([
                            'user_id'    => auth()->id(),
                            'plc_tag_id' => $record->id,
                            'value'      => $data['value'],
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title("Wrote {$data['value']} to {$record->display_name}")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlcTags::route('/'),
            'create' => Pages\CreatePlcTag::route('/create'),
            'edit'   => Pages\EditPlcTag::route('/{record}/edit'),
        ];
    }
}
```
<!-- @endcode-block -->

What you get: a full CRUD interface for managing tag metadata,
plus a live-value column and read/write action buttons per row.

<!-- @callout type="warning" -->
**A "live value" column reads OPC UA on every table render.**
For 100+ tags this is slow. Either use a cached value (see
the [Cached-value pattern](#cached-value-pattern) below) or
hide this column behind an explicit "Show live" toggle.
<!-- @endcallout -->

## Cached-value pattern

A subscription listener fills a cache; Filament reads from cache
instead of OPC UA:

<!-- @code-block language="php" label="cache-fill listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class CacheTagValue implements ShouldQueue
{
    public string $queue = 'opcua-cache';

    public function handle(DataChangeReceived $event): void
    {
        Cache::put(
            "plc:tag:handle:{$event->clientHandle}",
            [
                'value'  => $event->dataValue->getValue(),
                'status' => $event->dataValue->statusCode,
                'at'     => $event->dataValue->sourceTimestamp?->format('c'),
            ],
            minutes: 5,
        );
    }
}
```
<!-- @endcode-block -->

The table column becomes:

<!-- @code-block language="php" label="cached column" -->
```php
Tables\Columns\TextColumn::make('live_value')
    ->state(function (PlcTag $record): string {
        // $record->client_handle is the same handle you used at subscription time
        $cached = Cache::get("plc:tag:handle:{$record->client_handle}");
        if (!$cached) return '—';

        return number_format((float) $cached['value'], 2);
    }),
```
<!-- @endcode-block -->

No OPC UA round-trip on render — sub-millisecond per row.

## A plant-overview widget

Filament widgets are dashboard tiles. A live plant-state tile:

<!-- @code-block language="php" label="app/Filament/Widgets/PlantOverview.php" -->
```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class PlantOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '2s';

    protected function getStats(): array
    {
        try {
            $values = Opcua::readMulti()
                ->node('ns=2;s=Speed')
                ->node('ns=2;s=Temperature')
                ->node('ns=2;s=Throughput')
                ->execute();

            return [
                Stat::make('Speed', number_format((float) $values[0]->getValue(), 1) . ' m/min')
                    ->color($values[0]->statusCode === 0 ? 'success' : 'danger')
                    ->description('Live')
                    ->chart([10, 12, 14, 13, 15, 14, 15]),

                Stat::make('Temperature', number_format((float) $values[1]->getValue(), 1) . '°C')
                    ->color('warning')
                    ->description('Live'),

                Stat::make('Throughput', number_format((float) $values[2]->getValue(), 0))
                    ->color('primary')
                    ->description('units/hr'),
            ];
        } catch (\Throwable $e) {
            return [
                Stat::make('Plant', 'OFFLINE')->color('danger')->description($e->getMessage()),
            ];
        }
    }
}
```
<!-- @endcode-block -->

Filament polls every 2 seconds and refreshes the widget. The
batched read keeps it fast.

## An alarm queue page

Filament's resource pattern for an alarm queue:

<!-- @code-block language="php" label="alarm resource — table snippet" -->
```php
public static function table(Tables\Table $table): Tables\Table
{
    return $table
        ->query(\App\Models\PlcAlarm::query()->where('is_active', true))
        ->columns([
            Tables\Columns\TextColumn::make('occurred_at')->sortable()->dateTime(),
            Tables\Columns\TextColumn::make('source'),
            Tables\Columns\TextColumn::make('severity')
                ->badge()
                ->color(fn (int $s) => match (true) {
                    $s >= 900 => 'danger',
                    $s >= 700 => 'warning',
                    default    => 'gray',
                }),
            Tables\Columns\TextColumn::make('message')->limit(60),
            Tables\Columns\IconColumn::make('is_acked')->boolean(),
        ])
        ->actions([
            Action::make('ack')
                ->visible(fn ($r) => ! $r->is_acked)
                ->form([Forms\Components\Textarea::make('comment')])
                ->action(function (array $data, $record) {
                    app(\App\Services\AlarmAcknowledger::class)
                        ->ack($record, auth()->user(), $data['comment'] ?? '');
                }),
        ])
        ->poll('5s')
        ->defaultSort('severity', 'desc');
}
```
<!-- @endcode-block -->

`AlarmAcknowledger` is the service that performs the OPC UA
method call (see [Operations · Method calls](../operations/method-calls.md)).

## Custom pages

For something not modeled by Eloquent (e.g. raw browse / explore),
a Filament Page:

<!-- @code-block language="php" label="custom page" -->
```php
namespace App\Filament\Pages;

use Filament\Pages\Page;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class PlcExplorer extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-folder';
    protected static string $view = 'filament.plc-explorer';

    public ?string $rootNode = 'ns=0;i=85';
    public array $children = [];

    public function mount(): void
    {
        $this->loadChildren();
    }

    public function setRoot(string $nodeId): void
    {
        $this->rootNode = $nodeId;
        $this->loadChildren();
    }

    private function loadChildren(): void
    {
        $this->children = collect(Opcua::browse($this->rootNode))
            ->map(fn ($ref) => [
                'node_id'      => (string) $ref->nodeId,
                'browse_name'  => $ref->browseName->name,
                'display_name' => $ref->displayName->text,
                'node_class'   => $ref->nodeClass,
            ])->all();
    }
}
```
<!-- @endcode-block -->

…with a view that lets the operator click through the address
space.

## Forms with OPC UA validation

A form field that validates against a live OPC UA range:

<!-- @code-block language="php" label="OPC UA-validated input" -->
```php
Forms\Components\TextInput::make('setpoint_value')
    ->numeric()
    ->minValue(function () {
        return (float) Opcua::read('ns=2;s=Setpoint.MinValue')->getValue();
    })
    ->maxValue(function () {
        return (float) Opcua::read('ns=2;s=Setpoint.MaxValue')->getValue();
    })
    ->helperText('Range is read from the PLC')
    ->required(),
```
<!-- @endcode-block -->

The bounds come from the live PLC, not from application config —
guaranteed in sync with the actual device.

## Permission policies

Filament respects Laravel policies. For a tag-write action:

<!-- @code-block language="php" label="policy gate in Filament" -->
```php
Action::make('write')
    ->visible(fn (PlcTag $r) => auth()->user()->can('writeSetpoint', $r))
    ->authorize('writeSetpoint'),
```
<!-- @endcode-block -->

The button hides for unauthorised users, plus a defence-in-depth
check at action-execute time.

## Filament + Reverb (real-time)

Filament pages are Livewire components — see
[Livewire](./livewire.md) for the broadcast-listener pattern.
Inside a Filament resource page, the same `getListeners()` works.

## Notifications inside Filament

Filament has its own toast notification system —
`Notification::make()->send()`. Use it for action feedback:

<!-- @code-block language="php" label="filament notification" -->
```php
->action(function (array $data, PlcTag $record) {
    try {
        Opcua::write($record->node_id, $data['value']);

        \Filament\Notifications\Notification::make()
            ->title('Setpoint applied')
            ->body("Wrote {$data['value']} to {$record->display_name}")
            ->success()
            ->send();
    } catch (\Throwable $e) {
        \Filament\Notifications\Notification::make()
            ->title('Write failed')
            ->body($e->getMessage())
            ->danger()
            ->send();
    }
})
```
<!-- @endcode-block -->

## Where to read next

You've finished **Integrations**. Next: [Reference · Facade
methods](../reference/facade-methods.md) for the full API list.
