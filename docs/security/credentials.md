---
eyebrow: 'Docs · Security'
lede:    'Username/password vs user-cert identity, where credentials live, what the package never logs, and the rotation patterns Laravel apps converge on.'

see_also:
  - { href: './policies-and-modes.md',              meta: '6 min' }
  - { href: './certificates.md',                    meta: '7 min' }
  - { href: '../configuration/environment-variables.md', meta: '5 min' }

prev: { label: 'Policies & modes',  href: './policies-and-modes.md' }
next: { label: 'Certificates',      href: './certificates.md' }
---

# Credentials

OPC UA's session layer carries a **UserIdentityToken**. Three
shapes:

1. **Anonymous** — no identity.
2. **Username/password** — like a database login.
3. **X.509 certificate** — like a user-level mTLS cert.

This is **distinct from** the application certificate (which
identifies your Laravel application to the server). Most
deployments use both: an app cert at the secure-channel layer, a
user identity at the session layer.

## Configuring

In `config/opcua.php`:

<!-- @code-block language="php" label="username + password" -->
```php
'connections' => [
    'default' => [
        // ...
        'username' => env('OPCUA_USERNAME'),
        'password' => env('OPCUA_PASSWORD'),
    ],
],
```
<!-- @endcode-block -->

…or:

<!-- @code-block language="php" label="user X.509" -->
```php
'connections' => [
    'default' => [
        // ...
        'user_cert_path' => env('OPCUA_USER_CERT'),
        'user_key_path'  => env('OPCUA_USER_KEY'),
    ],
],
```
<!-- @endcode-block -->

…or nothing (anonymous):

<!-- @code-block language="php" label="anonymous" -->
```php
'connections' => [
    'default' => [
        // username, password, user_cert_path, user_key_path all omitted
    ],
],
```
<!-- @endcode-block -->

If both username/password and user-cert are present, user-cert
wins. Most servers reject ambiguous identity anyway.

## Where credentials should live

**Never** in code or version control. The recommended layers:

| Location                  | Production-ready? | Why                                    |
| ------------------------- | ----------------- | -------------------------------------- |
| `.env` file (committed)   | **No**            | Visible to anyone with repo access     |
| `.env` file (gitignored)  | OK in dev         | On-disk; readable by other processes   |
| Secrets manager → process env | **Yes**       | No on-disk plaintext                   |
| Per-process secret store   | Best              | E.g. systemd `LoadCredential`          |

Examples of secrets managers Laravel commonly integrates with:

- HashiCorp Vault (via `vault-cli` + env injection)
- AWS Secrets Manager (via `aws-sdk-php`)
- Doppler (via `doppler run --` wrapper)
- 1Password Connect
- Bitwarden Secrets Manager

The pattern: secret manager → env var at process start → `env()`
in `config/opcua.php`.

## What the package logs

The package **never** writes credentials to logs. Specifically:

| Surface                      | Credentials redacted? |
| ---------------------------- | --------------------- |
| Connection-opened log line   | Yes                   |
| Exception messages           | Yes (sanitised — see below) |
| Daemon's `list` IPC response | Yes (since v4.3.0)    |
| `php artisan tinker > config('opcua.*')` | No — visible to debug user |

