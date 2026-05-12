---
eyebrow: 'Docs · Testing'
lede:    'MockClient — the in-memory OpcUaClientInterface implementation from opcua-client. Faithful behaviour without a network, suitable for high-coverage unit tests.'

see_also:
  - { href: './mocking-the-facade.md',                       meta: '5 min' }
  - { href: './pest-setup.md',                               meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/testing/mock-client.md', meta: 'external', label: 'opcua-client — testing' }

prev: { label: 'Mocking the facade',  href: './mocking-the-facade.md' }
next: { label: 'Integration tests',   href: './integration-tests.md' }
---

# Using MockClient

`MockClient` is a full in-memory implementation of the
`OpcUaClientInterface`. It behaves like a real client (reads,
writes, browses, subscriptions, errors) — but everything happens
in PHP memory. No network, no daemon, no server.

## When to reach for it

| Scenario                                             | MockClient appropriate?    |
| ---------------------------------------------------- | -------------------------- |
| Unit-test a service that does 5+ reads/writes        | **Yes**                    |
| Test subscription callbacks                          | **Yes**                    |
| Test code that handles write failures                | **Yes**                    |
| Feature-test a controller                            | Usually facade mock instead |
| Integration-test against a real server               | No — use real daemon       |

`MockClient` shines when the code under test does **multi-step
OPC UA flows**: read → conditionally write → re-read. Facade
mocking gets verbose; `MockClient` reads cleanly.

## Basic usage

<!-- @code-block language="php" label="basic" -->
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

it('captures a reading', function () {
    $client = MockClient::create();
    $client->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

    $service = new TagReadingService($client);
    $result = $service->getCurrentSpeed();

    expect($result)->toBe(75.0);
});
```
<!-- @endcode-block -->

`$client` implements `OpcUaClientInterface` so anywhere your
production code accepts that interface, the mock plugs in.

## Programmable behaviour

<!-- @code-block language="php" label="responses" -->
```php
$client = MockClient::create();

// Reads
$client->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));
$client->onRead('ns=2;s=Temperature', fn() => DataValue::ofDouble(22.5));

// Writes — capture for assertion, optional success/fail
$client->onWrite('ns=2;s=Setpoint', function (mixed $value) use ($client) {
    // Return true for success
    return true;
});

// Browse — return a fixture set
$client->onBrowse('ns=2;s=Folder', fn() => [
    referenceFor('ns=2;s=Folder.Speed'),
    referenceFor('ns=2;s=Folder.Temperature'),
]);

// Method calls
$client->onCall('ns=2;s=Recipe', 'ns=2;s=Recipe.Load', fn(array $inputs) => /* CallResult */);
```
<!-- @endcode-block -->

`referenceFor()` is a test helper — see the [opcua-client testing
reference](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/mock-client.md).

## Simulating errors

<!-- @code-block language="php" label="error simulation" -->
```php
use PhpOpcua\Client\Exception\{ConnectionException, ServiceException};

$client->onRead('ns=2;s=Speed', function () {
    throw new ConnectionException('Network unreachable');
});

$client->onWrite('ns=2;s=Setpoint', function () {
    return false;     // returning false → ServiceException raised by the wrapper
});
```
<!-- @endcode-block -->

Useful for testing retry logic and error reporting.

## Subscriptions

`MockClient` mirrors the real `createSubscription` /
`createMonitoredItems` surface — it does **not** expose a
high-level callback-style `subscribe()` helper. To simulate a
publish notification, dispatch a `DataChangeReceived` directly on
the client's dispatcher (you can grab it via `getEventDispatcher()`
or use Laravel's `Event::dispatch(...)` in a test that wires the
mock through Laravel's container):

<!-- @code-block language="php" label="simulate notification" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;

$client = MockClient::create();

$dispatcher = $client->getEventDispatcher();
$dispatcher->dispatch(new DataChangeReceived(
    client:         $client,
    subscriptionId: 1,
    sequenceNumber: 1,
    clientHandle:   1,
    dataValue:      DataValue::ofDouble(75.0),
));
```
<!-- @endcode-block -->

The dispatched event reaches any listener registered via
`Event::listen(DataChangeReceived::class, ...)`.

## Recording calls

