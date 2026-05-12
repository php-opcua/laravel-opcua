---
eyebrow: 'Docs · Configuration'
lede:    'Every OPCUA_* environment variable the package reads. .env-first by design — production deployments rarely need to touch config/opcua.php at all.'

see_also:
  - { href: './config-file.md',              meta: '6 min' }
  - { href: '../security/credentials.md',    meta: '5 min' }
  - { href: '../recipes/production-deployment.md', meta: '6 min' }

prev: { label: 'Connections',         href: './connections.md' }
next: { label: 'Security',            href: './security.md' }
---

# Environment variables

`config/opcua.php` reads its values from `env(...)` by default.
For most deployments, a `.env` file (or its production
equivalent) is the only place you need to touch.

## Connection — the basics

| Variable                  | Default                   | Where                                   |
| ------------------------- | ------------------------- | --------------------------------------- |
| `OPCUA_CONNECTION`        | `default`                 | Picks the default connection name       |
| `OPCUA_ENDPOINT`          | `opc.tcp://localhost:4840`| Default connection's endpoint URL       |
| `OPCUA_TIMEOUT`           | `5.0`                     | Per-call timeout (seconds)              |
| `OPCUA_AUTO_RETRY`        | `null`                    | Auto-retry count for transient failures |
| `OPCUA_BATCH_SIZE`        | `null` (auto)             | Override the server-advertised batch size |
| `OPCUA_BROWSE_MAX_DEPTH`  | `10`                      | Default depth for `browseRecursive()`   |

## Security

| Variable                      | Default | Effect                                                    |
| ----------------------------- | ------- | --------------------------------------------------------- |
| `OPCUA_SECURITY_POLICY`       | `None`  | Algorithm suite — see [Security · Policies](../security/policies-and-modes.md) |
| `OPCUA_SECURITY_MODE`         | `None`  | `None`, `Sign`, `SignAndEncrypt`                          |
| `OPCUA_CLIENT_CERT`           | unset   | Path to client (application) certificate PEM              |
| `OPCUA_CLIENT_KEY`            | unset   | Path to client (application) private key PEM              |
| `OPCUA_CA_CERT`               | unset   | Path to CA bundle for chain validation                    |
| `OPCUA_USER_CERT`             | unset   | Path to user-identity X.509 cert                          |
| `OPCUA_USER_KEY`              | unset   | Path to user-identity private key                         |

## Authentication

| Variable                      | Default | Effect                                                    |
| ----------------------------- | ------- | --------------------------------------------------------- |
| `OPCUA_USERNAME`              | unset   | Username for session-level identity                       |
| `OPCUA_PASSWORD`              | unset   | Password for session-level identity                       |

## Trust store

| Variable                      | Default | Effect                                                    |
| ----------------------------- | ------- | --------------------------------------------------------- |
| `OPCUA_TRUST_STORE_PATH`      | unset   | Where the trust store lives. Default: `~/.opcua/` POSIX, `%APPDATA%\opcua\` Windows |
| `OPCUA_TRUST_POLICY`          | unset   | `fingerprint`, `fingerprint+expiry`, `full`               |
| `OPCUA_AUTO_ACCEPT`           | `false` | TOFU mode — accept unknown certs on first contact         |
| `OPCUA_AUTO_ACCEPT_FORCE`     | `false` | Re-accept previously-rejected certs (operator override)   |

See [Security · Trust store](../security/trust-store.md).

## Library behaviour flags

| Variable                            | Default | Effect                                              |
| ----------------------------------- | ------- | --------------------------------------------------- |
| `OPCUA_AUTO_DETECT_WRITE_TYPE`      | `true`  | Auto-detect `BuiltinType` on `write()`              |
| `OPCUA_READ_METADATA_CACHE`         | `false` | Cache non-Value attribute reads                     |

## Session manager

| Variable                          | Default                                            | Effect                                                 |
| --------------------------------- | -------------------------------------------------- | ------------------------------------------------------ |
| `OPCUA_SESSION_MANAGER_ENABLED`   | `true`                                             | Master switch — `false` forces direct mode            |
| `OPCUA_SOCKET_PATH`               | per-OS default (storage_path or `tcp://127.0.0.1:9990`) | IPC endpoint URI                                  |
| `OPCUA_SESSION_TIMEOUT`           | `600`                                              | Session inactivity timeout (seconds)                   |
| `OPCUA_CLEANUP_INTERVAL`          | `30`                                               | Daemon cleanup loop interval (seconds)                 |
| `OPCUA_AUTH_TOKEN`                | unset                                              | Shared secret for IPC auth                             |
| `OPCUA_MAX_SESSIONS`              | `100`                                              | Cap on concurrent daemon-held sessions                 |
| `OPCUA_AUTO_PUBLISH`              | `false`                                            | Daemon auto-publishes subscriptions to PSR-14 events  |

## Logging and caching

