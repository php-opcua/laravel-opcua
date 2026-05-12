---
eyebrow: 'Docs · Testing'
lede:    'Opcua::shouldReceive, Opcua::partialMock, Opcua::spy — the Mockery-based facade testing surface. The patterns that make controller and listener tests cheap.'

see_also:
  - { href: './pest-setup.md',           meta: '5 min' }
  - { href: './using-mock-client.md',    meta: '5 min' }
  - { href: '../using-the-client/facade-vs-injection.md', meta: '5 min' }

prev: { label: 'Pest setup',     href: './pest-setup.md' }
next: { label: 'Using MockClient', href: './using-mock-client.md' }
---

# Mocking the facade

The `Opcua` facade plays naturally with Mockery. The three primary
patterns are all **inherited from Laravel's base
`Illuminate\Support\Facades\Facade`** — they aren't package methods:

- `Opcua::shouldReceive(...)` — strict expectation
- `Opcua::partialMock()` — mock some methods, pass through others
- `Opcua::spy()` — observe calls without imposing expectations

There is **no `Opcua::fake()` / "recording fake"** method. Use
`spy()` if you want assertion-after-the-fact, or bind a manual mock
to the container.

## shouldReceive — strict expectation

For controllers / listeners that have a known interaction:

<!-- @code-block language="php" label="strict mock" -->
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;
use PhpOpcua\Client\Types\DataValue;

it('returns the speed', function () {
    Opcua::shouldReceive('read')
        ->once()
        ->with('ns=2;s=Speed')
        ->andReturn(DataValue::ofDouble(75.0));

    $response = $this->get('/tags/speed');

    $response->assertOk()->assertJson(['speed' => 75.0]);
});
```
<!-- @endcode-block -->

The test fails if `read()` is called zero times, more than once,
or with a different argument. Strict.

## Multiple calls

<!-- @code-block language="php" label="multiple expectations" -->
```php
Opcua::shouldReceive('read')
    ->with('ns=2;s=Speed')
    ->andReturn(DataValue::ofDouble(75.0));

Opcua::shouldReceive('read')
    ->with('ns=2;s=Temperature')
    ->andReturn(DataValue::ofDouble(22.5));
```
<!-- @endcode-block -->

Order doesn't matter unless you add `->ordered()`. For typical
tests, leave order unconstrained.

## Returning a sequence

<!-- @code-block language="php" label="sequenced return" -->
```php
Opcua::shouldReceive('read')
    ->with('ns=2;s=Speed')
    ->andReturn(
        DataValue::ofDouble(70.0),
        DataValue::ofDouble(72.0),
        DataValue::ofDouble(75.0),
    );
```
<!-- @endcode-block -->

The first call returns 70.0, the second 72.0, the third 75.0,
the fourth (and beyond) 75.0.

## Throwing

<!-- @code-block language="php" label="exception expectation" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;

Opcua::shouldReceive('read')
    ->with('ns=2;s=Speed')
    ->andThrow(new ConnectionException('PLC unreachable'));
```
<!-- @endcode-block -->

Useful for testing error paths.

## connection() — chained mocks

<!-- @code-block language="php" label="chained" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

it('reads from the historian', function () {
    $historian = Mockery::mock(OpcUaClientInterface::class);
    $historian->shouldReceive('read')
        ->with('ns=4;s=DailyAvg')
        ->andReturn(DataValue::ofDouble(64.2));

    Opcua::shouldReceive('connection')
        ->with('historian')
        ->andReturn($historian);

    $service = new HistorianService();
    expect($service->getDailyAverage())->toBe(64.2);
});
```
<!-- @endcode-block -->

Chained calls — `Opcua::connection('historian')->read(...)` — need
two mocks: the facade returns a mocked client, the client returns
the value.

## partialMock — mock some, pass through

For tests where only **one** method needs mocking:

<!-- @code-block language="php" label="partial" -->
```php
Opcua::partialMock();

Opcua::shouldReceive('write')
    ->once()
    ->with('ns=2;s=Setpoint', 75.0)
    ->andReturn(true);

// Other Opcua::* calls go to the real implementation
$dv = Opcua::read('i=2256');    // real read — but daemon is disabled
```
<!-- @endcode-block -->

Common where the test asserts `write()` happens but doesn't want
to mock the test's setup reads.

## spy — observe without expecting

<!-- @code-block language="php" label="spy" -->
```php
Opcua::spy();

