---
name: Bug Report
about: Report a bug or unexpected behavior
title: "[BUG] "
labels: bug
assignees: ''
---

## Description

A clear and concise description of the bug.

## Steps to Reproduce

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Minimal code to reproduce the issue
```

## Expected Behavior

What you expected to happen.

## Actual Behavior

What actually happened. Include error messages or exceptions if applicable.

## Environment

- PHP version:
- Laravel version:
- Library version:
- opcua-client version:
- opcua-session-manager version:
- OPC UA server: (e.g., open62541, Prosys, Unified Automation, etc.)
- OS:

## Connection Mode

- [ ] Direct (no session manager daemon)
- [ ] Managed (via session manager daemon)

## Session Manager Configuration

_(if using the daemon)_

- Socket path:
- Auth token: (yes/no)
- Log channel:
- Cache store:

## Security Configuration

- SecurityPolicy: (e.g., None, Basic256Sha256)
- SecurityMode: (e.g., None, SignAndEncrypt)
- Authentication: (e.g., Anonymous, Username/Password, Certificate)

## Additional Context

Any additional context, logs, or stack traces.
