---
eyebrow: 'Docs · Reference'
lede:    'Every exception the package can raise, when it raises them, and the right Laravel-side response. From transport failures to spec-defined service errors.'

see_also:
  - { href: '../observability/debugging.md',          meta: '5 min' }
  - { href: '../operations/reading.md',               meta: '6 min' }
  - { href: '../operations/writing.md',               meta: '5 min' }

prev: { label: 'Artisan commands',  href: './artisan-commands.md' }
next: { label: 'Persistent tag history', href: '../recipes/persistent-tag-history.md' }
---

# Exceptions

Every exception this package can raise comes from the underlying
`opcua-client` library (or, for managed mode, from
`opcua-session-manager`). All of them live in
`PhpOpcua\Client\Exception\` (or `PhpOpcua\SessionManager\Exception\`
for daemon-specific failures) and inherit from
`PhpOpcua\Client\Exception\OpcUaException`, which extends
`\RuntimeException`.

The authoritative catalogue and per-exception field lists are in the
sister-package docs:

- [`opcua-client` · Exceptions reference](https://github.com/php-opcua/opcua-client/blob/master/docs/reference/exceptions.md)
- [`opcua-session-manager` · Exceptions reference](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/reference/exceptions.md)

This page summarises the slice that matters most for typical Laravel
applications.

## Hierarchy

<!-- @code-block language="text" label="hierarchy" -->
```text
\RuntimeException
└── PhpOpcua\Client\Exception\OpcUaException
    ├── CacheCorruptedException
    ├── ConfigurationException
    ├── ConnectionException
    ├── EncodingException
    ├── InvalidNodeIdException
    ├── MissingModuleDependencyException
    ├── ModuleConflictException
    ├── ProtocolException
    │     ├── HandshakeException                ($errorCode, $errorMessage)
    │     └── MessageTypeException              ($expected, $actual)
    ├── SecurityException
    │     ├── CertificateParseException
    │     ├── OpenSslException
    │     ├── SignatureVerificationException
    │     ├── UnsupportedCurveException         ($curveName)
    │     └── UntrustedCertificateException     ($fingerprint, $certDer, $message)
    ├── ServiceException                        (getStatusCode())
    │     └── ServiceUnsupportedException       (BadServiceUnsupported)
    ├── WriteTypeDetectionException
    └── WriteTypeMismatchException              ($nodeId, $expectedType, $givenType, $message)
```
<!-- @endcode-block -->

The "buckets" worth catching specifically are
`ConnectionException`, `SecurityException`, and `ServiceException`.

## ConnectionException

Network / channel failures: TCP open, HEL/ACK timeout, socket
disconnect, channel rejected by the server. Has no custom properties
— inspect `getMessage()` and `getPrevious()`.

**Common causes**

- Server unreachable (network, firewall).
- Server stopped accepting connections.
- TCP RST mid-flight.
- In managed mode: the daemon returned `session_not_found`, which the
  client surfaces as `ConnectionException` (the session expired).

**Right response**

Retry with backoff (the package's `auto_retry` config handles this
inside `opcua-client`). After 3-5 retries, surface to the operator.

<!-- @code-block language="php" label="handling" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;

try {
    $dv = Opcua::read('ns=2;s=Speed');
} catch (ConnectionException $e) {
    Log::warning('PLC unreachable', ['message' => $e->getMessage()]);
    return response()->json(['error' => 'PLC unreachable'], 503);
}
```
<!-- @endcode-block -->

## SecurityException (and subclasses)

