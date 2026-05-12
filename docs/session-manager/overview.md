---
eyebrow: 'Docs · Session manager'
lede:    'The session manager — a long-lived PHP daemon that holds OPC UA sessions on behalf of short-lived PHP processes. What it is, what it solves, when you need one, when you don''t.'

see_also:
  - { href: './starting-the-daemon.md',     meta: '5 min' }
  - { href: './auto-publish.md',            meta: '5 min' }
  - { href: '../using-the-client/connection-lifecycle.md', meta: '5 min' }

prev: { label: 'History', href: '../operations/history.md' }
next: { label: 'Starting the daemon', href: './starting-the-daemon.md' }
---

# Overview

`opcua-session-manager` is a long-running PHP process that holds
OPC UA sessions in memory. Other PHP processes (your Laravel
app, queue workers, Octane requests) talk to it over a local
IPC channel instead of opening sessions directly.

## Architecture

<!-- @code-block language="text" label="topology" -->
```text
┌──────────────────────────────────────────────────────────┐
│                  Laravel application                     │
│   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐      │
│   │  FPM req 1  │  │  FPM req 2  │  │ queue worker │      │
│   └──────┬──────┘  └──────┬──────┘  └──────┬──────┘      │
│          │                │                │             │
│          └────────────────┼────────────────┘             │
│                           │                              │
│                  unix socket / tcp                       │
└───────────────────────────┼──────────────────────────────┘
                            │
            ┌───────────────▼────────────────┐
            │       OPC UA Session Manager   │
            │     ┌────────┐ ┌────────┐      │
            │     │session1│ │session2│ ...  │   long-lived
            │     └───┬────┘ └───┬────┘      │
            └─────────┼──────────┼───────────┘
                      │          │
              ┌───────▼─┐    ┌───▼────────┐
              │ PLC #1  │    │  PLC #2    │
              └─────────┘    └────────────┘
```
<!-- @endcode-block -->

## What it solves

### 1 — Session reuse across requests

OPC UA session establishment is expensive — handshake, key
exchange under `Sign`/`SignAndEncrypt`, server-side allocation.
A typical secured connection takes 200-500 ms to open. In a
PHP-FPM app where every request opens fresh, latency adds up
fast.

The daemon opens once, reuses many. Two FPM requests targeting
the same PLC share one server-side session.

### 2 — Subscriptions that outlive requests

A `Subscription` in direct mode lives only as long as the PHP
process that created it. FPM requests are seconds. Subscriptions
need hours.

The daemon owns the subscription. Requests come and go; the
daemon polls publish responses continuously.

### 3 — One server-side identity per (endpoint, identity) tuple

OPC UA servers often cap concurrent sessions per client identity.
With direct mode, each FPM worker uses its own session — 50
workers ≈ 50 sessions, breaching the cap.

Daemon-held sessions are pooled. 50 workers, 1 daemon, 1 server-
side session (per endpoint+identity).

## When to use it

| Use case                                          | Direct mode | Managed mode    |
| ------------------------------------------------- | ----------- | --------------- |
| Single artisan command, one-shot read              | ✓           | overkill        |
| Scheduled job, every 5 minutes                    | ✓           | overkill        |
| FPM endpoint that reads occasionally               | ✓           | optional        |
| FPM endpoint, dozens of OPC UA calls per request  | optional    | **recommended** |
| Real-time subscription feeding a UI                | hard        | **required**    |
| Production app with 5+ FPM workers                 | optional    | **recommended** |
| Multi-tenant with 100+ connections                  | hard        | **recommended** |
| Server-side session limit < worker count           | breaks      | **required**    |

The rule of thumb: **direct mode for scripts, managed mode for
applications.**

## When NOT to use it

- Single-process tools where the OPC UA session needs to die
  with the process.
- Environments where you can't run a sidecar (some PaaS).
- Air-gapped tests where the additional moving part isn't
  worth it.

## How the Laravel package picks the mode

At `OpcuaManager::connection()` time:

1. **Is `session_manager.enabled` true in config?** If not →
   direct mode.
2. **Is the daemon reachable on `socket_path`?** Quick probe.
   If not → direct mode, log a warning.
3. **Otherwise** → managed mode.

Probe results are cached for a short time, so a slow daemon
doesn't impose per-call cost.

You can force a mode:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_SESSION_MANAGER_ENABLED=false      # force direct
```
<!-- @endcode-block -->

There is **no per-connection** session-manager toggle — the
`session_manager.enabled` flag is global. If you need one
connection to bypass the daemon while others use it, run a
second config or use `Opcua::connectTo()` from code with the
daemon disabled. (Earlier drafts mentioned a per-connection
`session_manager_enabled` key — the manager doesn't read it.)

## ManagedClient — the wrapper

In managed mode, the package returns `ManagedClient` instead of
`OpcuaClient`. They implement the same `OpcUaClientInterface`,
so application code doesn't care.

What `ManagedClient` does internally:

1. Talks to the daemon over IPC.
2. Each call (`read`, `write`, …) gets serialised to a JSON
   envelope, sent to the daemon, decoded on the other side.
3. The daemon dispatches to its in-memory session.

The wire format is documented under
[IPC · Envelope and framing](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/ipc/envelope-and-framing.md).

## The daemon's lifecycle

The daemon is **independent of Laravel**. It's a PHP CLI process
that:

- Listens on a Unix socket or TCP port.
- Holds OPC UA sessions.
- Optionally publishes subscription events (auto-publish).

You launch it with `php artisan opcua:session` (Laravel-wired) or
`vendor/bin/opcua-session-manager` (raw CLI). In production, run
it under Supervisor / systemd — see
[Production supervisor](./production-supervisor.md).

## Per-deployment topology

A typical production topology:

| Component             | Process count | Notes                                  |
| --------------------- | ------------- | -------------------------------------- |
| Laravel FPM           | 8-32 workers  | Talk to the daemon                     |
| Laravel queue workers | 4-16 workers  | Talk to the daemon                     |
| Octane / FrankenPHP   | 4-16 workers  | Talk to the daemon                     |
| OPC UA session daemon | **1**         | Single tenant, holds all sessions      |
| Horizon / Reverb      | 1 each        | Optional, broadcast subscription data  |

Single daemon, many clients.

## Multi-tenant deployments

For a hard-isolation multi-tenant deployment, run **one daemon
per tenant**, each on its own socket. The package supports
multiple socket paths by config — see
[Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md).

For shared deployments where tenant isolation is at the OPC UA
identity layer (different username/cert per tenant connection),
one daemon is sufficient.

## Where to read next

- [Starting the daemon](./starting-the-daemon.md) — the artisan
  command and its options.
- [Auto-publish](./auto-publish.md) — the subscription event
  bridge.
- [Production supervisor](./production-supervisor.md) — systemd,
  Supervisor, Horizon process orchestration.
