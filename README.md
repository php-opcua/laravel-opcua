<h1 align="center"><strong>OPC UA Laravel Client</strong></h1>

<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
    <img alt="OPC UA Laravel Client" src="assets/logo-light.svg" width="435">
  </picture>
</div>

<p align="center">
  <a href="https://github.com/php-opcua/laravel-opcua/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/php-opcua/laravel-opcua/tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/php-opcua/laravel-opcua"><img src="https://img.shields.io/codecov/c/github/php-opcua/laravel-opcua?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/php-opcua/laravel-opcua"><img src="https://img.shields.io/packagist/v/php-opcua/laravel-opcua?style=flat-square&label=packagist" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/php-opcua/laravel-opcua"><img src="https://img.shields.io/packagist/php-v/php-opcua/laravel-opcua?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/php-opcua/laravel-opcua?style=flat-square" alt="License"></a>
</p>

---

Laravel integration for [OPC UA](https://opcfoundation.org/about/opc-technologies/opc-ua/) built on [`opcua-client`](https://github.com/php-opcua/opcua-client) and [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager). Connect your Laravel app to PLCs, SCADA systems, sensors, and IoT devices with a familiar developer experience: a `Facade`, `.env`-based configuration, named connections (like `config/database.php`), and an Artisan command for the optional session manager daemon.

**What you get:**

- **Facade** — `Opcua::read('i=2259')` with full IDE autocompletion
- **Named connections** — define multiple OPC UA servers and switch between them, just like database connections
- **Transparent session management** — when the daemon is running, connections persist across HTTP requests; when it's not, direct per-request connections with zero code changes
- **Laravel-native logging and caching** — your log channel and cache store are automatically injected into every OPC UA client
- **All OPC UA operations** — browse, read, write, method calls, subscriptions, events, history, path resolution, type discovery
- **PSR-14 events** — 47 dispatched events covering every OPC UA operation for observability and extensibility
- **Trust store** — certificate trust management with configurable policies and auto-accept modes
- **Write auto-detection** — omit the type parameter and let the client detect the correct OPC UA type automatically

> **Note:** This package wraps the full [opcua-client](https://github.com/php-opcua/opcua-client) API with Laravel conventions. For the underlying protocol details, types, and advanced features, see the [client documentation](https://github.com/php-opcua/opcua-client/tree/master/doc).

<table>
<tr>
<td>

### Tested against the OPC UA reference implementation

The underlying [opcua-client](https://github.com/php-opcua/opcua-client) is integration-tested against **[UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard)** — the **reference implementation** maintained by the OPC Foundation, the organization that defines the OPC UA specification. This is the same stack used by major industrial vendors to certify their products.

This Laravel package is additionally integration-tested via [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) in both direct and managed (daemon) modes, ensuring full compatibility across all connection strategies.

</td>
</tr>
</table>

## Quick Start

```bash
composer require php-opcua/laravel-opcua
```

```dotenv
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
```

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect();

$value = $client->read('i=2259');
echo $value->getValue(); // 0 = Running

$client->disconnect();
```

That's it. Facade, `.env`, connect, read. Everything else is optional.

## See It in Action

### Browse the address space

```php
$client = Opcua::connect();

$refs = $client->browse('i=85'); // Objects folder
foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId})\n";
}

$client->disconnect();
```

### Read multiple values with fluent builder

```php
$client = Opcua::connect();

$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->execute();

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

$client->disconnect();
```

### Write to a PLC

```php
use PhpOpcua\Client\Types\BuiltinType;

$client = Opcua::connect();
$client->write('ns=2;i=1001', 42, BuiltinType::Int32);

// Or let the client auto-detect the type
$client->write('ns=2;i=1001', 42);

$client->disconnect();
```

### Call a method on the server

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$client = Opcua::connect();

$result = $client->call(
    'i=2253',   // Server object
    'i=11492',  // Method
    [new Variant(BuiltinType::UInt32, 1)],
);

echo $result->statusCode;               // 0
echo $result->outputArguments[0]->value; // [1001, 1002, ...]

$client->disconnect();
```

### Subscribe to data changes

```php
$client = Opcua::connect();

$sub = $client->createSubscription(publishingInterval: 500.0);
$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001'],
]);

$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo $notif['dataValue']->getValue() . "\n";
}

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

### Switch connections

```php
// Named connection from config
$client = Opcua::connect('plc-line-1');
$value = $client->read('ns=2;i=1001');
Opcua::disconnect('plc-line-1');

// Ad-hoc connection at runtime
$client = Opcua::connectTo('opc.tcp://10.0.0.50:4840', [
    'username' => 'operator',
    'password' => 'secret',
], as: 'temp-plc');

Opcua::disconnectAll();
```

### Test without a real server

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0));

// Inject into OpcuaManager via DI or reflection
$value = $mock->read('i=2259');
echo $value->getValue(); // 0
echo $mock->callCount('read'); // 1
```

## Features

