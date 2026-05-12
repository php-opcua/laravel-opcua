---
eyebrow: 'Docs · Observability'
lede:    'How the package logs, what it logs, and how to wire its log channel into Laravel''s stack. Includes the dedicated-channel pattern most plants converge on.'

see_also:
  - { href: './debugging.md',                          meta: '5 min' }
  - { href: '../session-manager/monitoring-the-daemon.md', meta: '5 min' }

prev: { label: 'Queued listeners',  href: '../events/queued-listeners.md' }
next: { label: 'Caching',           href: './caching.md' }
---

# Logging

The package logs through Laravel's standard `LoggerInterface`.
Every log message is in **one of two surfaces**:

1. **Client-side** — your Laravel process emits log lines for
   connection events, errors, warnings.
2. **Daemon-side** — the `php artisan opcua:session` process emits
   log lines for session lifecycle, IPC errors, subscription state.

Both go through Laravel logging, so the same channel
configuration applies.

## Default channel

Without configuration, the package logs to the global
`LOG_CHANNEL`. The lines mix in with your application's other
logs:

<!-- @code-block language="text" label="default log" -->
```text
[2026-05-15 10:15:23] production.INFO: User logged in
[2026-05-15 10:15:24] production.INFO: OPCUA connection opened plc-line-a
[2026-05-15 10:15:25] production.WARNING: User action rate-limited
[2026-05-15 10:15:26] production.ERROR: OPCUA connection failed plc-line-b
```
<!-- @endcode-block -->

That's fine for low-volume use. For production, dedicate a
channel.

## Dedicated channel pattern

In `config/logging.php`:

<!-- @code-block language="php" label="config/logging.php" -->
```php
'channels' => [
    'opcua' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua.log'),
        'level'  => env('OPCUA_LOG_LEVEL', 'info'),
        'days'   => 14,
        'replace_placeholders' => true,
    ],
],
```
<!-- @endcode-block -->

In `.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_LOG_CHANNEL=opcua    # consumed by the daemon (session_manager.log_channel)
```
<!-- @endcode-block -->

`OPCUA_LOG_CHANNEL` is read by `config('opcua.session_manager.log_channel')`
(it scopes the **daemon's** logger). The client-side per-connection
log channel is set in `config/opcua.php` under
`connections.{name}.log_channel`; it is hard-coded to `'stdout'`
in the published default and is **not** sourced from an env var
unless you change the config file.

There is no `OPCUA_LOG_LEVEL` env var — the level is controlled
by the channel's own configuration in `config/logging.php`.

Now every package-emitted log line goes to your configured
channel, ready to grep or to ship to external aggregation (Loki,
Loggly, Sumo).

## What the package logs

| Level     | When                                                | Example                                          |
| --------- | --------------------------------------------------- | ------------------------------------------------ |
| `debug`   | Per-call detail (only when `APP_DEBUG=true`)         | "Calling read for nodeId='ns=2;s=Speed'"          |
| `info`    | Connection lifecycle, daemon state changes           | "Connection plc-line-a opened"                   |
| `notice`  | Recoverable degradation (managed → direct fallback)  | "Daemon unreachable, falling back to direct"     |
| `warning` | Transient failures with auto-recovery                | "Read retry 2/3 for ns=2;s=Speed"                |
| `error`   | Failed operations that surfaced as exceptions        | "Write rejected: Bad_TypeMismatch"               |
| `critical` | Failed-state daemon, lost subscriptions             | "Daemon publish loop stuck — restart required"   |

The package **never** logs:

- Passwords or auth tokens.
- Cert private keys.
- Raw OPC UA values from data changes (potentially huge,
  potentially sensitive).

If you want value logging, add an explicit listener and log
through your channel — see [Data events](../events/data-events.md).

## Per-connection log channel

Each connection can override the channel:

<!-- @code-block language="php" label="config/opcua.php" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint'    => '...',
        'log_channel' => 'plc-line-a',     // dedicated per line
    ],
    'plc-line-b' => [
        'endpoint'    => '...',
        'log_channel' => 'plc-line-b',
    ],
],
```
<!-- @endcode-block -->

…with matching channel definitions in `config/logging.php`. Useful
when ops wants per-line log volumes for troubleshooting.

## Stacked channels — Slack on errors

<!-- @code-block language="php" label="stacked channel" -->
```php
'channels' => [
    'opcua' => [
        'driver'   => 'stack',
        'channels' => ['opcua-daily', 'opcua-errors-slack'],
    ],
    'opcua-daily' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/opcua.log'),
        'days'   => 14,
    ],
    'opcua-errors-slack' => [
        'driver'   => 'slack',
        'url'      => env('LOG_SLACK_WEBHOOK_URL'),
        'username' => 'OPCUA',
        'emoji'    => ':zap:',
        'level'    => 'error',
    ],
],
```
<!-- @endcode-block -->

Daily file gets everything; Slack only sees `error` and above.

## Structured logging

For machine-readable logs (ELK, Loki):

<!-- @code-block language="php" label="JSON formatter" -->
```php
'opcua-json' => [
    'driver'    => 'monolog',
    'handler'   => StreamHandler::class,
    'with' => ['stream' => storage_path('logs/opcua.json')],
    'formatter' => JsonFormatter::class,
],
```
<!-- @endcode-block -->

Output:

<!-- @code-block language="text" label="JSON log" -->
```text
{"datetime":"2026-05-15T10:15:24+00:00","channel":"opcua","level_name":"INFO",
 "message":"Connection opened","context":{"connection":"plc-line-a",
 "endpoint":"opc.tcp://...","duration_ms":312}}