| Variable                | Default                                 | Effect                                                   |
| ----------------------- | --------------------------------------- | -------------------------------------------------------- |
| `OPCUA_LOG_CHANNEL`     | falls back to `LOG_CHANNEL` (`stack`)   | Laravel log channel for the daemon's output              |
| `OPCUA_CACHE_STORE`     | falls back to `CACHE_STORE` (`file`)    | Laravel cache store the daemon uses                      |

These two land on the daemon side. The per-connection
`log_channel` in `config/opcua.php` is **client-side** and not
exposed via env var by default.

## A complete `.env` for development

<!-- @code-block language="bash" label=".env (dev)" -->
```bash
APP_ENV=local
APP_DEBUG=true

OPCUA_ENDPOINT=opc.tcp://localhost:4840
OPCUA_SESSION_MANAGER_ENABLED=true
```
<!-- @endcode-block -->

Minimal. Everything else takes defaults.

## A complete `.env` for staging

<!-- @code-block language="bash" label=".env (staging)" -->
```bash
APP_ENV=staging
APP_DEBUG=false

OPCUA_ENDPOINT=opc.tcp://staging-plc.internal:4840
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_USERNAME=integrations
OPCUA_PASSWORD="${SECRETS_OPCUA_PASS}"     # from secret manager

OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key

OPCUA_TRUST_STORE_PATH=/var/lib/opcua/trust
OPCUA_TRUST_POLICY=fingerprint+expiry

OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_AUTH_TOKEN="${SECRETS_OPCUA_AUTH_TOKEN}"
OPCUA_LOG_CHANNEL=opcua
OPCUA_CACHE_STORE=redis
```
<!-- @endcode-block -->

Sensitive values come from a secrets manager (Vault, AWS
Secrets Manager, Doppler, …). The package doesn't read from
those directly — Laravel does, via your standard secret-injection
mechanism.

## A complete `.env` for production

Same shape as staging, with values from your production secrets
store. Differences are typically:

- `OPCUA_TIMEOUT=10.0` for tolerance to occasional slowness
- `OPCUA_AUTO_RETRY=3` for transient failure resilience
- `OPCUA_AUTH_TOKEN` definitely set
- `OPCUA_TRUST_POLICY=fingerprint+expiry` enforced
- `OPCUA_AUTO_ACCEPT=false` to refuse new certs without operator
  intervention

See [Recipes · Production deployment](../recipes/production-deployment.md).

## env vars per-connection

The variables listed above feed the **default** connection in
`config/opcua.php`. For multiple connections, the package
doesn't enforce an env-var-per-connection scheme — it's your
choice in `config/opcua.php`:

<!-- @code-block language="php" label="multi-line — per-connection env" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint' => env('OPCUA_LINE_A_ENDPOINT'),
        'username' => env('OPCUA_LINE_A_USER'),
        'password' => env('OPCUA_LINE_A_PASS'),
    ],
    'plc-line-b' => [
        'endpoint' => env('OPCUA_LINE_B_ENDPOINT'),
        'username' => env('OPCUA_LINE_B_USER'),
        'password' => env('OPCUA_LINE_B_PASS'),
    ],
],
```
<!-- @endcode-block -->

`.env` matches:

<!-- @code-block language="bash" label=".env (multi-connection)" -->
```bash
OPCUA_LINE_A_ENDPOINT=opc.tcp://plc-a.factory.local:4840
OPCUA_LINE_A_USER=integrations
OPCUA_LINE_A_PASS="${SECRETS_PLC_A_PASS}"

OPCUA_LINE_B_ENDPOINT=opc.tcp://plc-b.factory.local:4840
OPCUA_LINE_B_USER=integrations
OPCUA_LINE_B_PASS="${SECRETS_PLC_B_PASS}"
```
<!-- @endcode-block -->

## Caching the config

`php artisan config:cache` freezes the resolved config in a
PHP file. Two consequences:

- **`env()` calls outside `config/*.php` return `null`** in
  production after `config:cache`. Always read env via
  `config('opcua.*')` from application code.
- **Changing `.env` after `config:cache` has no effect** until
  you `config:clear` or `config:cache` again.

The Laravel deployment pattern: `config:cache` after every
deploy, never inside the dev loop.

## Secrets discipline

| Form                                | Visible in...                                         | Production-ready?         |
| ----------------------------------- | ----------------------------------------------------- | ------------------------- |
| `OPCUA_PASSWORD=hardcoded`           | `.env` file in the repo (bad), filesystem only         | No                        |
| `OPCUA_PASSWORD="${SECRETS_PASS}"`   | Process env, resolved from secrets manager            | Yes                        |
| Injected at boot by deployment tool  | Process env only — not on disk                        | Best                      |

The package never logs sensitive values. See [Security ·
Credentials](../security/credentials.md) for the runtime
sanitisation guarantees.

## Where to read next

- [Security](./security.md) — security-related env vars in
  context.
- [Session manager](./session-manager.md) — daemon-side env
  vars in context.
- [Recipes · Production deployment](../recipes/production-deployment.md)
  — putting it together.
