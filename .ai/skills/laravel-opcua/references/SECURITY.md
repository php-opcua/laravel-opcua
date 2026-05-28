# Security

OPC UA security has three concerns; this package wires Laravel ergonomics around all three.

## 1. Transport security (security policy + mode)

`security_policy` selects the cipher suite; `security_mode` selects what's protected.

| Policy | Algorithm | Status |
|---|---|---|
| `None` | — | No protection. Dev only. |
| `Basic128Rsa15` | RSA-1.5 + AES-128-CBC + SHA-1 | **Deprecated** (1.04). Don't use. |
| `Basic256` | RSA-OAEP + AES-256-CBC + SHA-1 | **Deprecated** (1.04). Don't use. |
| `Basic256Sha256` | RSA-OAEP + AES-256-CBC + SHA-256 | **Recommended** default for RSA. |
| `Aes128Sha256RsaOaep` | RSA-OAEP-SHA256 + AES-128-CBC + SHA-256 | Modern RSA alt. |
| `Aes256Sha256RsaPss` | RSA-PSS + AES-256-CBC + SHA-256 | Strongest RSA. |
| `ECC_nistP256` | ECDH-NIST-P-256 + AES-128-GCM + SHA-256 | **Recommended** modern. |
| `ECC_nistP384` | ECDH-NIST-P-384 + AES-256-GCM + SHA-384 | Strongest ECC. |
| `ECC_brainpoolP256r1` | ECDH-Brainpool-P-256 + AES-128-GCM + SHA-256 | EU regulators. |
| `ECC_brainpoolP384r1` | ECDH-Brainpool-P-384 + AES-256-GCM + SHA-384 | EU regulators. |

| Mode | Sign | Encrypt | Use |
|---|:-:|:-:|---|
| `None` | × | × | Dev / Docker test servers |
| `Sign` | ✓ | × | Authenticated, plaintext payload |
| `SignAndEncrypt` | ✓ | ✓ | Default for production |

Production rule of thumb: `Basic256Sha256` + `SignAndEncrypt` for RSA, `ECC_nistP256` + `SignAndEncrypt` for ECC.

## 2. Client certificate

When a policy + mode are set and no client cert is supplied, the client **auto-generates** one in memory at connect time (RSA-2048 for RSA policies, EC matching the curve for ECC). This is convenient for dev but the server sees a different cert every request — useless for TOFU-style server-side trust.

For production, supply a real cert:

```php
// config/opcua.php → connections.default
'client_certificate' => env('OPCUA_CLIENT_CERT'),
'client_key' => env('OPCUA_CLIENT_KEY'),
'ca_certificate' => env('OPCUA_CA_CERT'),  // optional, for server cert validation
```

```env
OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key
OPCUA_CA_CERT=/etc/opcua/ca.pem
```

File permissions:
- `client_certificate` → 0644
- `client_key` → 0600 (readable by web user only)
- `ca_certificate` → 0644

`allowed_cert_dirs` (in `session_manager` config) optionally restricts which directories the daemon may read cert/key files from — set this when the daemon runs as root or with elevated perms.

### Generating a client cert (one-liner)

```bash
openssl req -x509 -newkey rsa:2048 -keyout client.key -out client.pem \
    -days 365 -nodes \
    -subj "/CN=laravel-opcua-client" \
    -addext "subjectAltName=URI:urn:laravel-opcua:client,DNS:client.local"
```

The `URI:urn:...` SAN is **required** by spec. Many servers reject certs without it.

## 3. User authentication

Three modes; configure per connection.

### Anonymous

```php
'username' => null,
'password' => null,
'user_certificate' => null,
```

### Username / Password

```php
'username' => env('OPCUA_USERNAME'),
'password' => env('OPCUA_PASSWORD'),
```

The password is encrypted by the client using the server's certificate before transmission (when `security_mode != None`). Never enable `security_mode: None` with username auth.

### X.509 user token

```php
'user_certificate' => '/etc/opcua/user-operator.pem',
'user_key' => '/etc/opcua/user-operator.key',
```

User cert ≠ client cert. The client cert authenticates the **application**, the user cert authenticates the **operator**. Many production servers require both.

## Trust store

`FileTrustStore` persists trusted and rejected server certs on disk. Configured per connection:

```php
'trust_store_path' => storage_path('app/opcua-trust-store'),
'trust_policy' => 'fingerprint',  // or 'fingerprint+expiry', 'full'
'auto_accept' => env('OPCUA_AUTO_ACCEPT', false),
'auto_accept_force' => false,
```

