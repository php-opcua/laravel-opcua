---
eyebrow: 'Docs · Security'
lede:    'Generating, signing, rotating the client (application) certificate. The mTLS-style identity that the OPC UA server uses to recognise your Laravel app.'

see_also:
  - { href: './policies-and-modes.md',         meta: '6 min' }
  - { href: './trust-store.md',                meta: '6 min' }
  - { href: '../configuration/security.md',    meta: '4 min' }

prev: { label: 'Credentials',  href: './credentials.md' }
next: { label: 'Trust store',  href: './trust-store.md' }
---

# Certificates

The **client (application) certificate** identifies your Laravel
app to the OPC UA server. Every connection beyond
`security_mode = None` requires one.

It's an X.509 cert with a private key — the same format as TLS
server certs. The difference is in conventions:

- The subject `CN` is your application name, not a hostname.
- The cert needs an `ApplicationUri` extension matching the URI
  the server expects (often `urn:host:app`).
- The server-side trust list is per-application, not per-CA.

## Generating a cert

The simplest case — a self-signed cert:

<!-- @code-block language="bash" label="terminal — self-signed RSA cert" -->
```bash
mkdir -p /etc/opcua

openssl req -x509 -newkey rsa:2048 -keyout /etc/opcua/client.key \
    -out /etc/opcua/client.pem -days 365 -nodes \
    -subj "/CN=My Laravel Client/O=Acme" \
    -addext "subjectAltName=URI:urn:my-laravel:client,DNS:laravel.acme.local" \
    -addext "keyUsage=digitalSignature,keyEncipherment,dataEncipherment" \
    -addext "extendedKeyUsage=serverAuth,clientAuth"
```
<!-- @endcode-block -->

Important pieces:

- **`URI:urn:my-laravel:client`** — the OPC UA `ApplicationUri`.
  The server might validate this exactly.
- **`extendedKeyUsage=serverAuth,clientAuth`** — both required by
  the OPC UA spec, even though it's a client cert.
- **`keyUsage`** — the cert needs to sign, encipher keys, and
  encipher data.

For an ECC cert:

<!-- @code-block language="bash" label="terminal — ECC cert" -->
```bash
openssl ecparam -name prime256v1 -genkey -noout -out /etc/opcua/client.key
openssl req -x509 -key /etc/opcua/client.key \
    -out /etc/opcua/client.pem -days 365 \
    -subj "/CN=My Laravel Client/O=Acme" \
    -addext "subjectAltName=URI:urn:my-laravel:client"
```
<!-- @endcode-block -->

## Wiring it up

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key
```
<!-- @endcode-block -->

…and `config/opcua.php`:

<!-- @code-block language="php" label="config" -->
```php
'connections' => [
    'default' => [
        'client_cert_path' => env('OPCUA_CLIENT_CERT'),
        'client_key_path'  => env('OPCUA_CLIENT_KEY'),
    ],
],
```
<!-- @endcode-block -->

## Trusting the cert server-side

On the OPC UA server, the cert needs to land in the server's
**trusted client cert directory**. Two paths:

### 1 — First-connection trust prompt

Many servers default to rejecting unknown certs on first
connection — the cert lands in a "rejected" directory. An
operator manually moves it to "trusted" via the server's admin
UI.

### 2 — Pre-stage the cert

Drop the cert file directly into the server's trusted cert
directory before the first connection attempt. This avoids the
"first call fails" UX.

The exact directory is server-specific:

| Server                  | Trusted cert dir                                    |
| ----------------------- | --------------------------------------------------- |
| open62541 (default)     | `pki/trusted/certs/`                                |
| Prosys OPC UA Simulation | `~/.prosysopc/.../USER_PKI/CA/certs/`              |
| Siemens S7 PLCs         | TIA Portal config                                   |
| KEPServerEX             | KEPServer Configuration UI                          |

## Cert rotation

Certs expire. Quarterly or annual rotation is standard:

### Procedure (zero-downtime)

1. **Generate new cert** — same `ApplicationUri`, new validity.
2. **Stage in the server** alongside the old cert. Both are
   now trusted.
3. **Update Laravel** — `.env` → new paths, deploy.
4. **Restart daemon** — `systemctl restart opcua-session-manager`.
5. **Confirm connections** — new cert is in use.
6. **Remove old cert from server.**

### Procedure (with downtime — simpler)

1. Generate new cert.
2. Update server (remove old, add new).
3. Update Laravel.
4. Restart daemon.

Step 2 → step 4 is the downtime window — typically 10-30
seconds.

## Cert chain

A cert can be self-signed (the example above) or signed by a
CA. For larger fleets where you want to issue many client certs
from a single trust root, use a CA:

<!-- @code-block language="bash" label="terminal — CA-signed" -->
```bash
# 1. Create CA (once)
openssl req -x509 -newkey rsa:4096 -keyout opcua-ca.key \
    -out opcua-ca.pem -days 3650 -nodes \
    -subj "/CN=Acme OPC UA Root CA"

# 2. Create client CSR
openssl req -new -newkey rsa:2048 -keyout client.key \
    -out client.csr -nodes \
    -subj "/CN=My Laravel Client/O=Acme"

