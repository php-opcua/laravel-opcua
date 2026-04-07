# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 3.x     | Yes       |
| 2.x     | No        |
| 1.x     | No        |

## Reporting a Vulnerability

If you discover a security vulnerability in this library, please report it responsibly.

**Do not open a public issue.** Instead, send an email to [gianfri.aur@gmail.com](mailto:gianfri.aur@gmail.com) with:

- A description of the vulnerability
- Steps to reproduce
- The affected version(s)
- Any potential impact assessment

You should receive an acknowledgment within 48 hours. From there, we'll work together to understand the scope and develop a fix before any public disclosure.

## Scope

This policy covers the `php-opcua/laravel-opcua` package itself. For vulnerabilities in dependencies or related packages, please report them to the respective maintainers:

- [opcua-client](https://github.com/php-opcua/opcua-client)
- [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager)
- [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite)

## Security Considerations

This package integrates OPC UA into Laravel applications. Security is enforced at three levels:

### OPC UA Layer

The underlying `opcua-client` implements the full OPC UA security stack. When deploying in production:

- Use `SecurityPolicy::Basic256Sha256` or stronger
- Use `SecurityMode::SignAndEncrypt`
- Provide proper CA-signed certificates (don't rely on auto-generated self-signed certs)
- Keep PHP and OpenSSL up to date

### Session Manager Daemon

When using the session manager daemon (`php artisan opcua:session`), additional protections apply:

- **IPC authentication** — shared-secret token validated with timing-safe `hash_equals()`. Configure via `OPCUA_AUTH_TOKEN` env var
- **Socket permissions** — `0600` by default (owner-only read/write). Adjust with `--socket-mode`
- **Method whitelist** — only documented OPC UA operations allowed via IPC
- **Credential protection** — passwords and private key paths stripped from session metadata immediately after connection
- **Session limits** — configurable maximum to prevent resource exhaustion
- **Certificate path restrictions** — `allowed_cert_dirs` constrains certificate file access

### Laravel Layer

- Sensitive configuration values (`OPCUA_AUTH_TOKEN`, `OPCUA_PASSWORD`, `OPCUA_CLIENT_KEY`) should be stored in `.env` and never committed to version control
- The `config/opcua.php` file reads all secrets from environment variables by default
- The session manager socket file is created inside `storage/app/` with restricted permissions
