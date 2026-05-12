---
eyebrow: 'Docs · Security'
lede:    'Managing pinned OPC UA server certificates. The trust-store policy choice, the artisan commands that automate it, and the TOFU vs strict-pin trade-off.'

see_also:
  - { href: './certificates.md',                meta: '7 min' }
  - { href: './policies-and-modes.md',          meta: '6 min' }
  - { href: '../configuration/security.md',     meta: '4 min' }

prev: { label: 'Certificates',  href: './certificates.md' }
next: { label: 'Pest setup',    href: '../testing/pest-setup.md' }
---

# Trust store

The mirror image of `client_cert_path`. The **trust store** is
the local directory of OPC UA server certificates your Laravel
app considers legitimate. The package consults it on every
secure-channel handshake.

## The directory

By default:

| OS         | Default trust store path                                |
| ---------- | ------------------------------------------------------- |
| POSIX      | `~/.opcua/` (the underlying `FileTrustStore` does not append a `trust/` subdirectory) |
| Windows    | `%APPDATA%\opcua\`                                      |
| Override   | `OPCUA_TRUST_STORE_PATH=/path/to/trust`                  |

Override the default in production — `~/.opcua/` is fine for
development but it's tied to the executing user's home directory,
which can be unpredictable under php-fpm / queue workers.

For production, override to a known location:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_TRUST_STORE_PATH=/var/lib/opcua/trust
```
<!-- @endcode-block -->

`config/opcua.php`:

<!-- @code-block language="php" label="config" -->
```php
'connections' => [
    'default' => [
        'trust_store_path' => env('OPCUA_TRUST_STORE_PATH'),
        'trust_policy'     => env('OPCUA_TRUST_POLICY', 'fingerprint'),
        'auto_accept'      => env('OPCUA_AUTO_ACCEPT', false),
    ],
],
```
<!-- @endcode-block -->

## Trust policies

Three policies, three trade-offs:

| Policy                 | What's checked on connect                              | Pro                                                | Con                                                 |
| ---------------------- | ------------------------------------------------------ | -------------------------------------------------- | --------------------------------------------------- |
| `fingerprint`          | SHA-1 of the cert is in the trust store                | Simplest. No CA chain                              | Rotation = manual repinning                         |
| `fingerprint+expiry`   | Fingerprint match + cert `NotAfter` is in the future   | Catches expiry-related issues                      | Same rotation cost                                  |
| `full`                 | Full X.509 chain validation against trust store as CAs | CA-based rotation = no per-server changes          | Need a CA, more setup                               |

For a small fleet (1-50 PLCs), `fingerprint+expiry` is the
right default — simple, expiry-aware, no CA infrastructure.

For larger fleets where you operate the CA, `full` is cleaner.

## Adding a server cert

The package does **not** ship `opcua:trust:add`, `opcua:trust:list`,
or `opcua:trust:remove` artisan commands. Use one of these
alternatives:

- The companion [`opcua-cli`](https://github.com/php-opcua/opcua-cli)
  package — it ships a full trust-store CLI
  (`opcua-cli trust:add`, `trust:list`, `trust:remove`).
- The programmatic facade methods,
  `Opcua::trustCertificate(string $certDer)` and
  `Opcua::untrustCertificate(string $fingerprint)`, called from
  your own artisan command or admin route.
- Directly drop the PEM file into the trust-store directory
  (the file's filename can be anything; the package indexes by
  SHA-1 fingerprint).

A minimal first-pin pattern using `auto_accept` in dev plus
`trustCertificate()` in prod:

<!-- @code-block language="php" label="programmatic pinning" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// $certDer is the raw DER bytes of the server certificate
Opcua::trustCertificate($certDer);
```
<!-- @endcode-block -->

To discover the cert in the first place, either:

- Read the file off the server's filesystem.
- Connect once with `auto_accept` on, then inspect the trust-store
  directory.
- Use `opcua-cli trust:add` (it does the discovery handshake for
  you).

## Listing pinned certs

Read the trust-store directory directly, or via `opcua-cli`. The
package does not ship a `trust:list` command.

```bash
ls -la /var/lib/opcua/trust/
# or
vendor/bin/opcua-cli trust:list
```

## Removing a cert

```php
Opcua::untrustCertificate($sha1Fingerprint);
```

or `opcua-cli trust:remove`, or `rm /var/lib/opcua/trust/<file>.pem`.

Use after a server cert rotation — remove the old fingerprint
and add the new one.

## TOFU mode (`auto_accept`)

For development, you can let the package accept unknown certs
on first contact:

<!-- @code-block language="bash" label=".env (dev only)" -->
```bash
OPCUA_AUTO_ACCEPT=true
```
<!-- @endcode-block -->

First connection: cert is auto-pinned, connection succeeds,
package logs `notice` level "Auto-accepted server cert
fingerprint=...". Subsequent connections: normal trust check
against the pinned cert.

<!-- @callout type="warning" -->
**`auto_accept=true` in production is equivalent to disabling
server-cert validation.** Anyone who can MitM the first
connection establishes a permanent trust relationship. Use only
in dev, or only behind an admin gate.
<!-- @endcallout -->

For a production-safe variant, run a one-time provisioning command
that's the *only* place auto-accept is enabled:

<!-- @code-block language="php" label="trust-bootstrap" -->
```php
class TrustBootstrap extends Command
{
    protected $signature = 'opcua:trust:bootstrap';

    public function handle(OpcuaManager $opcua): int
    {
        // Only an admin runs this, once per server
        Config::set('opcua.connections.default.auto_accept', true);

        try {
            $opcua->read('i=2256');  // forces a connection
        } catch (\Throwable $e) {
            $this->error("Bootstrap failed: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Cert pinned. Inspect ' . config('opcua.connections.default.trust_store_path') . ' to verify.');
        return self::SUCCESS;
    }
}
```
<!-- @endcode-block -->

## Cert rotation procedure

The server's cert expires; you want to keep service running.

### Steps

1. **Server-side**: generate the new cert. Install it. Most
   servers support having multiple valid certs at once.
2. **Laravel-side**: pin the new cert alongside the old —
   `Opcua::trustCertificate($newCertDer)` or
   `vendor/bin/opcua-cli trust:add <endpoint>`.
3. **Switch over** server-side: the server starts presenting the
   new cert. Connections continue working — both are trusted.
4. **Laravel-side**: remove the old cert with
   `Opcua::untrustCertificate($oldFingerprint)`.
5. Confirm by listing the trust-store directory.

If you can't pre-stage the new cert in the trust store
(step 2 before step 3), there's a small window of "untrusted
cert" — the package raises `UntrustedCertificateException` for
those connections until the new cert is pinned.

## CI / deployment integration

For automated trust-store updates in CI:

<!-- @code-block language="text" label="deploy step" -->
```text
- name: Pin OPC UA server certs
  run: |
    for endpoint in $(cat ./endpoints.txt); do
      vendor/bin/opcua-cli trust:add --force "$endpoint"
    done
  env:
    OPCUA_TRUST_STORE_PATH: /var/lib/opcua/trust
```
<!-- @endcode-block -->

The `--force` flag skips the confirmation prompt. Use only
when the input is **known-good** — endpoint.txt is part of
your repo, not an external input.

## Multi-tenant trust stores

Per-tenant isolation:

<!-- @code-block language="php" label="per-tenant trust" -->
```php
'connections' => [
    'plc-tenant-acme' => [
        'endpoint'         => '...',
        'trust_store_path' => '/var/lib/opcua/trust/acme',
    ],
    'plc-tenant-globex' => [
        'endpoint'         => '...',
        'trust_store_path' => '/var/lib/opcua/trust/globex',
    ],
],
```
<!-- @endcode-block -->

A breach of one tenant's trust store doesn't compromise others.

## Permissions

The trust store directory must be readable by the Laravel
process:

<!-- @code-block language="bash" label="terminal — perms" -->
```bash
sudo mkdir -p /var/lib/opcua/trust
sudo chown www-data:www-data /var/lib/opcua/trust
sudo chmod 0750 /var/lib/opcua/trust
```
<!-- @endcode-block -->

Files inside are mode `0640` — readable by user and group.

## What's in a trust-store file

Each pinned cert is a PEM file:

<!-- @code-block language="text" label="trust store contents" -->
```text
/var/lib/opcua/trust/
├── a1b2c3...-OpenUaServer.pem
├── d4e5f6...-KEPServerEX.pem
└── revoked/
    └── (old certs moved here for audit)
```
<!-- @endcode-block -->

The filename embeds the fingerprint and a friendly hint —
the package uses the **fingerprint** for matching, not the
filename.

## Cache implications

The package caches trust-store hashes — see
[Caching · Trust store](../observability/caching.md#trust-store-fingerprints).
The artisan trust-store commands invalidate this cache
automatically. If you bypass the artisan command (drop files in
manually), wait up to 5 minutes for the cache to expire, or
flush manually.

## Where to read next

You've finished **Security**. Next: [Testing · Pest setup](../testing/pest-setup.md)
for the testing harness.
