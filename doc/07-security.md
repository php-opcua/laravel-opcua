# Security

## Security Policies

Each policy defines the algorithms used for encryption and signing:

| Policy | Asymmetric Sign | Asymmetric Encrypt | Symmetric Sign | Symmetric Encrypt | Min Key |
|--------|----------------|-------------------|---------------|-------------------|---------|
| None | — | — | — | — | — |
| Basic128Rsa15 | RSA-SHA1 | RSA-PKCS1-v1_5 | HMAC-SHA1 | AES-128-CBC | 1024 bit |
| Basic256 | RSA-SHA1 | RSA-OAEP | HMAC-SHA1 | AES-256-CBC | 1024 bit |
| Basic256Sha256 | RSA-SHA256 | RSA-OAEP | HMAC-SHA256 | AES-256-CBC | 2048 bit |
| Aes128Sha256RsaOaep | RSA-SHA256 | RSA-OAEP | HMAC-SHA256 | AES-128-CBC | 2048 bit |
| Aes256Sha256RsaPss | RSA-PSS-SHA256 | RSA-OAEP-SHA256 | HMAC-SHA256 | AES-256-CBC | 2048 bit |

> **Tip:** For new deployments, use `Basic256Sha256` or `Aes256Sha256RsaPss`. The older policies (`Basic128Rsa15`, `Basic256`) exist for legacy server compatibility.

## Security Modes

| Mode | Config value | Int | Description |
|------|-------------|-----|-------------|
| None | `None` | `1` | No security |
| Sign | `Sign` | `2` | Messages signed but not encrypted |
| SignAndEncrypt | `SignAndEncrypt` | `3` | Messages signed and encrypted |

## Configuration via .env

```dotenv
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/path/to/client.pem
OPCUA_CLIENT_KEY=/path/to/client.key
OPCUA_CA_CERT=/path/to/ca.pem
```

## Configuration via config/opcua.php

```php
'connections' => [
    'secure-plc' => [
        'endpoint'           => 'opc.tcp://10.0.0.10:4840',
        'security_policy'    => 'Basic256Sha256',
        'security_mode'      => 'SignAndEncrypt',
        'client_certificate' => '/etc/opcua/certs/client.pem',
        'client_key'         => '/etc/opcua/certs/client.key',
        'ca_certificate'     => '/etc/opcua/certs/ca.pem',
    ],
],
```

## Authentication

### Anonymous

No `username` and no `user_certificate` — the client connects anonymously (default).

### Username / Password

```dotenv
OPCUA_USERNAME=admin
OPCUA_PASSWORD=secret
```

Or per-connection in config:

```php
'connections' => [
    'default' => [
        'endpoint' => 'opc.tcp://...',
        'username' => 'admin',
        'password' => 'secret',
    ],
],
```

### X.509 Certificate

```php
'connections' => [
    'cert-auth' => [
        'endpoint'         => 'opc.tcp://...',
        'user_certificate' => '/path/to/user-cert.pem',
        'user_key'         => '/path/to/user-key.pem',
    ],
],
```

## Certificate Setup

### Generating Test Certificates

```bash
# 1. Create a CA
openssl genpkey -algorithm RSA -out ca.key -pkeyopt rsa_keygen_bits:2048
openssl req -x509 -new -key ca.key -days 365 -out ca.pem \
  -subj "/CN=Test CA"

# 2. Create a client certificate signed by the CA
openssl genpkey -algorithm RSA -out client.key -pkeyopt rsa_keygen_bits:2048
openssl req -new -key client.key -out client.csr \
  -subj "/CN=OPC UA Client" \
  -addext "subjectAltName=URI:urn:opcua-php-client:client"
openssl x509 -req -in client.csr -CA ca.pem -CAkey ca.key \
  -CAcreateserial -days 365 -out client.pem \
  -copy_extensions copy
```

> **Note:** The `subjectAltName` URI is required by OPC UA. It must match the application URI your server expects.

### Auto-Generated Certificates

When a security policy and mode are configured but no `client_certificate` / `client_key` are provided, the underlying client automatically generates a self-signed RSA 2048 certificate in memory with proper OPC UA extensions.

```php
'connections' => [
    'auto-cert' => [
        'endpoint'        => 'opc.tcp://auto-accept-server:4840',
        'security_policy' => 'Basic256Sha256',
        'security_mode'   => 'SignAndEncrypt',
        // No client_certificate / client_key — auto-generated
    ],
],
```

