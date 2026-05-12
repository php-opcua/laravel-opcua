---
eyebrow: 'Docs · Using the client'
lede:    'Facade or dependency injection? Both resolve to the same singleton. Choose by ergonomics, not performance — and by what your tests need to do.'

see_also:
  - { href: './named-connections.md',    meta: '5 min' }
  - { href: './connection-lifecycle.md', meta: '5 min' }
  - { href: '../testing/mocking-the-facade.md', meta: '5 min' }

prev: { label: 'Publishing & overriding', href: '../configuration/publishing-overriding.md' }
next: { label: 'Named connections',       href: './named-connections.md' }
---

# Facade vs injection

`laravel-opcua` exposes its main service two ways:

- The **`Opcua` facade** — `use PhpOpcua\LaravelOpcua\Facades\Opcua`.
- **Constructor injection** of the underlying `OpcuaManager`.

They wrap the same singleton from the container. There's no
runtime cost difference. The choice is about who reads the code.

## The facade

<!-- @code-block language="php" label="facade — controller" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class TagsController
{
    public function show(string $nodeId): JsonResponse
    {
        $dv = Opcua::read($nodeId);

        return response()->json(['value' => $dv->getValue()]);
    }
}
```
<!-- @endcode-block -->

**Pros**

- Concise. No constructor boilerplate.
- Familiar Laravel idiom (everyone has used `Cache::get()`).
- `Opcua::shouldReceive()` / `Opcua::partialMock()` / `Opcua::spy()`
  in tests work out of the box — see [Mocking the facade](../testing/mocking-the-facade.md).

**Cons**

- Implicit dependency. A reader of `TagsController` can't see that
  it talks to OPC UA without scanning the body.
- Static analysis tools (Psalm/PHPStan) need the `@method`
  docblocks on the facade to resolve method types.

## Constructor injection

<!-- @code-block language="php" label="injection — controller" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

class TagsController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function show(string $nodeId): JsonResponse
    {
        $dv = $this->opcua->read($nodeId);

        return response()->json(['value' => $dv->getValue()]);
    }
}
```
<!-- @endcode-block -->

**Pros**

- Explicit dependency. The class signature documents what it
  needs.
- Easy to test — replace the bound instance in the container.
- Easier for static analysis. `OpcuaManager::read()` is a real
  method.

**Cons**

- More verbose.
- A reader who's used to `Cache::get()` has to look up
  `OpcuaManager` the first time.

## Same thing under the hood

`Opcua::read()` dispatches through Laravel's
`Facade::resolveFacadeInstance('opcua')`. The provider binds the
singleton on the **class string**, then aliases `'opcua'` to it:

<!-- @code-block language="php" label="OpcuaServiceProvider::register" -->
```php
$this->app->singleton(OpcuaManager::class, function ($app) {
    return new OpcuaManager(
        $app['config']['opcua'],
        $app->bound(LoggerInterface::class)        ? $app->make(LoggerInterface::class)        : null,
        $app->bound(CacheInterface::class)         ? $app->make(CacheInterface::class)         : null,
        $app->bound(EventDispatcherInterface::class) ? $app->make(EventDispatcherInterface::class) : null,
        /* loggerResolver: */ /* … */
    );
});

$this->app->alias(OpcuaManager::class, 'opcua');
```
<!-- @endcode-block -->

So `app(OpcuaManager::class)`, `app('opcua')`, and the `Opcua`
facade all resolve to the **same** singleton.

## When to prefer which

| Code shape                                  | Recommendation                | Reason                                                  |
| ------------------------------------------- | ----------------------------- | ------------------------------------------------------- |
| Controller, 1-2 OPC UA calls per action     | Facade                        | Concise; no setup cost                                  |
| Service class that's all OPC UA             | Injection                     | Signature documents the dependency                      |
| Eloquent model accessor                     | Facade                        | Models can't have constructor args bound from the container |
| Artisan command                             | Either                        | `Command::handle()` resolves args from the container    |
| Queued job                                  | **Avoid** holding either across `serialize` boundary — re-resolve inside `handle()` | The job is serialized onto the queue; the service is not serializable |
| Test target                                 | Either, but inject `OpcuaManager` if you want PHPUnit doubles | Facades use `Opcua::shouldReceive()` / `partialMock()` / `spy()`; classes use `Mockery::mock(OpcuaManager::class)` |

## Queued jobs — the gotcha

<!-- @callout type="warning" -->
**Don't store `OpcuaManager` as a job property.** Laravel
serialises job classes onto the queue; the manager holds open
connections, which are not serialisable.
<!-- @endcallout -->

<!-- @code-block language="php" label="job — wrong" -->
```php
class SamplePlc implements ShouldQueue
{
    public function __construct(private OpcuaManager $opcua) {}  // ❌
    public function handle(): void
    {
        $this->opcua->read('ns=2;s=Speed');
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="job — right" -->
```php
class SamplePlc implements ShouldQueue
{
    public function __construct(public string $nodeId) {}

    public function handle(OpcuaManager $opcua): void  // ✅ resolved at run time
    {
        $opcua->read($this->nodeId);
    }
}
```
<!-- @endcode-block -->

Resolve in `handle()`, never store across the queue boundary.

## Type-safety on the facade

The facade carries 50+ `@method` annotations matching the
underlying `OpcuaManager` surface. Psalm / PHPStan resolves
`Opcua::read('...')` to `DataValue`, `Opcua::write(...)` to
`bool`, and so on.

If your editor flags `Opcua::someMethod` as unknown, check the
facade's docblock for the most recent method list. Tools like
**IDE Helper** for Laravel can auto-generate fresh stubs:

<!-- @code-block language="bash" label="terminal — ide helper" -->
```bash
php artisan ide-helper:generate
```
<!-- @endcode-block -->

(That's [`barryvdh/laravel-ide-helper`](https://github.com/barryvdh/laravel-ide-helper),
not part of the package — but the facade plays nicely with it.)

## Mixed style is fine

A typical codebase uses the facade in controllers, the manager
class in services, and resolves through `handle($manager)` in
jobs. There's no rule. Optimise for the reader of each call site.

## Where to read next

- [Named connections](./named-connections.md) — switching between
  configured connections by name.
- [Mocking the facade](../testing/mocking-the-facade.md) — the
  testing surface.
