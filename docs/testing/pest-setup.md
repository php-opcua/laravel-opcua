---
eyebrow: 'Docs · Testing'
lede:    'Pest harness for OPC UA-aware Laravel tests. Disabling the daemon for unit tests, the standard fixtures, and the three test layers most apps converge on.'

see_also:
  - { href: './mocking-the-facade.md',         meta: '5 min' }
  - { href: './using-mock-client.md',          meta: '5 min' }
  - { href: './integration-tests.md',          meta: '6 min' }

prev: { label: 'Trust store',     href: '../security/trust-store.md' }
next: { label: 'Mocking the facade', href: './mocking-the-facade.md' }
---

# Pest setup

The package ships first-class Pest support. The conventions here
mirror Laravel's first-party testing patterns — nothing exotic.

## Disable the daemon

The single most important rule: **don't hit the daemon in unit
or feature tests**. Set this in `tests/Pest.php`:

<!-- @code-block language="php" label="tests/Pest.php" -->
```php
uses(Tests\TestCase::class)->in('Feature', 'Unit');

beforeEach(function () {
    config([
        'opcua.session_manager.enabled' => false,
    ]);
});
```
<!-- @endcode-block -->

With managed mode off, the package falls through to direct
connections — and you'll mock those out per test.

## Three test layers

Most Laravel apps with OPC UA settle on three test layers:

| Layer            | Tests                                                   | Touches OPC UA?         |
| ---------------- | ------------------------------------------------------- | ----------------------- |
| Unit             | Your business logic                                     | No (mock everything)    |
| Feature          | HTTP endpoints, jobs, listeners                         | No (mock the facade)    |
| Integration      | End-to-end against a real test server / fake daemon     | Yes                     |

Unit and Feature run on every push. Integration runs nightly or
on PR-merge.

## Unit tests — mock everything

<!-- @code-block language="php" label="unit test" -->
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

it('persists a good reading', function () {
    $client = MockClient::create();
    $client->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

    $service = new TagReadingService($client);
    $service->capture('ns=2;s=Speed');

    expect(PlcReading::count())->toBe(1);
    expect(PlcReading::first()->value)->toBe(75.0);
});
```
<!-- @endcode-block -->

`MockClient` implements the same `OpcUaClientInterface` as the
real package — see [Using MockClient](./using-mock-client.md).

## Feature tests — mock the facade

<!-- @code-block language="php" label="feature test" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\DataValue;

it('returns the current speed', function () {
    Opcua::shouldReceive('read')
        ->with('ns=2;s=Speed')
        ->andReturn(DataValue::ofDouble(75.0));

    $response = $this->get('/tags/ns=2;s=Speed/latest');

    $response->assertOk();
    $response->assertJson(['value' => 75.0]);
});
```
<!-- @endcode-block -->

Mockery-based mocking, just like any other facade. See
[Mocking the facade](./mocking-the-facade.md).

## Integration tests — real daemon, real server

<!-- @code-block language="php" label="integration test" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

uses(Tests\IntegrationTestCase::class)->in('Integration');

beforeEach(function () {
    // The IntegrationTestCase boots a daemon + opens a connection to
    // a known test OPC UA server.
});

it('reads from the test server', function () {
    $dv = Opcua::read('i=2256');     // Server_ServerStatus_State

    expect($dv->statusCode)->toBe(0);
    expect($dv->value)->toBe(0);       // 0 = Running
});
```
<!-- @endcode-block -->

`IntegrationTestCase` is yours to define — it boots the daemon
as a fixture and points the test app at it. See [Integration
tests](./integration-tests.md).

## Useful Pest hooks

### Disable the OPC UA cache in tests

<!-- @code-block language="php" label="cache reset" -->
```php
beforeEach(function () {
    Cache::flush();    // clear OPC UA's metadata cache
});
```
<!-- @endcode-block -->

### Fix the time

For tests that assert on timestamps:

<!-- @code-block language="php" label="freeze time" -->
```php
beforeEach(function () {
    $this->travelTo('2026-05-15 10:00:00');
});
```
<!-- @endcode-block -->

### Avoid the event leak

Listeners on `DataChangeReceived` will fire in tests if the event
is dispatched. To avoid side-effects:

<!-- @code-block language="php" label="fake events" -->
```php
use Illuminate\Support\Facades\Event;
use PhpOpcua\Client\Event\DataChangeReceived;