Anything cryptographic or trust-related. The five concrete subclasses
each carry their own fields — see the [`opcua-client` exceptions
reference](https://github.com/php-opcua/opcua-client/blob/master/docs/reference/exceptions.md)
for the full signatures.

The most actionable subclass for application code is
`UntrustedCertificateException`:

```php
use PhpOpcua\Client\Exception\UntrustedCertificateException;

try {
    Opcua::connection('plc');
} catch (UntrustedCertificateException $e) {
    // $e->fingerprint  — SHA-1 fingerprint of the server cert
    // $e->certDer      — raw DER bytes for inspection / pinning
    // $e->getMessage() — human-readable reason
    Log::warning('Untrusted PLC cert', ['fp' => $e->fingerprint]);
    abort(503, 'Server certificate not trusted');
}
```

Note that trust-store fingerprints are **SHA-1**, not SHA-256.

## ServiceException

Raised when the server returns a top-level `Bad_*` status code or a
ServiceFault. The connection itself is healthy; the operation was
rejected.

**Accessing the status code**

`$statusCode` is **private** on `ServiceException`. Use the getter —
there is no `statusName` property:

```php
$code = $e->getStatusCode();              // int (OPC UA status)
$name = StatusCode::getName($code);       // 'BadNodeIdUnknown' etc.
```

Per-item failures (a single bad node inside a `readMulti()` call) are
**not** raised as exceptions — they ride in the per-result
`statusCode` field. Always check `$dv->statusCode` after a successful
service call.

**Common causes**

| Status name             | Cause                                       |
| ----------------------- | ------------------------------------------- |
| `BadNodeIdUnknown`      | Node not in the server's address space      |
| `BadAttributeIdInvalid` | Wrong attribute for the node type           |
| `BadTypeMismatch`       | Write value doesn't match expected type     |
| `BadNotWritable`        | Node isn't writable                         |
| `BadUserAccessDenied`   | User lacks permission                       |
| `BadOutOfRange`         | Value outside engineering range             |

(`BadNodeIdUnknown` is the **status code name**, not an exception
class — there is no `BadNodeIdUnknownException`. The exception
raised is `ServiceException` with that status.)

`ServiceUnsupportedException` is a subclass for the single status
`BadServiceUnsupported` (`0x800B0000`) — useful to catch separately
when calling optional service sets like history.

**Right response**

Logic bug or permission misconfiguration. Surface the status name
(via `StatusCode::getName($e->getStatusCode())`) to the operator.

<!-- @code-block language="php" label="handling" -->
```php
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\StatusCode;

try {
    Opcua::write('ns=2;s=Setpoint', 9999);
} catch (ServiceException $e) {
    $name = StatusCode::getName($e->getStatusCode());
    if ($name === 'BadOutOfRange') {
        return response()->json([
            'error' => 'Value out of allowed range',
            'limit' => 'check the EU Range attribute',
        ], 422);
    }
    throw $e;
}
```
<!-- @endcode-block -->

## ConfigurationException

Raised at construction / `connect()` time when configuration is
invalid (`SecurityMode::SignAndEncrypt` with no client certificate
and no auto-generation path enabled, etc.).

Fix the config; no runtime recovery.

## EncodingException

Wire-codec failure — typically a server bug or a protocol version
mismatch. Open an issue against `opcua-client`.

## OpcuaException — the base

Catch this to mop up "any OPC UA failure":

<!-- @code-block language="php" label="generic catch" -->
```php
use PhpOpcua\Client\Exception\OpcUaException;

try {
    Opcua::read('ns=2;s=Speed');
} catch (OpcUaException $e) {
    Log::error('OPC UA call failed: ' . $e->getMessage());
    return null;
}
```
<!-- @endcode-block -->

Usually too broad — prefer catching specific subclasses for specific
responses.

## Daemon-only exceptions (managed mode)

When using the session manager, `ManagedClient` may raise
`PhpOpcua\SessionManager\Exception\DaemonException` for IPC-layer
issues (`auth_failed`, `payload_too_large`, `unknown_method`, …).
This does **not** extend `OpcUaException` — catch it separately if
you care to distinguish "the daemon refused us" from "OPC UA itself
went wrong".

## Laravel exception handler

For unhandled OPC UA exceptions, route them in
`app/Exceptions/Handler.php`:

<!-- @code-block language="php" label="global handler" -->
```php
use PhpOpcua\Client\Exception\{ConnectionException, ServiceException};
use PhpOpcua\Client\Types\StatusCode;

public function register(): void
{
    $this->reportable(function (ConnectionException $e) {
        Log::channel('opcua')->warning($e->getMessage());
        return false; // suppress the global error log
    });

    $this->renderable(function (ServiceException $e, Request $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'error'   => 'OPC UA service error',
                'status'  => StatusCode::getName($e->getStatusCode()),
                'message' => $e->getMessage(),
            ], 502);
        }
    });
}
```
<!-- @endcode-block -->

## Sentry / Bugsnag fingerprinting

Fingerprint by status code, not by message:

<!-- @code-block language="php" label="sentry fingerprint" -->
```php
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\StatusCode;

\Sentry::configureScope(function (\Sentry\State\Scope $scope) use ($e) {
    if ($e instanceof ServiceException) {
        $scope->setFingerprint([
            '{{ default }}',
            'opcua-service',
            StatusCode::getName($e->getStatusCode()),
        ]);
    }
});
```
<!-- @endcode-block -->

## In tests

```php
use PhpOpcua\Client\Exception\ConnectionException;

Opcua::shouldReceive('read')
    ->andThrow(new ConnectionException('PLC down'));

$response = $this->get('/speed');
$response->assertStatus(503);
```

`ConnectionException` does not take an `endpoint:` keyword argument —
it is a plain `RuntimeException` subclass with the standard
`(string $message, int $code, ?\Throwable $previous)` constructor.

## Where to read next

You've finished **Reference**. Next: [Recipes · Persistent tag
history](../recipes/persistent-tag-history.md) for the practical
patterns.