`MockClient` exposes `getCalls()`, `getCallsFor(string $method)`,
`callCount(string $method)`, and `resetCalls()` — see the
[`opcua-client` testing reference](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/mock-client.md)
for the exact signatures. There are no `getRecordedReads()` /
`getRecordedWrites()` helpers.

Assertion patterns:

<!-- @code-block language="php" label="assert recorded" -->
```php
it('reads speed before writing setpoint', function () {
    $client = MockClient::create();
    $client->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(70.0));
    $client->onWrite('ns=2;s=Setpoint', fn() => 0);  // 0 = Good

    (new RecipeService($client))->bumpSetpoint();

    expect($client->callCount('read'))->toBeGreaterThan(0);
    expect($client->getCallsFor('write')[0])->toBeArray();
});
```
<!-- @endcode-block -->

## Binding to the container

`OpcuaManager` has no `setMockConnection()` method. To make
`MockClient` reachable through the facade, override the manager
binding with a manager subclass (or with a Mockery-mocked manager)
that returns the mock client from `connection()`:

<!-- @code-block language="php" label="container binding" -->
```php
use PhpOpcua\LaravelOpcua\OpcuaManager;
use PhpOpcua\Client\OpcUaClientInterface;

beforeEach(function () {
    $this->mockClient = MockClient::create();

    $manager = Mockery::mock(OpcuaManager::class);
    $manager->shouldReceive('connection')->andReturn($this->mockClient);

    $this->app->instance(OpcuaManager::class, $manager);
});

it('still goes through the facade', function () {
    $this->mockClient->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

    // Facade calls __call() which forwards to connection()
    // — the mocked manager returns the MockClient.
    $dv = Opcua::connection()->read('ns=2;s=Speed');

    expect($dv->getValue())->toBe(75.0);
});
```
<!-- @endcode-block -->

For tests that exercise `Opcua::read(...)` directly through the
facade's `__call()` magic, prefer
[facade mocking](./mocking-the-facade.md) — `MockClient` is most
valuable when you can inject it into a service via constructor
injection.

## A reusable test trait

<!-- @code-block language="php" label="HasMockOpcua trait" -->
```php
trait HasMockOpcua
{
    protected MockClient $mockClient;

    protected function setUpMockOpcua(): void
    {
        $this->mockClient = MockClient::create();

        $manager = Mockery::mock(OpcuaManager::class);
        $manager->shouldReceive('connection')->andReturn($this->mockClient);

        $this->app->instance(OpcuaManager::class, $manager);
    }
}
```
<!-- @endcode-block -->

In a test:

<!-- @code-block language="php" label="using the trait" -->
```php
uses(HasMockOpcua::class)->in('Feature/Plc');

it('reads via the facade', function () {
    $this->setUpMockOpcua();

    $this->mockClient->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(42.0));

    expect(Opcua::connection()->read('ns=2;s=Speed')->getValue())->toBe(42.0);
});
```
<!-- @endcode-block -->

## Mock vs facade — when to pick which

| Test characteristic                    | Pick                       |
| -------------------------------------- | -------------------------- |
| 1-2 OPC UA calls, exact interaction known | Facade `shouldReceive`  |
| 3+ calls, complex flow                  | MockClient                |
| Need recorded-call assertions           | MockClient (cleaner)      |
| Testing a controller, not a service     | Facade `shouldReceive`    |
| Testing a service with constructor injection | MockClient            |

Mix freely. Most apps end up with a mix of both styles.

## Comparing values

Don't compare `DataValue` instances with `==` — timestamps
differ. Compare fields:

<!-- @code-block language="php" label="value comparison" -->
```php
expect($dv->getValue())->toBe(75.0);
expect($dv->statusCode)->toBe(0);
```
<!-- @endcode-block -->

Or use a custom expectation:

<!-- @code-block language="php" label="custom expectation" -->
```php
// tests/Pest.php
expect()->extend('toBeGoodReading', function (mixed $expectedValue) {
    expect($this->value)->statusCode->toBe(0);
    expect($this->value)->value->toBe($expectedValue);
});

// In a test
expect($dv)->toBeGoodReading(75.0);
```
<!-- @endcode-block -->

## Where to read next

- [Integration tests](./integration-tests.md) — when mocking
  isn't enough.
- [opcua-client testing](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/mock-client.md) —
  the upstream MockClient reference.
