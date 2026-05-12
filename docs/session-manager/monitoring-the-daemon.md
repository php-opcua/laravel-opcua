---
eyebrow: 'Docs · Session manager'
lede:    'Liveness probes, session counts, log channels, and the metrics that matter. Plus a Laravel-native /health/opcua endpoint pattern.'

see_also:
  - { href: './production-supervisor.md',   meta: '6 min' }
  - { href: '../observability/logging.md',  meta: '5 min' }
  - { href: '../observability/telescope-and-pulse.md', meta: '5 min' }

prev: { label: 'Production supervisor', href: './production-supervisor.md' }
next: { label: 'Events overview',       href: '../events/overview.md' }
---

# Monitoring the daemon

A production daemon needs five questions answered at any moment:

1. Is it up?
2. How many sessions are open?
3. Are notifications flowing?
4. What was the last error?
5. How is the memory budget?

This page covers the lightweight, Laravel-native answers.

## 1 — Liveness probe (in Laravel)

A `/health/opcua` endpoint:

<!-- @code-block language="php" label="routes/web.php" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

Route::get('/health/opcua', function () {
    try {
        $alive = Opcua::isSessionManagerRunning();
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'down',
            'error'  => $e->getMessage(),
        ], 503);
    }

    return response()->json([
        'status' => $alive ? 'up' : 'direct-mode',
    ]);
});
```
<!-- @endcode-block -->

Returns `up` when the daemon responds to ping, `direct-mode`
when the daemon is unreachable but the package falls through to
direct connections, and `503` on a probe error.

Add this to your existing liveness aggregator (Kubernetes
liveness probe, Pingdom, Better Uptime — anything that hits an
HTTP endpoint).

## 2 — Session count

Probe the daemon directly. There is no `daemonStats()` method on
`OpcuaManager` — but `Opcua::isSessionManagerRunning()` checks for
the existence of the socket file (Unix) or assumes running (TCP),
and the daemon's `ping` IPC command can be invoked with a tiny
helper script (see
[`opcua-session-manager` · Debugging with netcat](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/recipes/debugging-with-netcat.md)).

<!-- @code-block language="php" label="health endpoint with detail" -->
```php
Route::get('/health/opcua/detail', function () {
    $manager = app(OpcuaManager::class);

    if (! $manager->isSessionManagerRunning()) {
        return response()->json(['status' => 'direct-mode-or-socket-missing'], 200);
    }

    // For richer stats, open the IPC socket yourself and send a
    // {"command":"ping","authToken":"..."} frame — see the netcat
    // recipe linked above for the wire format.

    return response()->json(['status' => 'socket-present']);
});
```
<!-- @endcode-block -->

`isSessionManagerRunning()` is a **socket-file existence check**
on Unix endpoints (not a live ping). For TCP endpoints it always
returns `true` and the first IPC call surfaces a `DaemonException`
if the daemon is actually down.

## 3 — Notification flow

The daemon dispatches the real `DataChangeReceived` /
`EventNotificationReceived` classes from `opcua-client` (see
[Events overview](../events/overview.md)). To track flow:

<!-- @code-block language="php" label="flow tracker" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class TrackOpcuaFlow
{
    public function handle(DataChangeReceived $event): void
    {
        // Increment a rolling counter
        Cache::increment('opcua:notifications:total');
        Cache::put('opcua:notifications:last', now()->toIso8601String());
    }
}
```
<!-- @endcode-block -->

Then probe:

<!-- @code-block language="php" label="route" -->
```php
Route::get('/health/opcua/flow', function () {
    $total = Cache::get('opcua:notifications:total', 0);
    $last  = Cache::get('opcua:notifications:last');

    $alarm = $last && now()->diffInMinutes(Carbon::parse($last)) > 5;

    return response()->json([
        'total'             => $total,
        'last_notification' => $last,
        'stale'             => $alarm,
    ], $alarm ? 503 : 200);
});
```
<!-- @endcode-block -->

If you expect notifications every few seconds and the last one
was 5 minutes ago, something's wrong upstream — the subscription
might have died, the server might be unreachable, the daemon's
publish loop might be stuck.

## 4 — Last error