### Layout on disk

```
storage/app/opcua-trust-store/
├── trusted/
│   ├── <fingerprint>.der    -- accepted server certs
│   └── ...
└── rejected/
    └── <fingerprint>.der    -- explicitly rejected
```

### Trust policies

- `fingerprint` — match by SHA-256 of the cert bytes. Survives expiry / renewal as long as the same key is reused.
- `fingerprint+expiry` — also rejects expired certs.
- `full` — full X.509 chain validation against `ca_certificate`. Most strict.

### TOFU (Trust On First Use)

Set `auto_accept: true` for dev / first-time bootstrap:

1. App connects, server presents cert
2. Cert is unknown → cert is added to `trusted/` and accepted
3. Subsequent connects validate against the stored cert

Combine with `opcua-cli trust opc.tcp://server:4840` from the CLI to seed the store before deploying:

```bash
opcua-cli trust opc.tcp://server:4840 --trust-store=/var/www/storage/app/opcua-trust-store
```

Then deploy with `auto_accept: false` — any cert change will trigger `UntrustedCertificateException`.

### Manual cert management

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Add a specific server cert
Opcua::trustCertificate(file_get_contents('/path/to/server.der'));

// Remove by fingerprint
Opcua::untrustCertificate('a1:b2:c3:...');
```

## Exception handling

Three exceptions specific to security:

| Exception | When | What to do |
|---|---|---|
| `UntrustedCertificateException` | Server cert not in trust store under `Strict` policy | Inspect cert, decide to trust manually or rotate to a trusted CA |
| `WriteTypeDetectionException` | `auto_detect_write_type` failed (couldn't read node metadata) | Disable auto-detect for that node or supply `$type` explicitly |
| `WriteTypeMismatchException` | Detected type doesn't match the PHP value (e.g., string into Int32) | Convert the value, or supply `$type` explicitly to override |

```php
use PhpOpcua\Client\Exception\UntrustedCertificateException;

try {
    Opcua::read('i=2259');
} catch (UntrustedCertificateException $e) {
    Log::warning('OPC UA server cert untrusted', [
        'fingerprint' => $e->getCertificateFingerprint(),
        'subject' => $e->getCertificateSubject(),
        'endpoint' => $e->getEndpointUrl(),
    ]);
    abort(503, 'Plant connectivity unavailable');
}
```

## Secrets at rest

Always keep credentials in `.env`, never in `config/opcua.php` (which is committed). Use Laravel's `php artisan key:generate` and `Crypt::encryptString()` for any stored credentials beyond plain env.

For multi-tenant setups, encrypt per-tenant credentials with `Crypt::encryptString()`, then decrypt before passing to `connectTo()`. The `Encrypter` uses APP_KEY, so rotating that key requires re-encrypting all tenant creds.

## Network-level

- **Firewall.** Restrict outbound 4840/4843/4848/etc. to OPC UA server hosts. Most PHP-FPM hosts should never speak OPC UA to arbitrary internet endpoints.
- **mTLS at the network edge** (HAProxy / Envoy) when crossing an untrusted segment. OPC UA's transport security is good, but a defense-in-depth layer is cheap.
- **IPC auth token.** Set `OPCUA_AUTH_TOKEN` even on a single-host setup. Defense against a compromised neighbor on the same Unix socket.

## Compliance notes

- **OPC UA Part 2** defines security objectives. Read it once; the abbreviations (`SecureChannel`, `Session`, `UserTokenPolicy`) come from there.
- **NIST SP 800-82** treats OPC UA as a "field bus" — ICS/SCADA controls apply.
- **EU NIS2**: industrial control systems are explicitly in scope. Audit trail for cert changes is helpful — use `ServerCertificateManuallyTrusted` event with a queued listener that logs to an immutable audit table.

## Quick security checklist

- [ ] `security_policy` ≥ `Basic256Sha256` in production
- [ ] `security_mode` = `SignAndEncrypt`
- [ ] Real client cert supplied (not auto-generated)
- [ ] Cert key file mode `0600`, owned by web user
- [ ] `trust_store_path` set to a deploy-volume directory (not tmpfs)
- [ ] `auto_accept` = `false` after bootstrap
- [ ] `OPCUA_AUTH_TOKEN` set, ≥ 32 bytes random
- [ ] `OPCUA_PASSWORD` / X.509 user creds only in `.env`, not config
- [ ] Daemon socket `socket_mode` = `0600`
- [ ] Firewall restricts outbound OPC UA ports to known servers
