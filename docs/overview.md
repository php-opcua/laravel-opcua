---
eyebrow: 'Docs · Overview'
lede:    'OPC UA, the industrial automation protocol, exposed through Laravel''s service container with idioms you already know — facade, config, events, queues, channels. Talk to PLCs and SCADA servers from your Laravel app.'

see_also:
  - { href: './getting-started/installation.md',           meta: '3 min' }
  - { href: './getting-started/how-laravel-opcua-fits.md', meta: '8 min' }
  - { href: 'https://github.com/php-opcua/opcua-client',   meta: 'external', label: 'php-opcua/opcua-client' }

prev: { label: 'No previous page', href: '#' }
next: { label: 'Installation',     href: './getting-started/installation.md' }
---

# Overview

`php-opcua/laravel-opcua` integrates the pure-PHP OPC UA client
([`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client))
with the Laravel framework. You get a familiar service provider,
a fluent facade, configuration through `config/opcua.php`, events
through Laravel's dispatcher, log channels, cache stores, an
Artisan command for the optional session-manager daemon — every
Laravel idiom you'd expect.

## In one read

<!-- @code-block language="php" label="examples/controller.php" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

class DashboardController extends Controller
{
    public function speed(): float
    {
        return Opcua::read('ns=2;s=PLC/Speed')->getValue();
    }
}
```
<!-- @endcode-block -->

That is the whole API at the call site — typed reads, writes,
browses, subscriptions through the `Opcua` facade. No bootstrap
in the controller. Configuration is in `config/opcua.php` plus
your `.env`.

## What you get out of the box

| Component                                    | What it does                                                       |
| --------------------------------------------- | ------------------------------------------------------------------ |
| `OpcuaServiceProvider`                        | Auto-registered. Binds the manager, wires logger / cache / event dispatcher from your container. |
| `Opcua` facade                                | Drop-in proxy to the OPC UA client with full IDE autocompletion    |
| `OpcuaManager`                                | The container singleton — typed dependency-injection target        |
| `config/opcua.php`                            | Connections, security, session manager, logging — one config file  |
| `opcua:session` Artisan command               | Starts the optional session-manager daemon                          |
| 47 PSR-14 events                              | Flow through your `EventServiceProvider` like any other Laravel event |
| Log channels                                  | OPC UA logging routes through `config/logging.php`                  |
| Cache stores                                  | OPC UA caching routes through `config/cache.php`                    |

Nothing magic. Every integration point is the standard Laravel
mechanism — bindings in the container, listeners in your
`EventServiceProvider`, channels in `logging.php`, stores in
`cache.php`.

## When to reach for it

Reach for `laravel-opcua` when you're building Laravel
applications that:

- **Read PLC tags** in controllers, jobs, or commands.
- **Persist sensor data** to a database on a schedule.
- **React to alarms** with notifications (Slack, mail, Telegram).
- **Show real-time data** in Livewire / Inertia / Filament UIs.
- **Expose OPC UA values** through an API (resources, JSON).

Skip it when:

- **You're not writing a Laravel app.** The
  [`opcua-client`](https://github.com/php-opcua/opcua-client) library
  is fine on its own.
- **You only need one-off CLI use.** The
  [`opcua-cli`](https://github.com/php-opcua/opcua-cli) tool is the
  terminal-first answer.

## The architecture, top-down

<!-- @code-block language="text" label="stack" -->
```text
Your Laravel application
  ↓
Opcua facade  /  injected OpcuaManager
  ↓
OpcuaManager (singleton in the service container)
  ↓
  ├─ Direct mode  ──→  ClientBuilder + Client (opcua-client)
  └─ Managed mode ──→  ManagedClient ──IPC──→ opcua:session daemon ──→ Client
                                                  (opcua-session-manager)
  ↓
OPC UA server (PLC, SCADA, gateway)
```
<!-- @endcode-block -->

Two modes, one API surface:

- **Direct mode** — every controller invocation opens an OPC UA
  session inline. Fine for low-traffic admin interfaces and CLI
  commands.
- **Managed mode** — connections live in the `opcua:session`
  daemon, persistent across requests. Recommended for any
  request-driven application. Auto-detected when the daemon is
  reachable; falls back to direct mode otherwise.

See [How laravel-opcua fits](./getting-started/how-laravel-opcua-fits.md)
for the depth.

## What's in this documentation

The structure mirrors how a Laravel developer typically navigates
a new package:

- **Getting started** — install, first read, mental model.
- **Configuration** — `config/opcua.php` end to end.
- **Using the client** — facade vs injection, the connection
  lifecycle, builders.
- **Operations** — read, write, browse, subscribe, history — one
  page per OPC UA service set.
- **Session manager** — the optional daemon, when and how.
- **Events** — 47 PSR-14 events through Laravel's dispatcher,
  with queued listeners and broadcasting.
- **Observability** — logging, caching, Telescope, Pulse.
- **Security** — policies, credentials, certificates, trust.
- **Testing** — Pest patterns, mocks, integration tests.
- **Integrations** — Octane, Horizon, Broadcasting, Livewire,
  Notifications, Filament. End-to-end runnable examples.
- **Reference** — facade methods, manager API, Artisan, exceptions.
- **Recipes** — full walkthroughs for the common Laravel-OPCUA
  combinations.

## Ecosystem

| Package                                                                                  | Role                                                          |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)                    | The OPC UA client library this package wraps                  |
| **`php-opcua/laravel-opcua`** (this package)                                              | Laravel integration                                            |
| [`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)  | Optional daemon for persistent sessions                       |
| [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli)                          | Terminal tool (works alongside or independent of Laravel)     |
| [`php-opcua/opcua-client-nodeset`](https://github.com/php-opcua/opcua-client-nodeset)    | Pre-generated PHP types for 51 OPC Foundation companion specs |

The Laravel integration is intentionally thin — it composes the
above into Laravel-idiomatic surfaces. Drop into any layer when
you need finer control.
