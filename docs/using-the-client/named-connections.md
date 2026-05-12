---
eyebrow: 'Docs · Using the client'
lede:    'Switch between configured connections by name with Opcua::connection(). The pattern is identical to Laravel''s DB facade — and the same caching rules apply.'

see_also:
  - { href: '../configuration/connections.md',     meta: '8 min' }
  - { href: './ad-hoc-connections.md',             meta: '5 min' }
  - { href: './connection-lifecycle.md',           meta: '5 min' }

prev: { label: 'Facade vs injection',              href: './facade-vs-injection.md' }
next: { label: 'Ad-hoc connections',               href: './ad-hoc-connections.md' }
---

# Named connections

The package mirrors Laravel's database facade. You configure
multiple connections in `config/opcua.php`, then switch between
them by name at the call site.

## The shape

<!-- @code-block language="php" label="config/opcua.php" -->
```php
'default' => env('OPCUA_CONNECTION', 'plc-line-a'),

'connections' => [
    'plc-line-a' => [
        'endpoint' => 'opc.tcp://plc-a.factory.local:4840',
        // ...
    ],
    'plc-line-b' => [
        'endpoint' => 'opc.tcp://plc-b.factory.local:4840',
        // ...
    ],
    'historian' => [
        'endpoint' => 'opc.tcp://historian.factory.local:4841',
        // ...
    ],
],
```
<!-- @endcode-block -->

## Switching at the call site

<!-- @code-block language="php" label="usage" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Default connection
$dv = Opcua::read('ns=2;s=Speed');

// Named connection
$dv = Opcua::connection('plc-line-b')->read('ns=2;s=Speed');

// Chained reads on a non-default connection
$client = Opcua::connection('historian');
$tags = $client->browseRecursive('ns=4;s=Tags');
```
<!-- @endcode-block -->

`Opcua::connection($name)` returns the underlying
`OpcUaClientInterface` for that connection — exactly the type
direct callers (controllers, jobs) need.

## Method resolution

When you call `Opcua::read('...')` **without** `connection(...)`,
the facade dispatches the method to the **default** connection.
The default is the value of `'default'` in `config/opcua.php`.

These two are equivalent:

<!-- @code-block language="php" label="equivalent calls" -->
```php
Opcua::read('ns=2;s=Speed');
Opcua::connection('plc-line-a')->read('ns=2;s=Speed');  // assuming default = 'plc-line-a'
```
<!-- @endcode-block -->

## Caching

Each connection resolves to **one client instance per request**.
The first `Opcua::connection('plc-line-b')` opens the connection
and caches it on the `OpcuaManager`; subsequent calls in the same
request return the same client.

Under Octane / FrankenPHP, "per request" becomes "per worker
boot" by default. See [Connection lifecycle](./connection-lifecycle.md)
and [Octane integration](../integrations/octane-and-frankenphp.md)
for the details.

## Closing one specific connection

<!-- @code-block language="php" label="disconnect" -->
```php
Opcua::disconnect('plc-line-b');
```
<!-- @endcode-block -->

Closes the cached client for `plc-line-b`. The next
`Opcua::connection('plc-line-b')` will open a fresh connection.

## Closing all connections

<!-- @code-block language="php" label="disconnect all" -->
```php
Opcua::disconnectAll();
```
<!-- @endcode-block -->

Useful in long-running workers to enforce a recycle. Typically
not needed in request-per-process FPM.

## When the name is dynamic

If the connection name depends on request input (multi-tenant
routing), keep the **lookup** in middleware or a service:

<!-- @code-block language="php" label="multi-tenant routing" -->
```php
class OpcuaConnectionResolver
{
    public function forTenant(int $tenantId): string
    {
        return "plc-tenant-{$tenantId}";
    }
}

class TagsController
{
    public function show(Request $request, OpcuaConnectionResolver $r): JsonResponse
    {
        $name = $r->forTenant($request->user()->tenant_id);

        $dv = Opcua::connection($name)->read($request->input('node'));

        return response()->json(['value' => $dv->getValue()]);
    }
}
```
<!-- @endcode-block -->

This is the **right** shape — keep the routing logic in one
place. Don't sprinkle `connection("plc-tenant-{$tenantId}")` calls
throughout the codebase.

## When the connection doesn't exist

`Opcua::connection('typo')` throws
`InvalidArgumentException` immediately — the package validates
names against the `connections` map at resolution time.

This is a **boot-time** error, not a runtime one — you find typos
on the first call, not on the millionth.

## Listing configured connections

<!-- @code-block language="php" label="list connections" -->
```php
$names = array_keys(config('opcua.connections'));
// ['default', 'plc-line-a', 'plc-line-b', 'historian']
```
<!-- @endcode-block -->

Useful for healthcheck endpoints that probe each connection:

<!-- @code-block language="php" label="health endpoint" -->
```php
Route::get('/health/opcua', function () {
    $results = [];
    foreach (array_keys(config('opcua.connections')) as $name) {
        try {
            Opcua::connection($name)->read('i=2256');  // Server_ServerStatus
            $results[$name] = 'ok';
        } catch (\Throwable $e) {
            $results[$name] = 'unhealthy: ' . class_basename($e);
        }
    }
    return response()->json($results);
});
```
<!-- @endcode-block -->

## Naming conventions

Recap from [Connections](../configuration/connections.md):

- Lowercase, kebab-case.
- Role-based (`plc-line-a`) preferred over instance-based
  (`plc-192-168-1-10`).
- Include tenancy in the name (`plc-tenant-acme`) if relevant.

## Where to read next

- [Ad-hoc connections](./ad-hoc-connections.md) — when you can't
  name everything ahead of time.
- [Connection lifecycle](./connection-lifecycle.md) — open, close,
  reuse, error.
