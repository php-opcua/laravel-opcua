---
eyebrow: 'Docs · Getting started'
lede:    'A real Laravel controller reading a PLC tag in three lines. Plus the canonical Tinker session, a console command, and a queued job — the four shapes you''ll write 90% of the time.'

see_also:
  - { href: './how-laravel-opcua-fits.md',          meta: '8 min' }
  - { href: '../using-the-client/facade-vs-injection.md', meta: '5 min' }
  - { href: '../operations/reading.md',             meta: '6 min' }

prev: { label: 'Installation',                href: './installation.md' }
next: { label: 'How laravel-opcua fits',      href: './how-laravel-opcua-fits.md' }
---

# Quick start

Four shapes cover almost every use of `laravel-opcua` in a real
Laravel application. This page walks through each.

Prerequisite: package installed (see [Installation](./installation.md))
and `.env` pointing at a reachable OPC UA server.

## 1 — In a controller

<!-- @code-block language="php" label="app/Http/Controllers/DashboardController.php" -->
```php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\StatusCode;

class DashboardController extends Controller
{
    public function speed(): JsonResponse
    {
        $dataValue = Opcua::read('ns=2;s=PLC/Speed');

        if (! StatusCode::isGood($dataValue->statusCode)) {
            return response()->json([
                'error' => 'Read failed: ' . StatusCode::getName($dataValue->statusCode),
            ], 503);
        }

        return response()->json([
            'speed_rpm' => $dataValue->getValue(),
            'as_of'     => $dataValue->sourceTimestamp?->format('c'),
        ]);
    }
}
```
<!-- @endcode-block -->

Route it like any other:

<!-- @code-block language="php" label="routes/web.php" -->
```php
use App\Http\Controllers\DashboardController;

Route::get('/api/speed', [DashboardController::class, 'speed']);
```
<!-- @endcode-block -->

`Opcua::read()` opens (or reuses) a session, issues a single OPC
UA Read request, returns a `DataValue`. The facade points at the
default connection from `config/opcua.php`.

## 2 — In a Tinker session

<!-- @code-block language="bash" label="terminal" -->
```bash
php artisan tinker
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="tinker" -->
```php
>>> use PhpOpcua\LaravelOpcua\Facades\Opcua;

>>> Opcua::read('i=2261')->getValue();
=> "open62541 OPC UA Server"

>>> $refs = Opcua::browse('i=85');
>>> count($refs);
=> 4

>>> Opcua::write('ns=2;s=PLC/Setpoint', 42.5);
=> 0    // 0 = Good status

>>> $eps = Opcua::getEndpoints('opc.tcp://plc.local:4840');
>>> collect($eps)->pluck('securityPolicyUri')->unique()->values()->all();
=> ["http://opcfoundation.org/UA/SecurityPolicy#None", "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256"]
```
<!-- @endcode-block -->

Tinker is the fastest exploration loop — no controller, no test,
no route. The `Opcua` facade works exactly as in production.

## 3 — In a console command

<!-- @code-block language="bash" label="terminal" -->
```bash
php artisan make:command CapturePlcSpeed
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="app/Console/Commands/CapturePlcSpeed.php" -->
```php
namespace App\Console\Commands;

use App\Models\PlcReading;
use Illuminate\Console\Command;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class CapturePlcSpeed extends Command
{
    protected $signature = 'plc:capture-speed
        {connection=default : The opcua connection name}';

    protected $description = 'Capture the current PLC speed reading';

    public function handle(): int
    {
        $value = Opcua::connection($this->argument('connection'))
            ->read('ns=2;s=PLC/Speed')
            ->getValue();

        PlcReading::create([
            'tag'   => 'PLC/Speed',
            'value' => $value,
        ]);

        $this->info("Captured: {$value}");

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule it in `routes/console.php`:

<!-- @code-block language="php" label="routes/console.php" -->
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('plc:capture-speed')->everyMinute();
```
<!-- @endcode-block -->

The kernel's `schedule:run` (typically wired into cron at 1-minute
granularity) fires it. One PLC read per tick — durable, in the
database, queryable later.

## 4 — In a queued job

For more involved work — fetch many tags, transform, persist,
notify — wrap it in a Job:

<!-- @code-block language="bash" label="terminal" -->
```bash
php artisan make:job SamplePlc
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="app/Jobs/SamplePlc.php" -->
```php
namespace App\Jobs;

use App\Models\PlcReading;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class SamplePlc implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $values = Opcua::readMulti([
            ['nodeId' => 'ns=2;s=PLC/Speed'],
            ['nodeId' => 'ns=2;s=PLC/Mode'],
            ['nodeId' => 'ns=2;s=PLC/Health'],
        ]);

        PlcReading::create([
            'speed'  => $values[0]->getValue(),
            'mode'   => $values[1]->getValue(),
            'health' => $values[2]->getValue(),
        ]);
    }
}
```
<!-- @endcode-block -->

Dispatch from anywhere:

<!-- @code-block language="php" label="dispatch" -->
```php
\App\Jobs\SamplePlc::dispatch();
```
<!-- @endcode-block -->

The queue worker picks it up. With Horizon, watch it through the
dashboard — see [Integrations · Horizon and
queues](../integrations/horizon-and-queues.md).

## With the session-manager daemon

For request-driven workloads (HTTP controllers, queue workers
that hit OPC UA per job), start the daemon in a separate
terminal:

<!-- @code-block language="bash" label="terminal — daemon" -->
```bash
php artisan opcua:session
```
<!-- @endcode-block -->

The Laravel app autodetects the daemon and routes through it.
The OPC UA session is opened once on the daemon side and reused
across every request — no per-request handshake.

In production, supervise the daemon; see [Session manager ·
Production supervisor](../session-manager/production-supervisor.md).

## What just happened, in one line each

| Step                                | Mechanism                                                   |
| ----------------------------------- | ----------------------------------------------------------- |
| `composer require ...`              | Auto-discovery wires the service provider + facade          |
| `php artisan vendor:publish ...`    | Drops `config/opcua.php` into the application               |
| `Opcua::read(...)`                  | Facade → `OpcuaManager::__call()` → default connection → `Client::read()` |
| `php artisan opcua:session`         | Boots the `SessionManagerDaemon` configured from `config/opcua.php` |
| Worker calling `Opcua::read(...)`   | `ManagedClient` → IPC → daemon-held session → server         |

Every layer is documented in detail in the rest of the docs.
This page is the **first turn**.

## Where to go next

- [How laravel-opcua fits](./how-laravel-opcua-fits.md) — the
  mental model behind the facade, the manager, and the daemon.
- [Using the client · Facade vs injection](../using-the-client/facade-vs-injection.md)
  — when to pick one over the other.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md)
  — the controller + schedule + Eloquent walkthrough, end to
  end.
