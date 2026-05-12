---
eyebrow: 'Docs · Getting started'
lede:    'composer require, vendor:publish, set OPCUA_ENDPOINT in .env, done. The package auto-registers — no service provider to add by hand.'

see_also:
  - { href: './quick-start.md',                  meta: '3 min' }
  - { href: '../configuration/config-file.md',   meta: '6 min' }
  - { href: '../configuration/environment-variables.md', meta: '4 min' }

prev: { label: 'Overview',    href: '../overview.md' }
next: { label: 'Quick start', href: './quick-start.md' }
---

# Installation

`php-opcua/laravel-opcua` installs through Composer with Laravel's
package auto-discovery. No service-provider registration, no
facade alias to add manually — the `composer.json` `extra.laravel`
block does both.

## Requirements

| Component             | Version                                          |
| --------------------- | ------------------------------------------------ |
| PHP                   | ≥ 8.2                                            |
| Laravel               | 11.x, 12.x, or 13.x                              |
| `ext-openssl`         | Required (transitively from `opcua-client`)      |

Tested on every Laravel version listed; CI runs the test suite
against all three on PHP 8.2 / 8.3 / 8.4 / 8.5.

## Install

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/laravel-opcua
```
<!-- @endcode-block -->

Composer pulls in:

- `php-opcua/opcua-client` — the OPC UA stack
- `php-opcua/opcua-session-manager` — the optional daemon
- `psr/log`, `psr/event-dispatcher`, `psr/simple-cache` — the
  interfaces Laravel implements

Laravel auto-discovery wires up the service provider and the
`Opcua` facade. No further changes to `config/app.php` or
`bootstrap/providers.php`.

## Publish the configuration

<!-- @code-block language="bash" label="terminal — publish config" -->
```bash
php artisan vendor:publish --tag=opcua-config
```
<!-- @endcode-block -->

This copies `config/opcua.php` into your application. Edit it as
needed; see [Configuration · The config file](../configuration/config-file.md)
for the full walkthrough.

You can skip the publish step if the defaults work for you — the
package merges its config from inside the vendor directory.

## Configure the endpoint

Edit `.env` and set at minimum the OPC UA endpoint URL:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_ENDPOINT=opc.tcp://plc.local:4840
```
<!-- @endcode-block -->

Everything else has sensible defaults:

| Variable                       | Default                                      | Effect                                              |
| ------------------------------ | -------------------------------------------- | --------------------------------------------------- |
| `OPCUA_ENDPOINT`               | `opc.tcp://localhost:4840`                    | The OPC UA server to connect to                     |
| `OPCUA_SECURITY_POLICY`        | `None`                                        | Channel security policy                             |
| `OPCUA_SECURITY_MODE`          | `None`                                        | Channel security mode                               |
| `OPCUA_USERNAME` / `OPCUA_PASSWORD` | unset                                    | Anonymous session if both empty                     |
| `OPCUA_TIMEOUT`                | `5.0`                                          | Per-call timeout                                    |
| `OPCUA_SESSION_MANAGER_ENABLED`| `true`                                        | Use the daemon when reachable                       |

See [Environment variables](../configuration/environment-variables.md)
for the full table.

## Verify

In Tinker:

<!-- @code-block language="bash" label="terminal — tinker" -->
```bash
php artisan tinker
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="tinker session" -->
```php
>>> use PhpOpcua\LaravelOpcua\Facades\Opcua;
>>> Opcua::isSessionManagerRunning();
=> false                                  // expected when daemon isn't started

>>> Opcua::read('i=2261')->getValue();    // server product name (well-known)
=> "open62541 OPC UA Server"               // or whichever server you targeted
```
<!-- @endcode-block -->

If the read returns a value, the package is wired correctly and
your `.env` matches a reachable server.

If the read raises an exception, see the error and consult:

- `ServiceException` with `getStatusCode()` →
  `StatusCode::getName($code) === 'BadNodeIdUnknown'` —
  the server is up but `i=2261` isn't there. Rare; almost every
  server publishes this. (Note: `BadNodeIdUnknown` is the **name
  of the status code**, not an exception class — the exception
  raised is `ServiceException`.)
- `ConnectionException` — host unreachable, port wrong, server
  down. Fix the URL.
- `UntrustedCertificateException` — server is secured; see
  [Security · Trust store](../security/trust-store.md).

## Optional — start the session manager

For request-driven applications (Laravel HTTP handlers, queue
workers), the session-manager daemon keeps OPC UA sessions alive
across requests. Without it, every request opens a new session.

Start in the foreground for development:

<!-- @code-block language="bash" label="terminal — daemon" -->
```bash
php artisan opcua:session
```
<!-- @endcode-block -->

Leave it running while you develop. In production, supervise with
systemd or Laravel Sail / Octane / Horizon's process manager —
see [Session manager · Production
supervisor](../session-manager/production-supervisor.md).

## Optional — install companion-spec types

If your servers implement OPC Foundation companion specs
(Machinery, Robotics, BACnet, MachineTool, …), pull the
pre-generated PHP types:

<!-- @code-block language="bash" label="terminal — nodeset" -->
```bash
composer require php-opcua/opcua-client-nodeset
```
<!-- @endcode-block -->

51 companion specs come pre-generated. Load specific ones via
the OPC UA client builder — see [Recipes · Using companion specs](../recipes/using-companion-specs.md).

## What now

Three readable next steps:

1. [Quick start](./quick-start.md) — `Opcua::read()` in a Laravel
   controller, three minutes.
2. [How laravel-opcua fits](./how-laravel-opcua-fits.md) — the
   mental model. Read this once; it pays off forever.
3. [The config file](../configuration/config-file.md) — every
   knob, one page.