> **Warning:** Auto-generated certificates are ephemeral. Every new `Client` instance gets a different certificate. For production, always provide your own.

## Fluent API

Security can also be set programmatically on a client instance:

```php
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = Opcua::connection();
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');
```

Or via `connectTo` with inline config:

```php
$client = Opcua::connectTo('opc.tcp://10.0.0.10:4840', [
    'security_policy'    => 'Basic256Sha256',
    'security_mode'      => 'SignAndEncrypt',
    'username'           => 'operator',
    'password'           => 'secret',
    'client_certificate' => '/etc/opcua/certs/client.pem',
    'client_key'         => '/etc/opcua/certs/client.key',
    'ca_certificate'     => '/etc/opcua/certs/ca.pem',
]);
```

## Policy Resolution

The `security_policy` config key accepts both short names and full OPC UA URIs:

```php
// Both are equivalent
'security_policy' => 'Basic256Sha256',
'security_policy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
```

Supported short names: `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`.

## Mode Resolution

The `security_mode` config key accepts names or integers:

```php
// All equivalent
'security_mode' => 'SignAndEncrypt',
'security_mode' => 3,
```

## Connection Flow

Here is what happens when you call `Opcua::connect()` with security enabled:

```
Client                          Server
  |                               |
  |--- HEL ---------------------->|  TCP handshake
  |<-- ACK -----------------------|
  |                               |
  |--- OPN (asymmetric) --------->|  Encrypted with server's public key
  |<-- OPN response --------------|  Contains server nonce
  |                               |
  |   [derive symmetric keys      |
  |    from shared nonces]        |
  |                               |
  |--- CreateSession (sym) ------>|  AES encrypted, HMAC signed
  |<-- CreateSession resp --------|
  |                               |
  |--- ActivateSession (sym) ---->|  Contains credentials / cert
  |<-- ActivateSession resp ------|
  |                               |
  |--- Read / Browse / ... ------>|  All encrypted and signed
  |<-- Responses -----------------|
  |                               |
  |--- CLO ---------------------->|  Close secure channel
```

**Phase 1 — Discovery.** The client connects without security, calls `GetEndpoints`, and retrieves the server's certificate.

**Phase 2 — Asymmetric (OpenSecureChannel).** The client sends an OPN request encrypted with the server's public key. Both sides exchange nonces. Symmetric keys are derived from the shared nonces.

**Phase 3 — Symmetric (Session + Messages).** All subsequent messages (CreateSession, ActivateSession, Read, Write, etc.) use the derived symmetric keys — signed with HMAC and encrypted with AES-CBC.

> This is all handled transparently by the underlying `opcua-client` library. You only need to configure the policy, mode, and certificates.

## Endpoint Discovery

Discover available security configurations before connecting:

```php
$client = Opcua::connection();
$endpoints = $client->getEndpoints('opc.tcp://192.168.1.100:4840');

foreach ($endpoints as $ep) {
    echo "{$ep->securityPolicyUri}\n";
    echo "  Mode: {$ep->securityMode->name}\n";
    echo "  Auth: " . implode(', ', array_map(fn($t) => $t->tokenType->name, $ep->userIdentityTokens)) . "\n";
}
```

## Session Manager Daemon Security

When using the session manager daemon (`php artisan opcua:session`), additional security layers protect the IPC channel:

| Layer | Description |
|-------|-------------|
| **IPC authentication** | Shared-secret token validated with timing-safe `hash_equals()` |
| **Socket permissions** | `0600` by default (owner-only access) |
| **Method whitelist** | Only 37 documented OPC UA operations allowed |
| **Credential protection** | Passwords and private key paths stripped after connection |
| **Session limits** | Configurable maximum to prevent resource exhaustion |
| **Certificate path restrictions** | `allowed_cert_dirs` constrains certificate file access |
| **Input size limit** | IPC requests capped at 1MB |
| **Connection protection** | 30s per-connection timeout, max 50 concurrent IPC connections |
| **Error sanitization** | Error messages truncated, file paths stripped |
| **PID file lock** | Prevents multiple daemon instances |

### Recommended Production Setup

```bash
# Generate a daemon auth token
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token
```

```dotenv
OPCUA_AUTH_TOKEN=<contents of /etc/opcua/daemon.token>
OPCUA_SOCKET_PATH=/var/run/opcua-session-manager.sock
OPCUA_MAX_SESSIONS=50
```

```bash
php artisan opcua:session --socket-mode=0660
```

