---
eyebrow: 'Docs · Observability'
lede:    'When something doesn''t work — a connection won''t open, a write is rejected, events stop flowing. A decision tree of cheap diagnostics, from logs to tinker to netcat.'

see_also:
  - { href: './logging.md',                              meta: '5 min' }
  - { href: '../session-manager/monitoring-the-daemon.md', meta: '5 min' }
  - { href: '../reference/exceptions.md',                meta: '4 min' }

prev: { label: 'Caching',           href: './caching.md' }
next: { label: 'Telescope & Pulse', href: './telescope-and-pulse.md' }
---

# Debugging

Symptom → cheapest probe → next-step. Use as a decision tree.

## "I can't read anything"

### Step 1 — confirm the package is wired

<!-- @code-block language="bash" label="terminal" -->
```bash
php artisan tinker
> Opcua::isSessionManagerRunning()       # true / false
> config('opcua.connections.default')    # endpoint + identity
```
<!-- @endcode-block -->

Common findings:

| Symptom                                       | Likely cause                                                |
| --------------------------------------------- | ----------------------------------------------------------- |
| `null` config                                  | Forgot `vendor:publish` or `OpcuaServiceProvider` not loaded |
| Wrong endpoint                                  | `.env` not loaded — `php artisan config:clear`             |
| `isSessionManagerRunning()` throws              | Daemon socket misconfigured                                |

### Step 2 — try a raw read

<!-- @code-block language="bash" label="terminal — tinker probe" -->
```bash
php artisan tinker
> Opcua::read('i=2256')                  # Server_ServerStatus_State — always exists
```
<!-- @endcode-block -->

`i=2256` is part of the standard namespace — every OPC UA server
exposes it. If the read succeeds but `ns=2;s=Speed` fails, the
issue is the **node ID**, not the connection.

### Step 3 — check the connection events

<!-- @code-block language="bash" label="terminal — recent log" -->
```bash
tail -50 storage/logs/opcua-$(date +%Y-%m-%d).log
```
<!-- @endcode-block -->

Look for connection-lifecycle log lines, or for a `ConnectionFailed`
event payload (`PhpOpcua\Client\Event\ConnectionFailed` carries the
exception that explains the next step).

## "Writes are rejected"

Connect to the node, read its attributes:

<!-- @code-block language="php" label="tinker — attribute check" -->
```php
> use PhpOpcua\Client\Types\AttributeId;
> Opcua::read('ns=2;s=Setpoint', AttributeId::AccessLevel)->getValue()  # bit 1 must be set for write
> Opcua::read('ns=2;s=Setpoint', AttributeId::DataType)->getValue()     # what type does it expect?
```
<!-- @endcode-block -->

| Status (`StatusCode::getName(...)`) | Likely cause                              |
| ----------------------------------- | ------------------------------------------ |
| `BadTypeMismatch`                   | PHP type → BuiltinType mismatch — use explicit type |
| `BadNotWritable`                    | Node isn't writable (`AccessLevel & 0x2 == 0`)      |
| `BadUserAccessDenied`               | Session lacks permission — check user identity      |
| `BadOutOfRange`                     | Value too high/low for the node's range             |