# 3. Sign with the CA
openssl x509 -req -in client.csr -CA opcua-ca.pem -CAkey opcua-ca.key \
    -CAcreateserial -out client.pem -days 365 \
    -extfile <(echo "subjectAltName=URI:urn:my-laravel:client") \
    -extfile <(echo "extendedKeyUsage=serverAuth,clientAuth")
```
<!-- @endcode-block -->

Now you trust the **CA** on the server, and any cert signed by
it gets accepted. For 50+ client deployments this is cleaner
than per-machine trust pinning.

Wire the CA cert too:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_CA_CERT=/etc/opcua/opcua-ca.pem
```
<!-- @endcode-block -->

…and config:

<!-- @code-block language="php" label="ca_cert_path" -->
```php
'ca_cert_path' => env('OPCUA_CA_CERT'),
```
<!-- @endcode-block -->

## Permissions

The private key must be readable by the Laravel process and
*nothing else*:

<!-- @code-block language="bash" label="terminal — permissions" -->
```bash
sudo chown www-data:www-data /etc/opcua/client.{pem,key}
sudo chmod 0640 /etc/opcua/client.pem
sudo chmod 0600 /etc/opcua/client.key       # private — only owner
```
<!-- @endcode-block -->

For the daemon running as `www-data`, this works. If FPM and
the daemon run as different users, use a shared group instead
of broadening permissions.

## Per-connection certs

A common large-deployment pattern: one client cert per
**connection name**, so the OPC UA server can audit by source:

<!-- @code-block language="php" label="per-connection certs" -->
```php
'connections' => [
    'plc-line-a' => [
        'endpoint'         => '...',
        'client_cert_path' => '/etc/opcua/line-a.pem',
        'client_key_path'  => '/etc/opcua/line-a.key',
    ],
    'plc-line-b' => [
        'endpoint'         => '...',
        'client_cert_path' => '/etc/opcua/line-b.pem',
        'client_key_path'  => '/etc/opcua/line-b.key',
    ],
],
```
<!-- @endcode-block -->

The server-side audit log can distinguish which Laravel
connection produced which write — useful for forensics.

## Cert expiry monitoring

Long-lived certs expire. The package does **not** ship an
`opcua:cert:check` artisan command — write your own application
command and schedule it. A reasonable signature is
`plc:cert:check` or `app:cert:check`; the snippet below uses
`opcua:cert:check` for symmetry with `opcua-cli`, which does ship
such a command.

<!-- @code-block language="php" label="CheckCertExpiry command (your app)" -->
```php
class CheckCertExpiry extends Command
{
    protected $signature = 'opcua:cert:check';

    public function handle(): int
    {
        foreach (config('opcua.connections') as $name => $conn) {
            if (empty($conn['client_cert_path'])) continue;

            $expiry = $this->certExpiry($conn['client_cert_path']);
            $daysLeft = now()->diffInDays($expiry);

            if ($daysLeft < 30) {
                $this->warn("$name expires in $daysLeft days");
                Notification::route('slack', config('alerts.ops_channel'))
                    ->notify(new CertExpiringSoon($name, $expiry));
            }
        }

        return self::SUCCESS;
    }

    private function certExpiry(string $path): \DateTimeImmutable
    {
        $cert = openssl_x509_parse(file_get_contents($path));
        return new \DateTimeImmutable("@{$cert['validTo_time_t']}");
    }
}
```
<!-- @endcode-block -->

Schedule daily:

<!-- @code-block language="php" label="app/Console/Kernel.php" -->
```php
$schedule->command('opcua:cert:check')->dailyAt('06:00');
```
<!-- @endcode-block -->

## Inspecting a cert

<!-- @code-block language="bash" label="terminal — inspect" -->
```bash
openssl x509 -in /etc/opcua/client.pem -noout -text
openssl x509 -in /etc/opcua/client.pem -noout -dates       # validity
openssl x509 -in /etc/opcua/client.pem -noout -subject     # subject
openssl x509 -in /etc/opcua/client.pem -noout -ext subjectAltName  # SAN/URI
```
<!-- @endcode-block -->

The SAN should contain `URI:urn:...` for OPC UA.

## What goes wrong

| Symptom                              | Likely cause                                          |
| ------------------------------------ | ----------------------------------------------------- |
| `CertificateException — untrusted`   | Server doesn't have this cert in its trust list       |
| `Bad_CertificateInvalid`             | Cert structure problem (missing extensions, bad URI)  |
| `Bad_CertificateUriInvalid`          | `ApplicationUri` doesn't match the SAN URI            |
| `Bad_CertificateUseNotAllowed`       | Missing `keyUsage` or `extendedKeyUsage`              |
| `Bad_CertificateTimeInvalid`         | Cert expired or not-yet-valid                         |
| `Bad_SecurityChecksFailed`           | Signature failure — wrong key                         |

For each of these, the fix is in the cert (re-generate with the
right extensions) or in the server's trust list (re-trust the
existing cert).

## Where to read next

- [Trust store](./trust-store.md) — the *server-cert* side.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  cert lifecycle in a real deployment.