beforeEach(function () {
    Event::fake([DataChangeReceived::class]);
});
```
<!-- @endcode-block -->

…then assert dispatches without running listeners:

<!-- @code-block language="php" label="assert dispatched" -->
```php
it('dispatches a data change event on subscribe', function () {
    // ... trigger the code under test
    Event::assertDispatched(DataChangeReceived::class);
});
```
<!-- @endcode-block -->

### Queue side-effects

<!-- @code-block language="php" label="fake queue" -->
```php
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('dispatches a job', function () {
    // ...
    Queue::assertPushed(SamplePlc::class);
});
```
<!-- @endcode-block -->

## Composing the three layers — `tests/Pest.php`

A realistic `Pest.php`:

<!-- @code-block language="php" label="tests/Pest.php — full" -->
```php
<?php

uses(Tests\TestCase::class)->in('Feature', 'Unit');
uses(Tests\IntegrationTestCase::class)->in('Integration');

beforeEach(function () {
    if (! $this instanceof Tests\IntegrationTestCase) {
        // Unit / Feature — fully mock
        config([
            'opcua.session_manager.enabled' => false,
        ]);
    }
});
```
<!-- @endcode-block -->

`tests/TestCase.php` extends `Tests\CreatesApplication`;
`IntegrationTestCase` adds daemon-startup logic.

## Test data factories

For tests that need `DataValue` instances, a helper:

<!-- @code-block language="php" label="DataValue factory" -->
```php
function dv(mixed $value, int $status = 0): DataValue
{
    return new DataValue(
        value: $value,
        statusCode: $status,
        sourceTimestamp: new DateTimeImmutable(),
    );
}
```
<!-- @endcode-block -->

In a test:

<!-- @code-block language="php" label="using factory" -->
```php
$client->onRead('ns=2;s=Speed', fn() => dv(75.0));
$client->onRead('ns=2;s=Bad',   fn() => dv(0.0, status: 0x80000000));
```
<!-- @endcode-block -->

## Running

<!-- @code-block language="bash" label="terminal" -->
```bash
# All except integration
vendor/bin/pest --exclude=Integration

# Integration only
vendor/bin/pest tests/Integration

# Single test
vendor/bin/pest --filter="returns the current speed"
```
<!-- @endcode-block -->

## CI matrix

<!-- @code-block language="text" label=".github/workflows/test.yml" -->
```text
jobs:
  unit-and-feature:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: 8.4 }
      - run: composer install
      - run: vendor/bin/pest --exclude=Integration

  integration:
    runs-on: ubuntu-latest
    services:
      opcua:
        image: ghcr.io/php-opcua/uanetstandard-test-suite:latest
        ports: ['4840:4840']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: 8.4 }
      - run: composer install
      - run: vendor/bin/pest tests/Integration
```
<!-- @endcode-block -->

Unit / Feature on every push; Integration on a separate matrix
leg with the test server as a sidecar.

## Test coverage targets

The package aims for 99.5% coverage. For your application:

| Test target                                | Reasonable coverage         |
| ------------------------------------------ | --------------------------- |
| OPC UA-touching services (read/write logic) | High — 90%+                 |
| Event listeners                             | High — 90%+                 |
| OPC UA-touching controllers                 | Medium — 80%+               |
| Trust-store / cert rotation tooling          | Medium — manual rotation works too |

Don't aim for 100% — there are always cases that are
disproportionate to test (real-cert handshake failures, in
particular).

## Where to read next

- [Mocking the facade](./mocking-the-facade.md) — Mockery-based
  facade mocks.
- [Using MockClient](./using-mock-client.md) — opcua-client's
  testing fake.
- [Integration tests](./integration-tests.md) — real-server
  testing.