The daemon logs to your Laravel `OPCUA_LOG_CHANNEL`. For a
real-time pulse on errors:

<!-- @code-block language="php" label="config/logging.php — opcua channel" -->
```php
'channels' => [
    'opcua' => [
        'driver' => 'stack',
        'channels' => ['opcua-daily', 'opcua-errors-slack'],
    ],
    'opcua-daily' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua.log'),
        'days'   => 14,
    ],
    'opcua-errors-slack' => [
        'driver' => 'slack',
        'url'    => env('LOG_SLACK_WEBHOOK_URL'),
        'level'  => 'error',
    ],
],
```
<!-- @endcode-block -->

Daemon errors go both to the daily file (for forensics) and to
Slack (for immediate response). Tune the `level` filter to your
team's tolerance for noise.

## 5 — Memory

The daemon's memory grows with:

- Number of sessions.
- Number of monitored items per subscription.
- Per-server protocol caches.

A 5-session, 100-monitored-item daemon typically uses 80-150 MB.
Spikes above that warrant a look.

Probe via the OS (systemd / supervisor logs RSS):

<!-- @code-block language="bash" label="terminal — process memory" -->
```bash
systemctl status opcua-session-manager   # systemd shows current memory
# or
ps -o rss,pid,command -p $(pgrep -f opcua:session)
```
<!-- @endcode-block -->

## Prometheus / Telegraf integration

A scheduled Laravel job exports notification counters to a
Prometheus push gateway. The daemon itself doesn't expose
structured stats over the artisan surface — write a tiny custom
command that opens the IPC socket, sends `{"command":"ping",…}`,
and parses the response (see the
[`opcua-session-manager` IPC reference](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/ipc/envelope-and-framing.md)
for the exact wire format).

<!-- @code-block language="php" label="ExportOpcuaMetrics command" -->
```php
// app/Console/Commands/ExportOpcuaMetrics.php — custom in your app
class ExportOpcuaMetrics extends Command
{
    protected $signature = 'opcua:metrics';

    public function handle(OpcuaManager $opcua): int
    {
        if (! $opcua->isSessionManagerRunning()) {
            return self::SUCCESS;
        }

        Http::post(env('PROMETHEUS_PUSH_URL') . '/metrics/job/opcua', [
            'socket_present' => 1,
            'notifications'  => Cache::get('opcua:notifications:total', 0),
        ]);

        return self::SUCCESS;
    }
}
```
<!-- @endcode-block -->

`opcua:metrics` is a **user-defined** command — not shipped by the
package. Schedule it every 15s in your app's `Console\Kernel`.

## Laravel Pulse / Telescope

Both Pulse and Telescope are useful for the **listener side** of
auto-publish — they show you events arriving, listener durations,
queue depth.

The daemon itself is outside their reach (it's not running
Laravel HTTP). For daemon-side observability, the log channel is
the canonical hook.

See [Observability · Telescope and Pulse](../observability/telescope-and-pulse.md).

## Common alarm rules

| Rule                                                  | Trigger / threshold                        | Likely cause                                  |
| ----------------------------------------------------- | ------------------------------------------ | --------------------------------------------- |
| `isSessionManagerRunning()` returns false              | > 1 minute                                 | Daemon dead, socket unreachable               |
| No `DataChangeReceived` events                          | > 5 minutes                                | Subscriptions dropped, server lost            |
| ERROR-level log entries                                | > 10/minute                                | Something's actively wrong                    |
| Memory                                                 | Above your normal +50%                     | Leak in a third-party module or listener      |

## What NOT to monitor

- **Per-call latency through the daemon.** Useful but expensive
  to instrument. Use Pulse on the listener side instead.
- **OPC UA wire-level metrics.** The daemon doesn't expose them.
  Instrument at the OPC UA server side if you need wire data.

## Debugging from the terminal

For occasional manual checks, see
[opcua-session-manager — Debugging with netcat](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/recipes/debugging-with-netcat.md)
— a handful of one-line probes that work directly against the
daemon socket.

## Where to read next

You've finished **Session manager**. Next: [Events overview](../events/overview.md)
for the Laravel-event side of the bridge.
