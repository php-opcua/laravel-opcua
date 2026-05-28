# Pitfalls

Common mistakes when working with `laravel-opcua`. Each entry has the **smell** (what you wrote), **why it's wrong**, and the **fix**.

## P1 — Resolving the Facade inside `config/opcua.php`

```php
// ❌ config/opcua.php
'session_manager' => [
    'logger' => Log::channel('stack'),  // FAILS — Log Facade not bound yet
],
```

Config is loaded before the Facade root binds. Use channel **names** (strings), resolved lazily by `OpcuaManager` or `SessionCommand`:

```php
// ✓
'log_channel' => env('OPCUA_LOG_CHANNEL', 'stack'),
```

## P2 — Calling `disconnect()` in HTTP requests with the daemon enabled

```php
// ❌ controller
public function show()
{
    $value = Opcua::read('i=2259')->getValue();
    Opcua::disconnect();  // tears down daemon session
    return $value;
}
```

Under `ManagedClient`, `disconnect()` is a daemon-side close — the next request pays the connect + session-create + activate cost again. Let the daemon manage lifecycle.

```php
// ✓ — just don't call disconnect
return Opcua::read('i=2259')->getValue();
```

## P3 — Calling `publish()` manually under `auto_publish`

```php
// ❌
Opcua::publish();  // returns auto_publish_active error, no notifications
```

The daemon's auto-publish loop owns the publish cycle. Subscribe to events instead:

```php
// ✓
Event::listen(DataChangeReceived::class, function ($e) { /* ... */ });
```

## P4 — Singleton client in Octane without the daemon

`OpcuaManager` is a singleton. Without the daemon, that singleton holds a single `Client` whose connection state is shared across requests in a long-lived Octane worker — and `flushAll` doesn't reset it. Subsequent requests see stale subscriptions / cached metadata.

Fix: enable the daemon (recommended), or flush the manager on `RequestTerminated`:

```php
Event::listen(RequestTerminated::class, fn() => app(OpcuaManager::class)->disconnectAll());
```

## P5 — `auto_accept: true` in production

```php
// ❌ config/opcua.php (prod)
'auto_accept' => true,
```

TOFU bypass. A man-in-the-middle on first connection gets permanently trusted. Use `auto_accept` only during initial bootstrap, then flip to `false` after seeding the trust store via `opcua-cli trust`.

## P6 — Plaintext password with `security_mode: None`

```php
// ❌
'security_mode' => 'None',
'username' => 'op',
'password' => 'secret',
```

Username token uses the server's cert to encrypt the password. With `security_mode: None`, there's no server cert exchange — password may go over the wire as plaintext (server behavior is implementation-specific). Use `Sign` or `SignAndEncrypt` whenever you supply credentials.

## P7 — Mixed daemon + app versions

App on v4.4, daemon on v4.3 → `BadMethodCallException` the first time the app calls `historyInsertData` / `openFile` / `aggregate`. Upgrade daemon first, then app.

## P8 — Hot-loop reads inside a job

```php
// ❌
while (true) {
    $v = Opcua::read('ns=2;s=Temp')->getValue();
    sleep(1);
}
```

A polling loop in a queue worker burns one DB-blocking job slot forever. Use subscriptions (`createMonitoredItems`) + auto-publish + queued listeners, or a scheduled command (`->everySecond()`) that exits quickly.

## P9 — Synchronous heavy work in event listener

```php
// ❌
Event::listen(DataChangeReceived::class, function ($e) {
    DB::table('readings')->insert([...]);
    Notification::send($admins, new NewReading($e));
    Http::post('https://webhook', [...]);
});
```

The daemon's publish loop blocks while the listener runs. At >50 events/sec, you'll back-pressure the loop and eventually drop notifications. Make the listener `implements ShouldQueue`:

```php
// ✓
class StoreReading implements ShouldQueue { /* ... */ }
```

## P10 — Forgetting `withoutOverlapping()` on cron probes

```php
// ❌
$schedule->call(fn() => Opcua::read('i=2259'))->everyMinute();
```

If the server hangs for 90 seconds, two probes run in parallel; if it hangs for 300 seconds, you have five. Always:

```php
// ✓
$schedule->call(fn() => Opcua::read('i=2259'))
    ->everyMinute()
    ->name('opcua-probe')
    ->withoutOverlapping();
```

## P11 — Trust store inside an ephemeral filesystem

```php
// ❌ Docker container without volume
'trust_store_path' => '/tmp/opcua-trust',
```

Container restart → trust store wiped → all reconnects fail. Mount a persistent volume; on Heroku-style ephemeral envs, use a cloud-stored cert + `trustCertificate()` at boot.

## P12 — Catching `\Exception` and continuing

```php
// ❌
try { Opcua::write('ns=2;s=Setpoint', 42.5); }
catch (\Exception) { /* shrug */ }
```

OPC UA writes can return Good with a side-effect (e.g., out-of-range clamping) or Bad with no exception (status code on the return value). Always check return value or specific exceptions:

```php
// ✓
use PhpOpcua\Client\Types\StatusCode;
$status = Opcua::write('ns=2;s=Setpoint', 42.5);
if (! StatusCode::isGood($status)) {
    Log::warning("Write failed: " . StatusCode::getName($status));
}
```

## P13 — Caching `Opcua::connection('x')` in a property

```php
// ❌ singleton service
public function __construct() {
    $this->client = Opcua::connection('plc-1');  // resolved once, never reconnects
}
```

The client is held forever. If the daemon dies / socket changes, your service holds a dead handle. Resolve lazily, or rely on `OpcuaManager::connection()`'s internal caching (it tracks reconnects).

## P14 — `connectTo` without `as:` in a request loop

```php
// ❌
foreach ($endpoints as $url) {
    Opcua::connectTo($url)->read('i=2259');  // new client per call, no caching
}
```

Each `connectTo` without `as:` builds a new connection. For long lists, supply `as: $url` to dedupe within the request.

## P15 — Daemon owned by root with web user trying to read socket

```bash
# ❌
sudo php artisan opcua:session  # daemon owns socket as root
```

`socket_mode: 0600` + root-owned = `www-data` cannot read. Run the daemon as the web user (or share a group via `socket_mode: 0660` + group ownership).

```ini
# ✓ supervisor
user=www-data
```

## P16 — Filament page that reads on every render

```php
// ❌
class PlantPage extends Page {
    protected function getViewData(): array {
        return ['temp' => Opcua::read('ns=2;s=Temp')->getValue()];  // every poll
    }
}
```

Filament polls (default 5s) → every visitor hits the PLC. Subscribe once, cache the value, render from cache:

```php
// ✓ in EventServiceProvider
Event::listen(DataChangeReceived::class, function ($e) {
    Cache::put("plant:temp", $e->dataValue->getValue(), 60);
});

// in Filament
protected function getViewData(): array {
    return ['temp' => Cache::get('plant:temp')];
}
```

## P17 — `OPCUA_AUTH_TOKEN` in `config/opcua.php` directly

```php
// ❌
'auth_token' => 'hardcoded-secret-in-git',
```

Committed to repo. Always:
```php
'auth_token' => env('OPCUA_AUTH_TOKEN'),
```

## P18 — Treating `subscriptionId` and `monitoredItemId` as global

Both are server-scoped. After a daemon restart with `transferSubscriptions` recovery, ids may change. Don't store them long-term; use `client_handle` for application-level correlation.

## P19 — `MockClient` in feature tests without binding it

```php
// ❌
$mock = MockClient::create()->onRead(...);
$response = $this->get('/dashboard');  // hits real config, ignores mock
```

You created the mock, you didn't tell Laravel to use it. Either swap the Facade with `Opcua::shouldReceive(...)`, or bind the mock into the container:

```php
// ✓
$this->app->instance(OpcUaClientInterface::class, $mock);
```

## P20 — `OPCUA_READ_METADATA_CACHE=true` with frequently-changing address space

The cache assumes node types are stable. If you have NodeSets that change at runtime (rare but happens with vendor-specific dynamic types), enabling the cache returns stale `BuiltinType` and `auto_detect_write_type` writes the wrong format. Either disable the cache, or invalidate explicitly: `Opcua::invalidateCache('ns=2;s=DynamicTag')`.
