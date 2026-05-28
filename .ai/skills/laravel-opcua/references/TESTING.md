# Testing

Pest PHP throughout. 7 unit + 22 integration test files. Same `tests/Unit` vs `tests/Integration` split as the underlying `opcua-client`.

## Pest setup

`tests/Pest.php`:

```php
<?php

uses(\Tests\TestCase::class)->in('Unit', 'Integration');

function makeConfig(array $overrides = []): array { /* … */ }
```

A minimal `TestCase` (from `orchestra/testbench` for service-provider testing):

```php
namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use PhpOpcua\LaravelOpcua\OpcuaServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [OpcuaServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Opcua' => \PhpOpcua\LaravelOpcua\Facades\Opcua::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('opcua', [
            'default' => 'default',
            'session_manager' => ['enabled' => false],
            'connections' => [
                'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
            ],
        ]);
    }
}
```

## Unit testing the OpcuaManager

```php
use PhpOpcua\LaravelOpcua\OpcuaManager;

it('returns the default connection name from config', function () {
    $manager = new OpcuaManager([
        'default' => 'plc-1',
        'session_manager' => ['enabled' => false],
        'connections' => ['plc-1' => ['endpoint' => 'opc.tcp://x:4840']],
    ]);

    expect($manager->getDefaultConnection())->toBe('plc-1');
});
```

For tests that create real connections, swap the `ClientBuilder` to one that returns a fake (the manager calls `ClientBuilder::create()->...->connect()` internally; intercept that):

```php
$builder = Mockery::mock(\PhpOpcua\Client\ClientBuilderInterface::class);
$builder->shouldReceive('build')->andReturn($mockClient);
// Inject builder via constructor or test subclass
```

## MockClient for application tests

`PhpOpcua\Client\MockClient` ships in `opcua-client`. It implements `OpcUaClientInterface` and lets you register handlers per node/method.

```php
use PhpOpcua\Client\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\LaravelOpcua\Facades\Opcua;

it('renders the server state', function () {
    $mock = MockClient::create()
        ->onRead('i=2259', fn() => DataValue::ofInt32(0));

    Opcua::shouldReceive('read')->andReturnUsing(fn(...$args) => $mock->read(...$args));

    $response = $this->get('/server-state');
    $response->assertOk()->assertJson(['state' => 0, 'running' => true]);
});
```

For richer setups (multiple nodes, write asserts), use `MockClient`'s recording methods directly:

```php
$mock = MockClient::create()
    ->onRead('ns=2;s=Temp', fn() => DataValue::ofDouble(72.5))
    ->onRead('ns=2;s=Pressure', fn() => DataValue::ofDouble(101.3))
    ->onWrite('ns=2;s=Setpoint', fn($value) => StatusCode::Good);

$this->app->instance(\PhpOpcua\Client\OpcUaClientInterface::class, $mock);

// Run code under test...

expect($mock->callCount('write'))->toBe(1);
expect($mock->lastWriteValue('ns=2;s=Setpoint'))->toBe(42.5);
```

## Available DataValue factories

```php
DataValue::ofBoolean(true);
DataValue::ofInt16(42);
DataValue::ofUInt16(42);
DataValue::ofInt32(42);
DataValue::ofUInt32(42);
DataValue::ofDouble(3.14);
DataValue::ofFloat(3.14);
DataValue::ofString('hello');
DataValue::of(BuiltinType::Int64, 1234567890);
DataValue::bad(0x80000000);  // Bad statuscode, no value
```

Each accepts optional `statusCode`, `sourceTimestamp`, `serverTimestamp`.

## Facade shouldReceive

Standard Laravel mocking — `Opcua::shouldReceive(...)` swaps the manager:

```php
Opcua::shouldReceive('read')
    ->once()
    ->with('i=2259')
    ->andReturn(DataValue::ofInt32(0));

Opcua::shouldReceive('write')
    ->with('ns=2;s=Setpoint', 42.5, null)
    ->andReturn(StatusCode::Good);
```

For method chains (`connection('x')->read(...)`), partial-mock:

```php
$manager = Mockery::mock(OpcuaManager::class)->makePartial();
$client  = Mockery::mock(OpcUaClientInterface::class);

$client->shouldReceive('read')->andReturn(DataValue::ofInt32(0));
$manager->shouldReceive('connection')->with('plc-1')->andReturn($client);

$this->app->instance(OpcuaManager::class, $manager);
```

