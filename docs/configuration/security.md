---
eyebrow: 'Docs · Configuration'
lede:    'Security knobs as they appear in config/opcua.php — policy, mode, application identity, user identity, trust store. A configuration tour; the deep dives are under Security.'

see_also:
  - { href: '../security/policies-and-modes.md', meta: '6 min' }
  - { href: '../security/credentials.md',        meta: '5 min' }
  - { href: '../security/certificates.md',       meta: '7 min' }
  - { href: '../security/trust-store.md',        meta: '6 min' }

prev: { label: 'Environment variables', href: './environment-variables.md' }
next: { label: 'Session manager',       href: './session-manager.md' }
---

# Security configuration

The security-relevant keys in `config/opcua.php`, grouped by
purpose. Each one points to its dedicated page under
[Security](../security/policies-and-modes.md) for the
**why** — this page is the **where**.

## Security policy and mode

<!-- @code-block language="php" label="config/opcua.php — security" -->
```php
'connections' => [
    'default' => [
        'security_policy' => env('OPCUA_SECURITY_POLICY', 'None'),
        'security_mode'   => env('OPCUA_SECURITY_MODE',   'None'),
        // ...
    ],
],
```
<!-- @endcode-block -->

| Key               | Values                                                                      | What it means                              |
| ----------------- | --------------------------------------------------------------------------- | ------------------------------------------ |
| `security_policy` | `None`, `Basic256Sha256`, `Aes128_Sha256_RsaOaep`, `Aes256_Sha256_RsaPss`, `ECC_nistP256`, `ECC_nistP384` | Algorithm suite. None = no crypto |
| `security_mode`   | `None`, `Sign`, `SignAndEncrypt`                                            | Whether messages are signed and/or encrypted |

Anything beyond `None` requires `client_cert_path` and
`client_key_path`. See [Security · Policies and modes](../security/policies-and-modes.md).

## Application identity (client certificate)

<!-- @code-block language="php" label="config/opcua.php — app identity" -->
```php
'connections' => [
    'default' => [
        'client_cert_path' => env('OPCUA_CLIENT_CERT'),
        'client_key_path'  => env('OPCUA_CLIENT_KEY'),
        'ca_cert_path'     => env('OPCUA_CA_CERT'),
        // ...
    ],
],
```
<!-- @endcode-block -->

The client certificate identifies **your application** to the
OPC UA server. Most servers require the client cert's
fingerprint or DN to be in their trust store before they will
accept the connection.

See [Security · Certificates](../security/certificates.md) for
generation and rotation.

## User identity

OPC UA distinguishes the **application** (cert) from the **user**
(identity at session level). The session-level identity can be
anonymous, username/password, or X.509:

<!-- @code-block language="php" label="config/opcua.php — user identity" -->
```php
'connections' => [
    'default' => [
        // username + password
        'username' => env('OPCUA_USERNAME'),
        'password' => env('OPCUA_PASSWORD'),

        // OR X.509 user identity
        'user_cert_path' => env('OPCUA_USER_CERT'),
        'user_key_path'  => env('OPCUA_USER_KEY'),
    ],
],
```
<!-- @endcode-block -->

Set only one of the two. If both are present, the user-certificate
path wins. If neither is set, the session is anonymous.

See [Security · Credentials](../security/credentials.md).

## Trust store

<!-- @code-block language="php" label="config/opcua.php — trust store" -->
```php
'connections' => [
    'default' => [
        'trust_store_path' => env('OPCUA_TRUST_STORE_PATH'),
        'trust_policy'     => env('OPCUA_TRUST_POLICY', 'fingerprint'),
        'auto_accept'      => env('OPCUA_AUTO_ACCEPT',  false),
    ],
],
```
<!-- @endcode-block -->

| Key                | Values                                       | Meaning                                                            |
| ------------------ | -------------------------------------------- | ------------------------------------------------------------------ |
| `trust_store_path` | Filesystem path                              | Where pinned server certs live. Defaults to per-OS user-data dir.  |
| `trust_policy`     | `fingerprint`, `fingerprint+expiry`, `full`  | What the package checks when validating a server cert.             |
| `auto_accept`      | `true`/`false`                               | TOFU mode — accept unknown server certs on first contact.          |

In production, set `auto_accept` to `false` and use the artisan
trust-store commands documented in [Security · Trust store](../security/trust-store.md).

## A complete secured connection

<!-- @code-block language="php" label="config/opcua.php — full secured" -->
```php
'connections' => [
    'plc' => [
        'endpoint'         => env('OPCUA_ENDPOINT'),
        'timeout'          => 10.0,

        'security_policy'  => 'Basic256Sha256',
        'security_mode'    => 'SignAndEncrypt',

        'client_cert_path' => env('OPCUA_CLIENT_CERT'),
        'client_key_path'  => env('OPCUA_CLIENT_KEY'),
        'ca_cert_path'     => env('OPCUA_CA_CERT'),

        'username'         => env('OPCUA_USERNAME'),
        'password'         => env('OPCUA_PASSWORD'),

        'trust_store_path' => storage_path('app/opcua/trust'),
        'trust_policy'     => 'fingerprint+expiry',
        'auto_accept'      => false,
    ],
],
```
<!-- @endcode-block -->

<!-- @callout type="warning" -->
**`auto_accept => true` is for development.** It accepts every
server certificate on first contact and pins it. In production
this is equivalent to disabling server-side certificate validation
the first time you connect.
<!-- @endcallout -->

## What lives where

| Concern                          | This file (config)                    | Deep dive                                        |
| -------------------------------- | ------------------------------------- | ------------------------------------------------ |
| Choosing a policy / mode         | `security_policy`, `security_mode`    | [Policies and modes](../security/policies-and-modes.md) |
| Generating client cert           | (you point at file)                   | [Certificates](../security/certificates.md)       |
| Managing pinned server certs     | (path only)                           | [Trust store](../security/trust-store.md)         |
| Passwords / user X.509           | `username`/`password`/`user_*_path`   | [Credentials](../security/credentials.md)         |

## Where to read next

- [Session manager](./session-manager.md) — daemon-related config.
- [Security · Policies and modes](../security/policies-and-modes.md) —
  pick the right combination for your server.