See [Operations · Writing](../operations/writing.md#explicit-types)
for explicit-type override.

## "Events stopped firing"

### Step 1 — check the daemon

<!-- @code-block language="bash" label="terminal — daemon liveness" -->
```bash
# Real IPC envelope is flat, NDJSON, one frame per line.
echo '{"command":"ping"}' \
    | nc -U /var/run/opcua/sessions.sock
# Expect: {"success":true,"data":{"status":"ok","sessions":N,"time":<unix>}}
```
<!-- @endcode-block -->

See [`opcua-session-manager` · Envelope and framing](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/ipc/envelope-and-framing.md)
for the exact wire format.

If `nc` hangs → daemon not listening.
If sessions count is 0 → no sessions held.

### Step 2 — check auto-publish

<!-- @code-block language="bash" label="terminal — config" -->
```bash
php artisan tinker
> config('opcua.session_manager.auto_publish')   # must be true
```
<!-- @endcode-block -->

If `false`, the daemon doesn't emit events even though
subscriptions exist.

### Step 3 — check listeners are registered

<!-- @code-block language="bash" label="terminal — event:list" -->
```bash
php artisan event:list | grep -i opcua
```
<!-- @endcode-block -->

Expected output:

<!-- @code-block language="text" label="event list" -->
```text
PhpOpcua\Client\Event\DataChangeReceived ─── App\Listeners\StoreReadings
PhpOpcua\Client\Event\DataChangeReceived ─── App\Listeners\BroadcastTagUpdate
```
<!-- @endcode-block -->

If listeners aren't listed, fix `EventServiceProvider` or
auto-discovery.

### Step 4 — check the queue

If your listeners implement `ShouldQueue`:

<!-- @code-block language="bash" label="terminal — queue" -->
```bash
php artisan queue:work --once redis --queue=opcua-data
# Or via Horizon
php artisan horizon:list
```
<!-- @endcode-block -->

If the listener is failing silently, check `failed_jobs`:

<!-- @code-block language="bash" label="terminal — failed jobs" -->
```bash
php artisan queue:failed
```
<!-- @endcode-block -->

## "It works in tinker but not in the request"

Likely causes:

1. **Different connection.** Tinker uses the default; the
   request uses a different one. Check
   `Opcua::connection(...)`.
2. **Config cache.** Run `php artisan config:clear` and try
   again.
3. **Octane stale state.** Run `php artisan octane:reload`.

## "Tests are failing with connection errors"

Tests should not hit a real OPC UA server. Add this to your
test bootstrap:

<!-- @code-block language="php" label="tests/Pest.php" -->
```php
beforeEach(function () {
    Config::set('opcua.session_manager.enabled', false);
});
```
<!-- @endcode-block -->

…then mock the facade in feature tests:

<!-- @code-block language="php" label="test" -->
```php
Opcua::shouldReceive('read')
    ->with('ns=2;s=Speed')
    ->andReturn(DataValue::ofDouble(42.5));
```
<!-- @endcode-block -->

See [Testing · Mocking the facade](../testing/mocking-the-facade.md).

## "I get InactiveSessionException after a while"

The server's `MaxSessionTimeout` fired. The package will reopen
on the next call automatically. If this is **frequent** under
managed mode, check:

1. The daemon's `session_timeout` should be **less** than the
   server's `MaxSessionTimeout`. The daemon recycles before the
   server does — clean teardown.
2. The daemon's cleanup loop should run — check the daemon log
   for periodic cleanup entries.

## "The cert handshake fails"

| Exception                                  | Likely cause                                            |
| ------------------------------------------ | ------------------------------------------------------- |
| `UntrustedCertificateException`            | Server cert not in trust store                          |
| `CertificateParseException`                | Cert file missing required OPC UA fields                |
| `SignatureVerificationException`           | Cipher-suite mismatch or malformed signed message       |
| `UnsupportedCurveException`                | OpenSSL on this host doesn't support the configured ECC curve |
| Any `SecurityException` subclass           | Catch-all for crypto / trust failure                     |

For trust-store inspection, list the directory directly (or use
`opcua-cli trust:list`). The Laravel package does not ship a
`trust:list` command — see
[Security · Trust store](../security/trust-store.md).

## "I see a leak — memory grows over time"

In long-running workers (Octane, Horizon), this happens. Sources:

1. **Cache without LRU.** Use Redis with `allkeys-lru`.
2. **Eloquent collection growth in listeners.** Don't accumulate
   across events.
3. **Builder reuse.** Don't reuse the same builder for hundreds
   of monitored items — each call freshens.

Set `--memory=512` on the worker and let it restart on exceed.

## "I want to see the wire"

For deep protocol debugging, enable `debug` logging and
correlate with the daemon's IPC log. Or — `tcpdump` against the
OPC UA endpoint:

<!-- @code-block language="bash" label="terminal — tcpdump" -->
```bash
sudo tcpdump -i any -A 'port 4840' -w opcua.pcap
```
<!-- @endcode-block -->

Open the pcap in Wireshark; install the OPC UA dissector
(bundled with Wireshark since ≥3.0) for a decoded view.

## Hard-to-diagnose intermittent failures

When the timing of the failure is unclear:

1. **Increase logging temporarily** — set the log channel's
   `level` to `debug` in `config/logging.php`. There is no
   `OPCUA_LOG_LEVEL` env var.
2. **Pipe through Telescope** — see [Telescope and Pulse](./telescope-and-pulse.md).
3. **Watch the daemon's IPC socket** — `lsof -U` or open the
   socket directly with the netcat recipe from the
   `opcua-session-manager` docs.
4. **Schedule a custom health-check command** that calls
   `Opcua::read(...)` against a known node and graphs the
   round-trip time.

## When to escalate

If the failure is in the OPC UA library itself (not your code,
not your config), capture:

1. The exception class and full message.
2. The connection config (redacted of secrets).
3. The relevant log lines (with `debug` level).
4. The server product / version (from `Server_ServerStatus_BuildInfo`).

Open an issue against the upstream `opcua-client` repo or
`opcua-session-manager`. The Laravel package mostly delegates —
problems are usually a layer down.

## Where to read next

- [Telescope and Pulse](./telescope-and-pulse.md) — request-level
  observability.
- [Reference · Exceptions](../reference/exceptions.md) — what
  each exception means.
