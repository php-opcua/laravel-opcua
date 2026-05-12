---
eyebrow: 'Docs · Reference'
lede:    'The artisan commands the package ships. Signatures, options, common invocations.'

see_also:
  - { href: '../session-manager/starting-the-daemon.md',  meta: '5 min' }
  - { href: '../security/trust-store.md',                meta: '6 min' }
  - { href: '../security/certificates.md',               meta: '7 min' }

prev: { label: 'OpcuaManager API', href: './opcua-manager-api.md' }
next: { label: 'Exceptions',       href: './exceptions.md' }
---

# Artisan commands

The package ships **one** artisan command. Earlier drafts of these
docs listed `opcua:ping`, `opcua:trust:*`, `opcua:discover`, and
`opcua:cert:check` — those are not registered by
`OpcuaServiceProvider`. For trust-store inspection and endpoint
discovery, use the companion [`opcua-cli`](https://github.com/php-opcua/opcua-cli)
package, or call the equivalent facade methods
(`Opcua::trustCertificate()`, `Opcua::untrustCertificate()`,
`Opcua::getEndpoints()`) from a custom command.

| Command         | Purpose                                                |
| --------------- | ------------------------------------------------------ |
| `opcua:session` | Run the OPC UA session daemon                          |

## opcua:session

Start the session-manager daemon. The command resolves the daemon's
PSR-3 logger and PSR-16 cache from the Laravel container (using
`app('log')->channel(...)` and `app('cache')->store(...)`) and runs
the daemon in the foreground.

<!-- @code-block language="bash" label="signature" -->
```bash
php artisan opcua:session
    [--timeout=N]
    [--cleanup-interval=N]
    [--max-sessions=N]
    [--socket-mode=MODE]
    [--log-channel=NAME]
    [--cache-store=NAME]
```
<!-- @endcode-block -->

| Option                 | Description                                                                  |
| ---------------------- | ---------------------------------------------------------------------------- |
| `--timeout=N`          | Session inactivity timeout in seconds (default: `config('opcua.session_manager.timeout')`) |
| `--cleanup-interval=N` | Cleanup-check interval in seconds                                            |
| `--max-sessions=N`     | Maximum concurrent sessions                                                  |
| `--socket-mode=MODE`   | Unix-socket file permissions, in octal (e.g. `0600`)                         |
| `--log-channel=NAME`   | Override the Laravel log channel used by the daemon                          |
| `--cache-store=NAME`   | Override the Laravel cache store used by the daemon's client                 |

All other daemon parameters — `socket_path`, `auth_token`,
`allowed_cert_dirs`, `auto_publish`, the auto-connect connection
list — come **from `config/opcua.php`**, not from CLI flags. There is
no `--socket-path`, `--auth-token`, `--allowed-cert-dirs`,
`--auto-publish`, or `--no-auto-connect` option.

When `config('opcua.session_manager.auto_publish')` is `true` and any
connection has `auto_connect => true` plus a `subscriptions` array,
the command calls `Daemon::autoConnect(...)` after the daemon is
created — see [Session manager · Auto-publish](../session-manager/auto-publish.md).

Detailed walkthrough: [Starting the daemon](../session-manager/starting-the-daemon.md).

## Common invocations

| Goal                                          | Command                                                |
| --------------------------------------------- | ------------------------------------------------------ |
| Start daemon in dev                           | `php artisan opcua:session`                            |
| Start daemon with a specific log channel      | `php artisan opcua:session --log-channel=opcua`        |
| Start daemon with a stricter session timeout  | `php artisan opcua:session --timeout=120`              |

For health checks, write a small command in your application that
calls `Opcua::isSessionManagerRunning()` (a socket-file existence
check on Unix) plus an `Opcua::connection()->read(...)` round-trip
against a known node.

## Custom commands

The package leaves room for app-specific commands. Common ones:

| Command (app-defined)       | Purpose                                          |
| --------------------------- | ------------------------------------------------ |
| `plc:discover`              | Walk address space, populate `plc_tags`          |
| `plc:sample`                | One-shot read across all configured tags         |
| `opcua:resubscribe`         | Re-create subscriptions after daemon restart     |
| `opcua:metrics`             | Push metrics to Prometheus / Datadog             |

See [Recipes](../recipes/persistent-tag-history.md) for examples.

## Where to read next

- [Exceptions](./exceptions.md) — the exception hierarchy this
  command can throw.
- [Trust store](../security/trust-store.md) — how to seed trusted
  server certificates programmatically.
