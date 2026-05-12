---
eyebrow: 'Docs · Testing'
lede:    'Integration tests against a real test server and a real daemon. Docker-Compose fixtures, CI patterns, and the cost/benefit trade-off vs mocked tests.'

see_also:
  - { href: './pest-setup.md',                        meta: '5 min' }
  - { href: '../session-manager/starting-the-daemon.md', meta: '5 min' }
  - { href: 'https://github.com/php-opcua/uanetstandard-test-suite', meta: 'external', label: 'uanetstandard-test-suite' }

prev: { label: 'Using MockClient',         href: './using-mock-client.md' }
next: { label: 'Octane & FrankenPHP',      href: '../integrations/octane-and-frankenphp.md' }
---

# Integration tests

Integration tests boot a **real OPC UA server**, a **real
daemon**, and exercise the full stack. The cost is real (Docker
fixtures, slow tests, CI complexity). The benefit is real too —
the only way to catch wire-level regressions.

<!-- @callout type="note" -->
**Run integration tests separately from unit / feature.** They're
slow and need fixtures. Most apps run them on PR merge, not on
every commit.
<!-- @endcallout -->

## Three things you need

| Component             | Source                                              |
| --------------------- | --------------------------------------------------- |
| OPC UA test server    | `ghcr.io/php-opcua/uanetstandard-test-suite:latest` |
| Daemon                | Boot via `php artisan opcua:session` as a fixture   |
| Laravel test harness  | `IntegrationTestCase` you define                    |

## Docker-Compose fixture

A `docker-compose.test.yml` for the test server:

<!-- @code-block language="text" label="docker-compose.test.yml" -->
```text
services:
  opcua-test-server:
    image: ghcr.io/php-opcua/uanetstandard-test-suite:latest
    ports:
      - "4840:4840"      # unsecured endpoint
      - "4841:4841"      # secured (Basic256Sha256)
      - "4842:4842"      # secured + username/password
    healthcheck:
      test: ["CMD", "sh", "-c", "nc -z localhost 4840"]
      interval: 5s
      timeout: 2s
      retries: 10
```
<!-- @endcode-block -->

Run:

<!-- @code-block language="bash" label="terminal — fixture up" -->
```bash
docker compose -f docker-compose.test.yml up -d
```
<!-- @endcode-block -->

The test suite from `php-opcua/uanetstandard-test-suite` exposes
8 different endpoints covering every security policy and auth
flow. For most Laravel tests, the `:4840` (unsecured) endpoint
is enough.

## IntegrationTestCase

<!-- @code-block language="php" label="tests/IntegrationTestCase.php" -->
```php
namespace Tests;

class IntegrationTestCase extends TestCase
{
    protected ?int $daemonPid = null;
    protected string $socketPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->socketPath = sys_get_temp_dir() . '/opcua-test-' . getmypid() . '.sock';

        config([
            'opcua.session_manager.enabled' => true,
            'opcua.session_manager.socket_path' => $this->socketPath,
            'opcua.connections.default' => [
                'endpoint'        => 'opc.tcp://localhost:4840',
                'security_policy' => 'None',
                'security_mode'   => 'None',
                'timeout'         => 5.0,
            ],
        ]);

        $this->startDaemon();
    }

    protected function tearDown(): void
    {
        $this->stopDaemon();
        parent::tearDown();
    }

    private function startDaemon(): void
    {
        // opcua:session has no --socket-path flag — set the socket
        // path via OPCUA_SOCKET_PATH in the environment instead.
        $cmd = sprintf(
            'OPCUA_SOCKET_PATH=%s php %s/artisan opcua:session > /tmp/daemon.log 2>&1 & echo $!',
            escapeshellarg($this->socketPath),
            base_path(),
        );
        $this->daemonPid = (int) trim(shell_exec($cmd));

        // Wait for socket
        $timeout = microtime(true) + 5;
        while (! file_exists($this->socketPath) && microtime(true) < $timeout) {
            usleep(50_000);
        }
    }

    private function stopDaemon(): void
    {
        if ($this->daemonPid) {
            posix_kill($this->daemonPid, SIGTERM);
            // Wait briefly for cleanup
            usleep(200_000);
        }
        @unlink($this->socketPath);
    }
}
```
<!-- @endcode-block -->

A typical pattern — start the daemon per test class, point Laravel at it,
clean up at the end.

## Test examples

### Server liveness

<!-- @code-block language="php" label="liveness test" -->
```php
uses(Tests\IntegrationTestCase::class)->in('Integration');

it('the test server is up', function () {
    $dv = Opcua::read('i=2256');     // Server_ServerStatus_State

    expect($dv->statusCode)->toBe(0);
    expect($dv->getValue())->toBe(0);  // 0 = Running
});
```
<!-- @endcode-block -->

### Read / write round-trip

<!-- @code-block language="php" label="round-trip" -->
```php
it('writes and reads back', function () {
    Opcua::write('ns=2;s=TestWritableInt', 42);

    $dv = Opcua::read('ns=2;s=TestWritableInt');

    expect($dv->statusCode)->toBe(0);
    expect((int) $dv->getValue())->toBe(42);
});
```
<!-- @endcode-block -->

### Subscription