```
<!-- @endcode-block -->

…ready to be ingested by anything that speaks JSON.

## Daemon-side logging

The daemon logs to the channel passed via `--log-channel=`:

<!-- @code-block language="bash" label="terminal — daemon channel" -->
```bash
php artisan opcua:session --log-channel=opcua --cache-store=redis
```
<!-- @endcode-block -->

When started this way, the daemon's PSR-3 logger writes through
Laravel's `opcua` channel — file rotation, formatting, and
shipping all happen the Laravel way.

Without `--log-channel`, the daemon falls back to a stderr logger.
Useful only in Docker / containerised environments where stderr
goes to the orchestrator's log system.

## Log volume — what to expect

| Workload                                  | Lines per minute |
| ----------------------------------------- | ---------------- |
| 1 connection, no subscriptions             | 0-5              |
| 5 connections, low-frequency reads         | 5-20             |
| 5 connections, full auto-publish, 100 tags  | 50-200           |
| Subscription drops + reconnect storm        | 1000+ for a few min |

Set `OPCUA_LOG_LEVEL=info` for production. `debug` is too verbose
for sustained use.

## Sensitive data in your listener logs

Listeners are **your** code, not the package's. The package
doesn't log values; your listener can. Be deliberate:

<!-- @code-block language="php" label="careful listener" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

class LogDataChanges
{
    public function handle(DataChangeReceived $event): void
    {
        // OK — handle, status, timestamp. No value.
        Log::channel('plc-data')->info("data change", [
            'client_handle' => $event->clientHandle,
            'status'        => $event->dataValue->statusCode,
            'at'            => $event->dataValue->sourceTimestamp?->format('c'),
        ]);
    }
}
```
<!-- @endcode-block -->

Logging values is fine when:

- The value's PII risk is zero (a temperature reading).
- Volume is low (engineering setpoint changes, not continuous
  process values).
- You actually need the data downstream.

## Logging exceptions

The package raises typed exceptions on failure (see [Reference ·
Exceptions](../reference/exceptions.md)). Catch them and log:

<!-- @code-block language="php" label="exception logging" -->
```php
try {
    Opcua::write($node, $value);
} catch (\PhpOpcua\Client\Exception\ServiceException $e) {
    Log::channel('plc')->warning("Write rejected", [
        'node_id' => $node,
        'value'   => $value,
        'status'  => $e->getStatusCode(),
        'status_name' => \PhpOpcua\Client\Types\StatusCode::getName($e->getStatusCode()),
        'message' => $e->getMessage(),
    ]);
    throw $e;
}
```
<!-- @endcode-block -->

Always include the relevant context — log lines without it are
unhelpful for forensics.

## Where to read next

- [Caching](./caching.md) — the package's cache surface.
- [Debugging](./debugging.md) — when logs aren't enough.
- [Telescope and Pulse](./telescope-and-pulse.md) — request-level
  observability.
