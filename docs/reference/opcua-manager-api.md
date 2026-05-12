---
eyebrow: 'Docs · Reference'
lede:    'The OpcuaManager class — same surface as the facade, accessed via dependency injection. Constructor, container binding, and the connection-management methods.'

see_also:
  - { href: './facade-methods.md',                          meta: '5 min' }
  - { href: '../using-the-client/facade-vs-injection.md',   meta: '5 min' }

prev: { label: 'Facade methods',  href: './facade-methods.md' }
next: { label: 'Artisan commands', href: './artisan-commands.md' }
---

# OpcuaManager API

`PhpOpcua\LaravelOpcua\OpcuaManager` is the class behind the `Opcua`
facade. Inject it directly when you want an explicit dependency:

<!-- @code-block language="php" label="injection" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

class TagService
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function getSpeed(): float
    {
        return (float) $this->opcua->read('ns=2;s=Speed')->getValue();
    }
}
```
<!-- @endcode-block -->

## Constructor

<!-- @code-block language="php" label="constructor" -->
```php
public function __construct(
    protected array $config,
    protected ?LoggerInterface $defaultLogger = null,
    protected ?CacheInterface $defaultCache = null,
    protected ?EventDispatcherInterface $defaultEventDispatcher = null,
    protected ?\Closure $loggerResolver = null,
);
```
<!-- @endcode-block -->

| Parameter                  | Type                          | Description                                                |
| -------------------------- | ----------------------------- | ---------------------------------------------------------- |
| `$config`                  | `array`                       | The contents of `config('opcua')`                          |
| `$defaultLogger`           | `?LoggerInterface`            | PSR-3 logger — Laravel's logger is auto-resolved by the provider |
| `$defaultCache`            | `?CacheInterface`             | PSR-16 cache — Laravel's default cache by default          |
| `$defaultEventDispatcher`  | `?EventDispatcherInterface`   | PSR-14 dispatcher — Laravel's `Illuminate\Events\Dispatcher` implements PSR-14, so events flow through `Event::listen(...)` natively |
| `$loggerResolver`          | `?\Closure(string): ?LoggerInterface` | Resolves a per-connection `log_channel` string to a logger. The provider wires this to `app('log')->channel($name)` |

All parameters after `$config` are **optional and nullable**. You
rarely construct `OpcuaManager` directly — the
`OpcuaServiceProvider` binds it as a singleton.

## Methods

The full per-method documentation lives under
[Facade methods](./facade-methods.md). The manager itself defines a
small set of connection-management methods; everything else is
proxied to the default client via `__call()`.

### Manager-defined methods

| Method                                                                                                  | Returns                       |
| ------------------------------------------------------------------------------------------------------- | ----------------------------- |
| `connection(?string $name = null)`                                                                      | `OpcUaClientInterface`        |
| `connect(?string $name = null)`                                                                         | `OpcUaClientInterface`        |
| `connectTo(string $endpointUrl, array $config = [], ?string $as = null)`                                | `OpcUaClientInterface`        |
| `disconnect(?string $name = null)`                                                                      | `void`                        |
| `disconnectAll()`                                                                                       | `void`                        |
| `isSessionManagerRunning()`                                                                             | `bool`                        |
| `getDefaultConnection()`                                                                                | `string`                      |
| `setLogger(LoggerInterface $logger)`                                                                    | `self` (runtime logger override) |
| `useConsoleLogger(OutputInterface $output, array $verbosityMap = [], array $formatLevelMap = [], ?string $dateFormat = 'Y-m-d H:i:s.v')` | `self` (Symfony ConsoleLogger wired to a command) |
| `getLogger()`                                                                                           | `?LoggerInterface` (the runtime override) |

The package does **not** ship the following methods that earlier
versions of this page documented: `setMockConnection`, `daemonStats`,
`getConnectionNames`, `purgeMetadataCache`, `setCache`,
`setEventDispatcher`. For mock injection, override the container
binding instead (see "Replacing the binding" below).

### setLogger / useConsoleLogger

`setLogger()` installs a runtime override that takes precedence over
per-connection `logger` / `log_channel` config keys, and is applied
to every cached client that exposes a `setLogger()` method.

`useConsoleLogger()` is a convenience that builds a Symfony
`ConsoleLogger` wired to a command's output (so `-v` / `-vv` / `-vvv`
verbosity flags control which log levels are shown) and installs it
via `setLogger()`. It throws `\RuntimeException` if
`symfony/console` is not installed.

## Container binding

The `OpcuaServiceProvider::register()` method registers:

```php
$this->app->singleton(OpcuaManager::class, function ($app) { ... });
$this->app->alias(OpcuaManager::class, 'opcua');
```

So the **primary binding** is on the class string `OpcuaManager::class`
and `'opcua'` is an alias pointing to that singleton.

| Resolution path                              | Returns the singleton? |
| -------------------------------------------- | ---------------------- |
| `app(OpcuaManager::class)`                   | Yes                    |
| `app('opcua')`                               | Yes (via alias)        |
| `resolve(OpcuaManager::class)`               | Yes                    |
| Constructor injection of `OpcuaManager`      | Yes                    |
| `Opcua` facade (resolves `'opcua'`)          | Yes                    |

## Replacing the binding

In tests, replace the singleton:

<!-- @code-block language="php" label="binding override" -->
```php
$this->app->instance(OpcuaManager::class, $mockManager);
// or
$this->app->singleton(OpcuaManager::class, fn () => $mockManager);
```
<!-- @endcode-block -->

Both update the facade and any future injection.

## Per-connection config keys (instance form)

In addition to the documented config keys (`endpoint`, `security_*`,
…), `configureBuilder()` / `configureManagedClient()` accept these
**instance** values inside an individual connection's config array:

| Key                | Type                                  | Notes                                          |
| ------------------ | ------------------------------------- | ---------------------------------------------- |
| `logger`           | `LoggerInterface` instance            | Overrides any `log_channel` for the connection |
| `cache`            | `CacheInterface` instance \| `null`   | `null` disables the default cache              |
| `event_dispatcher` | `EventDispatcherInterface` instance   | Overrides the default dispatcher               |

You typically set these from a service provider, not from
`config/opcua.php` (which is cached and shouldn't hold object
instances).

## Magic `__call`

Calls to undefined methods on `OpcuaManager` forward to the default
connection's client:

<!-- @code-block language="php" label="magic forwarding" -->
```php
$opcua->read('ns=2;s=Speed');
// is internally:
$opcua->connection()->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

That is how `Opcua::read()` works without the manager defining a
`read()` method explicitly.

## Events fired

The manager does **not** dispatch any custom events of its own (no
`BeforeConnect` / `AfterConnect` / `BeforeDisconnect` exist in the
source). All OPC UA lifecycle events come from the underlying
`opcua-client` library — see
[Events · Overview](../events/overview.md) for the catalogue.

Because the provider passes Laravel's `Illuminate\Events\Dispatcher`
(which implements `Psr\EventDispatcher\EventDispatcherInterface`) to
the manager, every PSR-14 event the client dispatches is delivered to
Laravel listeners registered with `Event::listen(...)` —
**no bridge class is required**.

## Where to read next

- [Artisan commands](./artisan-commands.md) — the CLI surface.
- [Exceptions](./exceptions.md) — what can be thrown.