| Feature | What it does |
|---|---|
| **Facade** | `Opcua::read()`, `Opcua::browse()`, etc. with full PHPDoc for IDE autocompletion |
| **Named Connections** | Define multiple servers in `config/opcua.php`, switch with `Opcua::connection('plc-2')` |
| **Ad-hoc Connections** | `Opcua::connectTo('opc.tcp://...')` for endpoints not in config |
| **Session Manager** | Artisan command `php artisan opcua:session` for daemon-based session persistence |
| **Transparent Fallback** | Daemon available? ManagedClient. Not available? Direct Client. Zero code changes |
| **String NodeIds** | `'i=2259'`, `'ns=2;s=MyNode'` everywhere a `NodeId` is accepted |
| **Fluent Builder API** | `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()` chain |
| **PSR-3 Logging** | Laravel's log channel injected automatically via config |
| **PSR-14 Events** | 47 dispatched events covering every OPC UA operation for observability and extensibility |
| **PSR-16 Caching** | Laravel's cache store injected automatically. Per-call `useCache` on browse ops |
| **Write Auto-Detection** | `write('ns=2;i=1001', 42)` — omit the type and the client detects it automatically |
| **Read Metadata Cache** | Cached node metadata avoids redundant reads; refresh on demand with `read($nodeId, refresh: true)` |
| **Trust Store** | `FileTrustStore` with configurable `TrustPolicy`, auto-accept modes, and certificate management |
| **Type Discovery** | `discoverDataTypes()` auto-detects custom server structures |
| **Subscription Management** | `createMonitoredItems()`, `modifyMonitoredItems()`, `setTriggering()`, `transferSubscriptions()` |
| **MockClient** | Test without a server — register handlers, assert calls |
| **Timeout & Retry** | Per-connection `timeout`, `auto_retry` via config or fluent API |
| **Auto-Batching** | `readMulti`/`writeMulti` transparently split when exceeding server limits |
| **Recursive Browse** | `browseAll()`, `browseRecursive()` with depth control and cycle detection |
| **Path Resolution** | `resolveNodeId('/Objects/Server/ServerStatus')` |
| **Security** | 6 policies, 3 auth modes, auto-generated certs, certificate trust management |
| **History Read** | Raw, processed, and at-time historical queries |
| **Typed Returns** | All service responses return `public readonly` DTOs |

## Documentation

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](doc/01-introduction.md) | Overview, requirements, architecture, quick start |
| 02 | [Installation & Configuration](doc/02-installation.md) | Composer, config file, `.env`, connections, session manager |
| 03 | [Usage](doc/03-usage.md) | Reading, writing, browsing, methods, subscriptions, history |
| 04 | [Connections](doc/04-connections.md) | Named, ad-hoc, switching, disconnect, dependency injection |
| 05 | [Session Manager](doc/05-session-manager.md) | Daemon, Artisan command, Supervisor, architecture |
| 06 | [Logging & Caching](doc/06-logging-caching.md) | PSR-3/PSR-16, Laravel integration, per-call cache control |
| 07 | [Security](doc/07-security.md) | Policies, modes, certificates, authentication |
| 08 | [Testing](doc/08-testing.md) | MockClient, DataValue factories, unit and integration tests |
| 09 | [Examples](doc/09-examples.md) | Complete code examples for all features |

## Testing

80+ unit tests with **99%+ code coverage**. Integration tests run against [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) — a Docker-based OPC UA environment built on the OPC Foundation's UA-.NETStandard reference implementation — in both direct and managed (daemon) modes.

```bash
./vendor/bin/pest tests/Unit/                              # unit only
./vendor/bin/pest tests/Integration/ --group=integration   # integration only
./vendor/bin/pest                                          # everything
```

## Ecosystem

| Package | Description                                                                                                                                                                                                                                                                                                         |
|---------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client                                                                                                                                                                                                                                                                                              |
| [opcua-cli](https://github.com/php-opcua/opcua-cli) | CLI tool — browse, read, write, watch, discover endpoints, manage certificates, generate code from NodeSet2.xml                                                                                                                                                                                                     |
| [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Daemon-based session persistence across PHP requests. Keeps OPC UA connections alive between short-lived PHP processes via a ReactPHP daemon and Unix sockets. Separate package by design — see [ROADMAP.md](ROADMAP.md#session-manager-integration-here) for rationale.                                            |
| [opcua-client-nodeset](https://github.com/php-opcua/opcua-client-nodeset) | Pre-generated PHP types from 51 OPC Foundation companion specifications (DI, Robotics, Machinery, MachineTool, ISA-95, CNC, MTConnect, and more). 807 PHP files — NodeId constants, enums, typed DTOs, codecs, registrars with automatic dependency resolution. Just `composer require` and `loadGeneratedTypes()`. |
| [laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration — service provider, facade, config (this package)                                                                                                                                                                                                                                                             |
| [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) | Docker-based OPC UA test servers (UA-.NETStandard) for integration testing                                                                                                                                                                                                                                          |

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## AI-Ready

This package ships with machine-readable documentation designed for AI coding assistants (Claude, Cursor, Copilot, ChatGPT, and others). Feed these files to your AI so it knows how to use the library correctly:

| File | Purpose |
|------|---------|
| [`llms.txt`](llms.txt) | Compact project summary — architecture, Facade, configuration, session manager. Optimized for LLM context windows with minimal token usage. |
| [`llms-full.txt`](llms-full.txt) | Comprehensive technical reference — every config key, method, DTO, event, trust store, managed client. For deep dives and complex questions. |
| [`llms-skills.md`](llms-skills.md) | Task-oriented recipes — step-by-step instructions for common tasks (install, read, write, browse, named connections, session manager, security, testing, events). Written so an AI can generate correct, production-ready code from a user's intent. |

**How to use:** copy the files you need into your project's AI configuration directory. The files are located in `vendor/php-opcua/laravel-opcua/` after `composer install`.

- **Claude Code**: reference per-session with `--add-file vendor/php-opcua/laravel-opcua/llms-skills.md`
- **Cursor**: copy into your project's rules directory — `cp vendor/php-opcua/laravel-opcua/llms-skills.md .cursor/rules/laravel-opcua.md`
- **GitHub Copilot**: copy or append the content into your project's `.github/copilot-instructions.md` file (create the file and directory if they don't exist). Copilot reads this file automatically for project-specific context
- **Other tools**: paste the content into your system prompt, project knowledge base, or context configuration

## Versioning

This package follows the same version numbering as [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client). Each release of `laravel-opcua` is aligned with the corresponding release of the client library to ensure full compatibility.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