<!-- @code-block language="php" label="subscription test" -->
```php
it('receives a data change', function () {
    $received = null;

    \Event::listen(function (\PhpOpcua\Client\Event\DataChangeReceived $event) use (&$received) {
        $received = $event->dataValue->getValue();
    });

    $client = app(\PhpOpcua\LaravelOpcua\OpcuaManager::class)->connection();
    $sub = $client->createSubscription(publishingInterval: 100.0);

    $client->createMonitoredItems($sub->subscriptionId)
        ->add('ns=0;i=2258', clientHandle: 1)   // CurrentTime — always changing
        ->execute();

    // Drain publish responses for 1 second
    $end = microtime(true) + 1.0;
    while (microtime(true) < $end && $received === null) {
        $client->publish();
        usleep(50_000);
    }

    expect($received)->not->toBeNull();
});
```
<!-- @endcode-block -->

### Error handling

<!-- @code-block language="php" label="bad node error" -->
```php
it('raises on a bad node', function () {
    expect(fn() => Opcua::read('ns=99;s=DoesNotExist'))
        ->toThrow(\PhpOpcua\Client\Exception\ServiceException::class);
});
```
<!-- @endcode-block -->

## Per-policy tests

For tests that exercise each security policy, parameterise:

<!-- @code-block language="php" label="policy matrix" -->
```php
dataset('policies', [
    ['None',             'None',             4840],
    ['Basic256Sha256',   'SignAndEncrypt',   4841],
    ['Basic256Sha256',   'SignAndEncrypt',   4842, 'user', 'pass'],
]);

it('connects with policy', function (
    string $policy, string $mode, int $port,
    ?string $user = null, ?string $pass = null,
) {
    config([
        'opcua.connections.default.endpoint'        => "opc.tcp://localhost:{$port}",
        'opcua.connections.default.security_policy' => $policy,
        'opcua.connections.default.security_mode'   => $mode,
        'opcua.connections.default.username'        => $user,
        'opcua.connections.default.password'        => $pass,
        'opcua.connections.default.client_cert_path' => __DIR__ . '/fixtures/client.pem',
        'opcua.connections.default.client_key_path' => __DIR__ . '/fixtures/client.key',
    ]);

    $dv = Opcua::read('i=2256');
    expect($dv->statusCode)->toBe(0);
})->with('policies');
```
<!-- @endcode-block -->

The fixture certs in `tests/fixtures/` need to be pre-trusted on
the test server — typically baked into the Docker image.

## CI integration

<!-- @code-block language="text" label=".github/workflows/integration.yml" -->
```text
name: Integration

on:
  pull_request:
    branches: [main]
  push:
    branches: [main]

jobs:
  integration:
    runs-on: ubuntu-latest

    services:
      opcua:
        image: ghcr.io/php-opcua/uanetstandard-test-suite:latest
        ports:
          - 4840:4840
          - 4841:4841
          - 4842:4842
        options: >-
          --health-cmd "nc -z localhost 4840"
          --health-interval 5s
          --health-timeout 2s
          --health-retries 10

    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pcntl, sockets, openssl
      - run: composer install --prefer-dist
      - run: vendor/bin/pest tests/Integration --testdox
```
<!-- @endcode-block -->

The `services.opcua` block boots the test server as a CI service.
GitHub Actions waits for the healthcheck to pass before running
the test step.

## Performance budgets

Integration tests are slow. Set an expectation:

| Layer            | Per-test budget   |
| ---------------- | ----------------- |
| Unit              | < 50 ms           |
| Feature           | < 200 ms          |
| Integration       | < 5 s             |

If integration tests run > 5 s each, the suite gets unusable
fast. Aggressive teardown / startup parallelism keeps it tight.

## Parallel integration testing

Pest 3 supports parallel execution:

<!-- @code-block language="bash" label="terminal — parallel" -->
```bash
vendor/bin/pest tests/Integration --parallel --processes=4
```
<!-- @endcode-block -->

For OPC UA integration, parallel needs **separate daemon sockets
per process**. The `IntegrationTestCase` above already uses
`getmypid()` in the socket path — that's the trick that makes
parallel safe.

## What integration tests catch

| Class of bug                          | Caught by integration? |
| ------------------------------------- | ---------------------- |
| Daemon ↔ Laravel IPC framing changes   | **Yes**               |
| Subscription publish-loop regressions  | **Yes**               |
| TypeSerializer wire round-trips        | **Yes**               |
| Cert/policy negotiation mismatches     | **Yes** (real handshake) |
| Reconnect after server restart         | **Yes** (with fixture) |
| Listener business logic bugs           | No — that's unit/feature |

The rule: **integration tests catch what unit tests can't see
because it lives at the wire**. Unit tests catch what integration
tests can't economically cover (every business-logic branch).

## When NOT to write integration tests

- For pure business-logic flows — too slow, too brittle.
- For UI / view rendering — Laravel browser tests are better.
- For permission/policy logic — they're orthogonal to OPC UA.

The integration suite is small by design. 20-50 tests, covering
the critical happy paths and the most likely regressions.

## Where to read next

You've finished **Testing**. Next: [Integrations · Octane and
FrankenPHP](../integrations/octane-and-frankenphp.md) — the
runtime-specific patterns.
