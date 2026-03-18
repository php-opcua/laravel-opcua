<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionBuilder;

describe('SubscriptionBuilder', function () {

    describe('data_change subscriptions', function () {

        it('builds a minimal data_change config', function () {
            $builder = new SubscriptionBuilder('test-sub');
            $config = $builder
                ->nodes(['ns=2;i=1001'])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['type'])->toBe('data_change');
            expect($config['job'])->toBe('App\Jobs\MyJob');
            expect($config['nodes'])->toHaveCount(1);
            expect($config['nodes'][0])->toBe(['node_id' => 'ns=2;i=1001']);
            expect($config['queue'])->toBeNull();
            expect($config['publishing_interval'])->toBe(500.0);
        });

        it('accepts string shorthand for nodes', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->nodes(['ns=2;i=1001', 'ns=2;i=1002', 'i=85'])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['nodes'])->toHaveCount(3);
            expect($config['nodes'][0])->toBe(['node_id' => 'ns=2;i=1001']);
            expect($config['nodes'][1])->toBe(['node_id' => 'ns=2;i=1002']);
            expect($config['nodes'][2])->toBe(['node_id' => 'i=85']);
        });

        it('accepts detailed array for nodes', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->nodes([
                    ['node_id' => 'ns=2;i=1001', 'sampling_interval' => 100.0],
                    ['node_id' => 'ns=2;i=1002'],
                ])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['nodes'][0])->toBe(['node_id' => 'ns=2;i=1001', 'sampling_interval' => 100.0]);
            expect($config['nodes'][1])->toBe(['node_id' => 'ns=2;i=1002']);
        });

        it('accepts mixed string and array nodes', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->nodes([
                    'ns=2;i=1001',
                    ['node_id' => 'ns=2;i=1002', 'sampling_interval' => 50.0],
                ])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['nodes'][0])->toBe(['node_id' => 'ns=2;i=1001']);
            expect($config['nodes'][1])->toBe(['node_id' => 'ns=2;i=1002', 'sampling_interval' => 50.0]);
        });
    });

    describe('event subscriptions', function () {

        it('builds an event config with default select_fields', function () {
            $builder = new SubscriptionBuilder('alarms');
            $config = $builder
                ->events('i=2253')
                ->job('App\Jobs\HandleAlarm')
                ->toArray();

            expect($config['type'])->toBe('event');
            expect($config['node_id'])->toBe('i=2253');
            expect($config['job'])->toBe('App\Jobs\HandleAlarm');
            expect($config['select_fields'])->toBe([
                'EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity',
            ]);
        });

        it('accepts custom select_fields', function () {
            $builder = new SubscriptionBuilder('alarms');
            $config = $builder
                ->events('i=2253', ['EventId', 'Message', 'Severity'])
                ->job('App\Jobs\HandleAlarm')
                ->toArray();

            expect($config['select_fields'])->toBe(['EventId', 'Message', 'Severity']);
        });
    });

    describe('connection options', function () {

        it('sets a named connection', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->connection('plc')
                ->nodes(['ns=2;i=1001'])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['connection'])->toBe('plc');
            expect($config)->not->toHaveKey('endpoint');
        });

        it('sets an ad-hoc endpoint', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->endpoint('opc.tcp://10.0.0.50:4840', [
                    'username' => 'admin',
                    'password' => 'secret',
                ])
                ->nodes(['ns=2;i=1001'])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['endpoint'])->toBe('opc.tcp://10.0.0.50:4840');
            expect($config['endpoint_config'])->toBe([
                'username' => 'admin',
                'password' => 'secret',
            ]);
            expect($config)->not->toHaveKey('connection');
        });

        it('sets endpoint without config', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->endpoint('opc.tcp://10.0.0.50:4840')
                ->nodes(['ns=2;i=1001'])
                ->job('App\Jobs\MyJob')
                ->toArray();

            expect($config['endpoint'])->toBe('opc.tcp://10.0.0.50:4840');
            expect($config['endpoint_config'])->toBe([]);
        });
    });

    describe('optional settings', function () {

        it('sets the queue name', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->nodes(['ns=2;i=1001'])
                ->job('App\Jobs\MyJob')
                ->queue('opcua')
                ->toArray();

            expect($config['queue'])->toBe('opcua');
        });

        it('sets the publishing interval', function () {
            $builder = new SubscriptionBuilder('test');
            $config = $builder
                ->nodes(['ns=2;i=1001'])
                ->job('App\Jobs\MyJob')
                ->publishingInterval(1000.0)
                ->toArray();

            expect($config['publishing_interval'])->toBe(1000.0);
        });
    });

    describe('getName', function () {

        it('returns the subscription name', function () {
            $builder = new SubscriptionBuilder('my-subscription');

            expect($builder->getName())->toBe('my-subscription');
        });
    });
});