## Faking events

Laravel's `Event::fake()` captures all dispatched events — including OPC UA ones:

```php
use Illuminate\Support\Facades\Event;
use PhpOpcua\Client\Event\NodeValueWritten;

it('dispatches NodeValueWritten when writing', function () {
    Event::fake();

    Opcua::write('ns=2;s=Setpoint', 42.5);

    Event::assertDispatched(NodeValueWritten::class, function ($e) {
        return $e->nodeId === 'ns=2;s=Setpoint' && $e->value === 42.5;
    });
});
```

Pair with `MockClient` so the write is actually intercepted (otherwise it hits real TCP).

## Integration tests with the Docker test suite

Requires `php-opcua/uanetstandard-test-suite:v1.5.0` containers running on:

| Port | Server |
|---|---|
| 4840 | Plaintext (None) |
| 4843 | SignAndEncrypt (Basic256Sha256) |
| 4848 | ECC NIST |
| 4849 | ECC Brainpool |
| 4851 | Security Key Service |
| 4852 | HTTPS Binary |
| 24842 | open62541 historizing |

Start:
```bash
docker compose -f compose.test-suite.yml up -d
```

Tag tests with `--group=integration` to skip them locally without Docker:

```php
// tests/Integration/ConnectionTest.php
it('connects to plaintext server', function () { ... })->group('integration');
```

```bash
./vendor/bin/pest tests/Unit/                            # always
./vendor/bin/pest tests/Integration/ --group=integration # when Docker is up
```

CI runs both; local dev defaults to unit-only via `phpunit.xml`:

```xml
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <groups>
        <exclude><group>integration</group></exclude>
    </groups>
</phpunit>
```

## Sample integration test

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

it('reads the ServerStatus state attribute', function () {
    config(['opcua.connections.default.endpoint' => 'opc.tcp://localhost:4840']);

    $state = Opcua::read('i=2259')->getValue();

    expect($state)->toBeIn([0, 1, 2, 3, 4, 5, 6, 7]);
})->group('integration');
```

## Testing event listeners

```php
use App\Listeners\StoreSensorReading;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Types\DataValue;

it('stores readings on DataChangeReceived', function () {
    $event = new DataChangeReceived(
        subscriptionId: 1,
        clientHandle: 5,
        dataValue: DataValue::ofDouble(72.5),
    );

    (new StoreSensorReading)->handle($event);

    expect(SensorReading::where('client_handle', 5)->first()?->value)->toBe(72.5);
});
```

## Pest convenience expectations

```php
expect($dataValue->getValue())->toBe(72.5);
expect($dataValue->statusCode)->toBe(0);  // Good
expect($dataValue->sourceTimestamp)->toBeInstanceOf(\DateTimeImmutable::class);
```

For multi-result reads:
```php
$results = Opcua::readMulti()->node('a')->value()->node('b')->value()->execute();
expect($results)->toHaveCount(2);
expect($results[0]->getValue())->toBe(...);
```

## Workbench (orchestra/testbench) for service provider tests

The integration test suite exercises the service provider boot:

```php
use Orchestra\Testbench\TestCase;
use Tests\Support\AppTestCase;

it('registers OpcuaManager as singleton', function () {
    $first  = $this->app->make(OpcuaManager::class);
    $second = $this->app->make(OpcuaManager::class);
    expect($first)->toBe($second);
});

it('aliases opcua to OpcuaManager', function () {
    expect($this->app->make('opcua'))->toBeInstanceOf(OpcuaManager::class);
});
```

## CI matrix (recommended)

```yaml
strategy:
  matrix:
    php: ['8.2', '8.3', '8.4']
    laravel: ['11.*', '12.*', '13.*']
    exclude:
      - { php: '8.2', laravel: '13.*' }  # Laravel 13 requires PHP 8.3+
```

For integration: spin up `uanetstandard-test-suite` as a Docker service in the workflow and gate `--group=integration` behind it.

## Code coverage

Target ≥ 99.5% (matches the rest of the php-opcua ecosystem). The 7 unit + 22 integration files give wide coverage; gaps usually live in `OpcuaManager::configureBuilder()` branches (one branch per security policy / mode permutation). Don't write a test for every permutation — pick one RSA, one ECC, one None, and trust the underlying `ClientBuilder` test coverage in `opcua-client`.
