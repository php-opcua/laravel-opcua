---
eyebrow: 'Docs · Security'
lede:    'OPC UA security policy and mode — what each combination actually does, which to pick, how Laravel apps configure them.'

see_also:
  - { href: './credentials.md',                    meta: '5 min' }
  - { href: './certificates.md',                   meta: '7 min' }
  - { href: '../configuration/security.md',        meta: '4 min' }

prev: { label: 'Telescope & Pulse',  href: '../observability/telescope-and-pulse.md' }
next: { label: 'Credentials',        href: './credentials.md' }
---

# Policies and modes

OPC UA security is two orthogonal axes:

- **Policy** — the algorithm suite. *What crypto?*
- **Mode** — what those algorithms protect. *Sign, encrypt, both, neither?*

A connection picks one of each.

## Modes

| Mode               | Protection                                                       |
| ------------------ | ---------------------------------------------------------------- |
| `None`             | No signing, no encryption. Cleartext on the wire.                |
| `Sign`             | Every message signed. Integrity + authenticity, no confidentiality. |
| `SignAndEncrypt`   | Signed and encrypted. Integrity + authenticity + confidentiality. |

`None` is for development against an unsecured test server. Real
deployments use `Sign` (when the network is trusted but you want
to detect tampering) or `SignAndEncrypt` (the production default).

## Policies

| Policy                       | Status                | Notes                                   |
| ---------------------------- | --------------------- | --------------------------------------- |
| `None`                       | Required by spec      | Used with mode `None`                   |
| `Basic128Rsa15`              | **Deprecated**        | Don't use. SHA-1, RSA-PKCS1.            |
| `Basic256`                   | **Deprecated**        | Don't use. SHA-1.                       |
| `Basic256Sha256`             | Stable. Widely supported. | SHA-256, RSA-PKCS1, AES256. Good default. |
| `Aes128_Sha256_RsaOaep`      | Stable                | Modern padding (RSA-OAEP).              |
| `Aes256_Sha256_RsaPss`       | Stable                | Modern padding (RSA-PSS). Strongest non-ECC.  |
| `ECC_nistP256`               | New (UA spec ≥ 1.05)  | ECC P-256. Smaller keys, faster.        |
| `ECC_nistP384`               | New                   | ECC P-384. Stronger ECC.                |

For production today: **`Basic256Sha256` + `SignAndEncrypt`** is
the de-facto default. ECC is the future — adopt when your servers
all support it.

## In `config/opcua.php`

<!-- @code-block language="php" label="secured connection" -->
```php
'connections' => [
    'plc' => [
        'endpoint'         => env('OPCUA_ENDPOINT'),

        'security_policy'  => 'Basic256Sha256',
        'security_mode'    => 'SignAndEncrypt',

        'client_cert_path' => env('OPCUA_CLIENT_CERT'),
        'client_key_path'  => env('OPCUA_CLIENT_KEY'),
    ],
],
```
<!-- @endcode-block -->

Any mode beyond `None` **requires** `client_cert_path` and
`client_key_path`. The package raises
`CertificateException` on connect otherwise.

## How the server negotiates

The OPC UA discovery phase reports the server's
**EndpointDescriptions** — one per (policy, mode) the server
offers. The client picks one that matches its config and that
the server offers.

If your config asks for `Basic256Sha256 + SignAndEncrypt` and
the server doesn't offer it, the package raises `PolicyException`
on connect.

Discover what a server offers:

<!-- @code-block language="bash" label="terminal — discover policies" -->
```bash
# Using the cli sibling package
vendor/bin/opcua-cli discover opc.tcp://plc.factory.local:4840
```
<!-- @endcode-block -->

Or use any OPC UA inspector tool (UaExpert, OPCUA-Client tools).

## Recommended combinations

| Scenario                                       | Policy                  | Mode             |
| ---------------------------------------------- | ----------------------- | ---------------- |
| Local dev against test server                  | `None`                  | `None`           |
| Trusted-network production (typical plant)     | `Basic256Sha256`        | `SignAndEncrypt` |
| Public-network / cross-DC                       | `Aes256_Sha256_RsaPss`  | `SignAndEncrypt` |
| Modern ECC deployment                          | `ECC_nistP256`          | `SignAndEncrypt` |
| Legacy server that only does old policies      | `Basic128Rsa15` (warn)  | `Sign`           |

For the legacy-server case, **document the exception** — these
policies have known weaknesses.

## Choosing between Sign and SignAndEncrypt

- **`Sign`** — when the network is private and trusted. Saves CPU.
  Server sees the wire as plaintext (useful for ops debugging).
- **`SignAndEncrypt`** — when the network might be observed.
  Adds CPU cost (~5-15% on most modern hardware) but no operational
  cost.

For a Laravel app sitting on the plant LAN with no untrusted
peers, `Sign` is defensible. For everything else, encrypt.

## Per-connection override

Each connection in `config/opcua.php` has its own policy/mode.
A common pattern: one `historian` connection over `None` (the
historian is on a trusted subnet and high-volume) plus several
`plc-*` connections over `SignAndEncrypt`:

<!-- @code-block language="php" label="mixed policy deployment" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint'         => 'opc.tcp://plc-a.plant.local:4840',
        'security_policy'  => 'Basic256Sha256',
        'security_mode'    => 'SignAndEncrypt',
        // ...
    ],
    'historian' => [
        'endpoint'         => 'opc.tcp://historian.plant.local:4841',
        'security_policy'  => 'None',
        'security_mode'    => 'None',
    ],
],
```
<!-- @endcode-block -->

## Hardware acceleration

On modern Intel/AMD CPUs, AES is hardware-accelerated and
practically free. RSA signing is more expensive — 200-500
signatures/sec per core. For high-frequency call patterns,
**reuse the connection** rather than re-handshaking.

This is the managed-mode case — session reuse via the daemon
eliminates re-handshaking entirely.

## ECC support

ECC policies (`ECC_nistP256`, `ECC_nistP384`) need a new
certificate type — ECC keys, not RSA. Generating one:

<!-- @code-block language="bash" label="generate ECC cert" -->
```bash
openssl ecparam -name prime256v1 -genkey -noout -out client.key
openssl req -new -x509 -key client.key -out client.pem -days 365 \
    -subj "/CN=My Laravel Client/O=Acme"
```
<!-- @endcode-block -->

Most servers don't yet support ECC (UA spec 1.05 is new). Check
your server's `Server.ServerCapabilities.SupportedPrivateKeyFormats`
before committing to it.

## Default policy by environment

| Environment | Recommended policy                  | Mode             |
| ----------- | ----------------------------------- | ---------------- |
| `local`     | `None`                              | `None`           |
| `staging`   | `Basic256Sha256`                    | `Sign` (faster), `SignAndEncrypt` to mirror prod |
| `production` | `Basic256Sha256` (or stronger)     | `SignAndEncrypt` |
| `tests`     | `None`                              | `None`           |

The `.env`-per-environment pattern keeps this clean:

<!-- @code-block language="bash" label=".env.production" -->
```bash
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
```
<!-- @endcode-block -->

## What policy/mode do NOT cover

- **User authentication** — that's the user-identity layer.
  See [Credentials](./credentials.md).
- **Authorisation** — what the user can read/write/call.
  Server-side, not client-side.
- **Audit trail** — your app's responsibility.
- **Replay protection** — handled at the OPC UA chunk layer
  automatically.

## Where to read next

- [Credentials](./credentials.md) — username/password and user-cert
  identity at the session layer.
- [Certificates](./certificates.md) — generating, signing, rotating
  client certs.
- [Trust store](./trust-store.md) — managing pinned server certs.