// Code under test runs
Opcua::write('ns=2;s=Setpoint', 75.0);

// Assert after the fact
Opcua::shouldHaveReceived('write')
    ->with('ns=2;s=Setpoint', 75.0)
    ->once();
```
<!-- @endcode-block -->

Useful when you want assertion-after-the-fact rather than
expectation-before. Reads cleaner in some test shapes.

## Closures for argument matching

For complex arguments, a closure matcher:

<!-- @code-block language="php" label="closure matcher" -->
```php
Opcua::shouldReceive('write')
    ->withArgs(function (string $node, mixed $value) {
        return str_starts_with($node, 'ns=2;') && is_float($value);
    })
    ->andReturn(true);
```
<!-- @endcode-block -->

Captures any write to a `ns=2` node with a float value.

## Asserting no calls

For routes that should not touch OPC UA:

<!-- @code-block language="php" label="no calls" -->
```php
Opcua::shouldReceive('read')->never();
Opcua::shouldReceive('write')->never();

$response = $this->get('/health');  // pure liveness route

$response->assertOk();
```
<!-- @endcode-block -->

## Listener tests

For a listener that depends on `DataChangeReceived`:

<!-- @code-block language="php" label="listener test" -->
```php
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\OpcUaClientInterface;

it('stores speed readings', function () {
    $client = Mockery::mock(OpcUaClientInterface::class);

    $event = new DataChangeReceived(
        client:         $client,
        subscriptionId: 1,
        sequenceNumber: 1,
        clientHandle:   1,
        dataValue:      DataValue::ofDouble(75.0),
    );

    (new StoreReadings())->handle($event);

    expect(PlcReading::first()->value)->toBe(75.0);
});
```
<!-- @endcode-block -->

The listener takes the event as input; no mocking of `Opcua`
needed because the listener doesn't call back into OPC UA.

## Job tests

Same shape:

<!-- @code-block language="php" label="job test" -->
```php
it('reads the speed', function () {
    Opcua::shouldReceive('read')
        ->with('ns=2;s=Speed')
        ->andReturn(DataValue::ofDouble(75.0));

    (new SamplePlc('ns=2;s=Speed'))->handle(app(OpcuaManager::class));

    expect(PlcReading::first()->value)->toBe(75.0);
});
```
<!-- @endcode-block -->

For jobs that use the manager directly (via `handle($manager)`),
binding a mock to the container works too:

<!-- @code-block language="php" label="container-bound mock" -->
```php
$mock = Mockery::mock(OpcuaManager::class);
$mock->shouldReceive('read')->andReturn(DataValue::ofDouble(75.0));
$this->app->instance(OpcuaManager::class, $mock);

(new SamplePlc('ns=2;s=Speed'))->handle($mock);
```
<!-- @endcode-block -->

## Combining with Bus::fake() / Queue::fake()

<!-- @code-block language="php" label="bus + facade" -->
```php
use Illuminate\Support\Facades\{Bus, Queue};

beforeEach(function () {
    Bus::fake();
    Queue::fake();
});

it('queues a sample job', function () {
    Opcua::shouldReceive('read')
        ->andReturn(DataValue::ofDouble(75.0));

    $this->post('/sample', ['node' => 'ns=2;s=Speed']);

    Bus::assertDispatched(SamplePlc::class);
});
```
<!-- @endcode-block -->

The fake captures the dispatch without running it.

## Mockery cleanup

Pest automatically tears down Mockery containers between tests.
You don't need `Mockery::close()` explicitly.

If you see `Mockery::close()` errors in CI but not locally,
ensure `--strict-coverage` and a clean `vendor/`.

## When facade mocking is wrong

Two cases where facade mocking is the wrong tool:

1. **Testing the facade itself.** Don't — it's covered by the
   package's tests.
2. **Testing complex multi-call flows.** A test with 8
   `shouldReceive` calls is signalling that the code under test
   has too many touchpoints. Refactor the production code first.

Prefer `MockClient` for these cases — it captures interactions
faithfully without manually stubbing each one. See
[Using MockClient](./using-mock-client.md).

## Where to read next

- [Using MockClient](./using-mock-client.md) — the recommended
  alternative for complex flows.
- [Integration tests](./integration-tests.md) — when mocking
  isn't enough.