> **Note:** When using the daemon with certificate-based OPC UA connections, certificate paths must be absolute. Set `allowed_cert_dirs` in `config/opcua.php` to restrict which directories can be accessed.

## Trust Store (v4.0+)

The trust store provides persistent certificate trust management for OPC UA connections. Instead of relying solely on a CA certificate chain, you can build a local trust store of known server certificates.

### FileTrustStore

`FileTrustStore` persists trusted certificates as DER files in a directory on disk:

```php
use PhpOpcua\Client\Security\FileTrustStore;

$trustStore = new FileTrustStore('/var/opcua/trust');
```

The directory is created automatically if it does not exist. Each trusted certificate is stored as a file named by its SHA-256 fingerprint.

### TrustPolicy Enum

The `TrustPolicy` enum controls how certificates are validated against the trust store:

| Value | Enum | Description |
|-------|------|-------------|
| `fingerprint` | `TrustPolicy::Fingerprint` | Matches certificates by SHA-256 fingerprint only |
| `fingerprint+expiry` | `TrustPolicy::FingerprintAndExpiry` | Matches by fingerprint and rejects expired certificates |
| `full` | `TrustPolicy::Full` | Full X.509 validation including chain, expiry, and key usage |

```php
use PhpOpcua\Client\Security\TrustPolicy;

$client->setTrustPolicy(TrustPolicy::Fingerprint);
```

### Configuration

```dotenv
OPCUA_TRUST_STORE_PATH=/var/opcua/trust
OPCUA_TRUST_POLICY=fingerprint
OPCUA_AUTO_ACCEPT=false
OPCUA_AUTO_ACCEPT_FORCE=false
```

Or in `config/opcua.php`:

```php
'connections' => [
    'default' => [
        'endpoint'           => 'opc.tcp://10.0.0.10:4840',
        'trust_store_path'   => '/var/opcua/trust',
        'trust_policy'       => 'fingerprint',       // fingerprint, fingerprint+expiry, full
        'auto_accept'        => false,
        'auto_accept_force'  => false,
    ],
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `trust_store_path` | `string\|null` | `null` | Directory for persisting trusted certificates. When `null`, no trust store is used. |
| `trust_policy` | `string\|null` | `null` | Trust validation policy. One of: `fingerprint`, `fingerprint+expiry`, `full`. |
| `auto_accept` | `bool` | `false` | Automatically trust unknown server certificates on first encounter (Trust On First Use / TOFU). |
| `auto_accept_force` | `bool` | `false` | Like `auto_accept`, but also overwrites previously trusted certificates if they change. Use with caution. |

### Auto-Accept (TOFU Mode)

When `auto_accept` is `true`, the client automatically trusts any server certificate it encounters for the first time and stores it in the trust store. Subsequent connections verify the server presents the same certificate.

This is useful for development and controlled environments where manual certificate exchange is impractical.

> **Warning:** TOFU mode is vulnerable to man-in-the-middle attacks on the first connection. For production environments, pre-populate the trust store or use `auto_accept_force=false`.

### Programmatic Trust API

You can trust or untrust certificates programmatically:

```php
$client = Opcua::connect();

// Trust a certificate (DER-encoded binary)
$client->trustCertificate($derBytes);

// Remove a previously trusted certificate
$client->untrustCertificate($derBytes);
```

This is useful when building admin interfaces that let operators approve or revoke server certificates.

### UntrustedCertificateException

When the client encounters an untrusted server certificate and `auto_accept` is `false`, it throws an `UntrustedCertificateException`:

```php
use PhpOpcua\Client\Exception\UntrustedCertificateException;

try {
    $client = Opcua::connect();
} catch (UntrustedCertificateException $e) {
    $fingerprint = $e->getFingerprint();
    $der = $e->getCertificate();

    // Present to the user for approval, then:
    $client->trustCertificate($der);
    $client = Opcua::connect(); // retry
}
```

The exception provides:
- `getFingerprint()` -- SHA-256 fingerprint of the untrusted certificate
- `getCertificate()` -- DER-encoded certificate bytes for storage/inspection

### Fluent API

```php
use PhpOpcua\Client\Security\TrustPolicy;

$client = Opcua::connection();
$client->setTrustStorePath('/var/opcua/trust');
$client->setTrustPolicy(TrustPolicy::FingerprintAndExpiry);
$client->autoAccept(true);
$client->connect('opc.tcp://192.168.1.100:4840');
```
