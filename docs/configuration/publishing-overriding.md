---
eyebrow: 'Docs · Configuration'
lede:    'How to publish the config, what to commit, when to override at runtime, and how the package resolves config across cache and tests.'

see_also:
  - { href: './config-file.md',                     meta: '6 min' }
  - { href: './environment-variables.md',           meta: '5 min' }
  - { href: '../testing/pest-setup.md',             meta: '5 min' }

prev: { label: 'Session manager',  href: './session-manager.md' }
next: { label: 'Facade vs injection', href: '../using-the-client/facade-vs-injection.md' }
---

# Publishing and overriding

## Publishing the config

The `OpcuaServiceProvider` registers `config/opcua.php` as a
publishable resource tagged `opcua-config`:

<!-- @code-block language="bash" label="terminal" -->
```bash
php artisan vendor:publish --tag=opcua-config
```
<!-- @endcode-block -->

That copies the package's `config/opcua.php` into your application's
`config/` directory. After publishing:

- Your `config/opcua.php` **wins** over the package default. The
  package's file becomes irrelevant.
- Re-running `vendor:publish` with `--force` overwrites your copy
  (warning — destructive).
- The published file is plain PHP — version-control it, keep
  `env()` calls in it, treat it as application code.

## What to commit

- ✅ `config/opcua.php` — published config file, intentional.
- ✅ `config/logging.php` additions (the `opcua` channel).
- ❌ `.env` — never. Use `.env.example` for the keys.
- ❌ Resolved certificate paths pointing at local-only filesystem
  locations — use `env()` so each environment can resolve them
  differently.

## Overriding at runtime

Three common reasons to override config at runtime:

### 1 — Per-tenant, per-request

If a multi-tenant app needs a different endpoint per tenant, define
all tenants statically in `config/opcua.php` and resolve by name
per request:

<!-- @code-block language="php" label="app/Http/Middleware/SetOpcuaConnection.php" -->
```php
public function handle(Request $request, Closure $next): Response
{
    $tenant = $request->user()->tenant_id;
    $request->merge(['opcua_connection' => "plc-tenant-{$tenant}"]);

    return $next($request);
}
```
<!-- @endcode-block -->

…then in the controller:

<!-- @code-block language="php" label="controller" -->
```php
public function read(Request $request): JsonResponse
{
    $dv = Opcua::connection($request->input('opcua_connection'))
        ->read('ns=2;s=Speed');

    return response()->json(['value' => $dv->getValue()]);
}
```
<!-- @endcode-block -->

No config mutation — just connection switching.

### 2 — Dynamic endpoint per call

If the endpoint is fundamentally dynamic (a fleet of identical
PLCs reachable by serial number), use `connectTo()`:

<!-- @code-block language="php" label="ad-hoc connection" -->
```php
$dv = Opcua::connectTo([
    'endpoint'        => "opc.tcp://plc-{$serial}.factory.local:4840",
    'security_policy' => 'Basic256Sha256',
    'security_mode'   => 'SignAndEncrypt',
    'username'        => config('opcua.connections.default.username'),
    'password'        => config('opcua.connections.default.password'),
])->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

`connectTo()` accepts the same array shape as a `connections.*`
entry. See [Using the client · Ad-hoc connections](../using-the-client/ad-hoc-connections.md).

### 3 — Test-time overrides

In tests, use Laravel's `Config::set()`:

<!-- @code-block language="php" label="tests/Feature/OpcuaTest.php" -->
```php
beforeEach(function () {
    Config::set('opcua.connections.default.endpoint', 'opc.tcp://127.0.0.1:14840');
    Config::set('opcua.session_manager.enabled', false);
});
```
<!-- @endcode-block -->

For deeper test patterns see [Testing · Pest setup](../testing/pest-setup.md)
and [Mocking the facade](../testing/mocking-the-facade.md).

## Resolution order

The package reads config in this order, last-write-wins:

1. **Package defaults** — `vendor/.../config/opcua.php` if not
   published.
2. **Application config** — `config/opcua.php` if published.
3. **Environment** — `env()` calls inside `config/opcua.php`
   resolve to `.env` values at boot.
4. **Cached config** — `bootstrap/cache/config.php` if
   `config:cache` was run. **Frozen** until cleared.
5. **Runtime mutations** — `Config::set()`, only in-process.

`Config::set()` does **not** persist across requests or workers
unless wrapped in middleware/bootstrap. It is suitable for tests
and per-request context, not for "remember this for next time".

## Octane / FrankenPHP / Vapor implications

Long-running PHP processes (Octane, FrankenPHP, RoadRunner) read
config **once at boot**. `Config::set()` lasts for the lifetime
of the worker, which crosses request boundaries:

<!-- @callout type="warning" -->
**Don't mutate global config from a request handler under Octane.**
The mutation will leak into the next request handled by the same
worker. Use `Opcua::connection()` / `Opcua::connectTo()` instead —
those are stateless and request-scoped.
<!-- @endcallout -->

See [Integrations · Octane and FrankenPHP](../integrations/octane-and-frankenphp.md).

## Forcing a config reload

If something feels wrong:

<!-- @code-block language="bash" label="terminal — config reset" -->
```bash
php artisan config:clear     # remove the cached config
php artisan config:cache     # rebuild from the current state of .env + config/
```
<!-- @endcode-block -->

For Octane:

<!-- @code-block language="bash" label="terminal — octane reset" -->
```bash
php artisan octane:reload    # restart workers, picks up new config
```
<!-- @endcode-block -->

## What `vendor:publish` does not publish

- **Routes** — the package ships none.
- **Migrations** — the package ships none. If you need a
  `plc_readings` table, it's yours to design.
- **Views** — none. There are no UI components.
- **Translations** — none. Error messages are not localised.

The package's surface is a **service**, not a feature module.
What you build on top is yours.

## Removing the package

`composer remove php-opcua/laravel-opcua`. Optionally delete
`config/opcua.php` afterwards — Laravel ignores it, but it makes
the intent clear.

If you set up the `opcua` log channel, dedicated cache store, or
custom artisan commands, those stay until you remove them — the
package doesn't track what you wired around it.

## Where to read next

You've finished **Configuration**. The next group, [Using the
client](../using-the-client/facade-vs-injection.md), goes through
the four idiomatic ways to call OPC UA from Laravel code.
