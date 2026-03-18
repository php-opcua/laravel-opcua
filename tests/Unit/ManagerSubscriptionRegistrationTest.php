<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionBuilder;

function makeRegConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'default' => 'default',
        'session_manager' => ['enabled' => false, 'socket_path' => '/tmp/nonexistent.sock'],
        'connections' => ['default' => ['endpoint' => 'opc.tcp://localhost:4840']],
    ], $overrides);
}

describe('OpcuaManager subscription registration', function () {

    it('returns a SubscriptionBuilder from subscription()', function () {
        $manager = new OpcuaManager(makeRegConfig());

        $builder = $manager->subscription('my-sub');

        expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
        expect($builder->getName())->toBe('my-sub');
    });

    it('registers the subscription for later retrieval', function () {
        $manager = new OpcuaManager(makeRegConfig());

        $manager->subscription('temp-monitor')
            ->nodes(['ns=2;i=1001'])
            ->job('App\Jobs\TempChanged');

        $registered = $manager->getRegisteredSubscriptions();

        expect($registered)->toHaveKey('temp-monitor');
        expect($registered['temp-monitor']['job'])->toBe('App\Jobs\TempChanged');
        expect($registered['temp-monitor']['nodes'])->toHaveCount(1);
    });

    it('returns empty array when no subscriptions registered', function () {
        $manager = new OpcuaManager(makeRegConfig());

        expect($manager->getRegisteredSubscriptions())->toBeEmpty();
    });

    it('supports multiple registrations', function () {
        $manager = new OpcuaManager(makeRegConfig());

        $manager->subscription('sub-a')
            ->nodes(['ns=2;i=1001'])
            ->job('App\Jobs\A');

        $manager->subscription('sub-b')
            ->events('i=2253')
            ->job('App\Jobs\B');

        $registered = $manager->getRegisteredSubscriptions();

        expect($registered)->toHaveCount(2);
        expect($registered)->toHaveKey('sub-a');
        expect($registered)->toHaveKey('sub-b');
        expect($registered['sub-a']['type'])->toBe('data_change');
        expect($registered['sub-b']['type'])->toBe('event');
    });

    it('overwrites registration when same name is used', function () {
        $manager = new OpcuaManager(makeRegConfig());

        $manager->subscription('my-sub')
            ->nodes(['ns=2;i=1001'])
            ->job('App\Jobs\Old');

        $manager->subscription('my-sub')
            ->nodes(['ns=2;i=2002'])
            ->job('App\Jobs\New');

        $registered = $manager->getRegisteredSubscriptions();

        expect($registered)->toHaveCount(1);
        expect($registered['my-sub']['job'])->toBe('App\Jobs\New');
    });

    it('correctly converts ad-hoc endpoint registration to config', function () {
        $manager = new OpcuaManager(makeRegConfig());

        $manager->subscription('remote')
            ->endpoint('opc.tcp://10.0.0.50:4840', [
                'username' => 'admin',
                'password' => 'secret',
            ])
            ->nodes(['ns=3;i=100'])
            ->job('App\Jobs\Remote')
            ->queue('opcua')
            ->publishingInterval(1000.0);

        $config = $manager->getRegisteredSubscriptions()['remote'];

        expect($config['endpoint'])->toBe('opc.tcp://10.0.0.50:4840');
        expect($config['endpoint_config'])->toBe([
            'username' => 'admin',
            'password' => 'secret',
        ]);
        expect($config['nodes'][0]['node_id'])->toBe('ns=3;i=100');
        expect($config['job'])->toBe('App\Jobs\Remote');
        expect($config['queue'])->toBe('opcua');
        expect($config['publishing_interval'])->toBe(1000.0);
    });

    it('correctly converts named connection registration to config', function () {
        $manager = new OpcuaManager(makeRegConfig());

        $manager->subscription('plc-data')
            ->connection('plc')
            ->events('i=2253', ['EventId', 'Severity'])
            ->job('App\Jobs\PlcAlarm');

        $config = $manager->getRegisteredSubscriptions()['plc-data'];

        expect($config['connection'])->toBe('plc');
        expect($config['type'])->toBe('event');
        expect($config['node_id'])->toBe('i=2253');
        expect($config['select_fields'])->toBe(['EventId', 'Severity']);
        expect($config)->not->toHaveKey('endpoint');
    });
});