In tinker, `config('opcua.connections.default.password')` returns
the actual password (it has to — it's a config value). This is
fine; tinker is a debug surface for the developer, not a logging
output.

## URL credential sanitisation

The package's exception sanitiser rewrites three known credential
patterns:

| Input                              | After sanitisation                    |
| ---------------------------------- | ------------------------------------- |
| `opc.tcp://user:pass@host:4840`    | `opc.tcp://[redacted]:[redacted]@host:4840` |
| `/etc/opcua/client.key`             | `[path]` (in messages, not config)   |
| `C:\Users\me\OneDrive\client.pem`  | `[path]`                              |

This runs in error messages, not in your own logs. If your
listener does `Log::error($e->getMessage())`, the message is
already sanitised.

## Per-environment credentials

The standard `.env`-per-environment pattern:

<!-- @code-block language="bash" label=".env.local" -->
```bash
OPCUA_ENDPOINT=opc.tcp://localhost:4840
# anonymous in local
```
<!-- @endcode-block -->

<!-- @code-block language="bash" label=".env.staging" -->
```bash
OPCUA_ENDPOINT=opc.tcp://staging-plc.internal:4840
OPCUA_USERNAME=integrations-staging
OPCUA_PASSWORD="${SECRETS_OPCUA_STAGING_PASS}"
```
<!-- @endcode-block -->

<!-- @code-block language="bash" label=".env.production" -->
```bash
OPCUA_ENDPOINT=opc.tcp://prod-plc.internal:4840
OPCUA_USERNAME=integrations-prod
OPCUA_PASSWORD="${SECRETS_OPCUA_PROD_PASS}"
```
<!-- @endcode-block -->

Both `staging` and `production` resolve secrets from the
secrets manager. Local dev runs anonymous against a test server.

## Rotation

A common quarterly task: rotate the OPC UA user password.

The procedure:

1. **Generate a new password** in the secrets manager.
2. **Update the OPC UA server's user database** (server-side).
3. **Update the secrets manager** with the new value.
4. **Trigger a deploy** that picks up the new env var.
5. **Restart the daemon** (since it caches credentials in
   open sessions).

Steps 2 and 3 should be done close together — anything in between
will fail to connect.

For zero-downtime rotation:

1. Add the **new** user to the server (don't remove the old yet).
2. Update secrets manager → new password.
3. Deploy and restart.
4. Verify connections are healthy under the new identity.
5. Remove the old user from the server.

The package supports this naturally — there's no rotation API,
just a config refresh + reconnect.

## User-cert credentials

Same shape as application certs (see [Certificates](./certificates.md)),
but tied to a **user** server-side. The user-cert is checked
against the server's user trust list, not against the secure-
channel trust store.

When to use user-cert over username/password:

| Scenario                              | Pick                |
| ------------------------------------- | ------------------- |
| Many users, simple management         | Username/password   |
| Audit per-application identity        | User-cert           |
| Hardware-bound identity (HSM, TPM)    | User-cert           |
| Per-machine identity in a fleet       | User-cert           |
| Per-Laravel-deployment audit trail    | User-cert           |

## Per-connection identities

A common multi-tenant pattern — one user-cert per tenant:

<!-- @code-block language="php" label="per-tenant user-cert" -->
```php
'connections' => [
    'plc-tenant-acme' => [
        'endpoint'        => 'opc.tcp://plc.acme.local:4840',
        'user_cert_path'  => '/etc/opcua/users/acme.pem',
        'user_key_path'   => '/etc/opcua/users/acme.key',
    ],
    'plc-tenant-globex' => [
        'endpoint'        => 'opc.tcp://plc.globex.local:4840',
        'user_cert_path'  => '/etc/opcua/users/globex.pem',
        'user_key_path'   => '/etc/opcua/users/globex.key',
    ],
],
```
<!-- @endcode-block -->

The server logs per-tenant identity; no shared credential.

## Anonymous

Anonymous is for **read-only, public-data** endpoints. Most
plants don't have these — there's almost always a user layer.
But if your endpoint is "show me a public weather station",
anonymous is fine.

## Validation in CI

A CI check that flags accidentally-committed credentials:

<!-- @code-block language="text" label=".github/workflows/secrets-check.yml" -->
```text
- name: Check for OPCUA password
  run: |
    if grep -r 'OPCUA_PASSWORD=' --include='.env*' .; then
      echo "Hardcoded password detected in .env file"
      exit 1
    fi
```
<!-- @endcode-block -->

Or use a proper secret-scanning tool (gitleaks, trufflehog).

## When credentials fail

| Error                                          | Likely cause                                   |
| ---------------------------------------------- | ---------------------------------------------- |
| `AuthenticationException — wrong password`     | Password typo or post-rotation drift           |
| `AuthenticationException — user unknown`       | Username doesn't exist on the server           |
| `AuthenticationException — locked out`         | Too many failed attempts; server has lockout   |
| `Bad_IdentityTokenRejected`                    | The user-cert isn't in the server's user trust list |
| `Bad_IdentityTokenInvalid`                     | Cert is expired or malformed                   |

For diagnostics see [Debugging](../observability/debugging.md).

## Where to read next

- [Certificates](./certificates.md) — the application-cert
  surface (different from user-cert).
- [Trust store](./trust-store.md) — managing the server-cert
  side.
