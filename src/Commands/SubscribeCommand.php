<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Commands;

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionWorker;
use Illuminate\Console\Command;

class SubscribeCommand extends Command
{
    protected $signature = 'opcua:subscribe
        {--subscription=* : Run only specific subscriptions (by name). If omitted, all configured subscriptions are started}';

    protected $description = 'Start polling OPC UA subscriptions and dispatch Laravel jobs on data changes or events';

    public function handle(OpcuaManager $manager): int
    {
        if (!$manager->isSessionManagerRunning()) {
            $this->error('The OPC UA session manager is not running.');
            $this->error('Start it first with: php artisan opcua:session');
            return self::FAILURE;
        }

        $configSubscriptions = config('opcua.subscriptions', []);
        $registeredSubscriptions = $manager->getRegisteredSubscriptions();
        $allSubscriptions = array_merge($configSubscriptions, $registeredSubscriptions);

        if (empty($allSubscriptions)) {
            $this->warn('No subscriptions found (neither in config nor registered programmatically).');
            return self::FAILURE;
        }

        // Filter by name if --subscription is provided
        $filterNames = $this->option('subscription');
        if (!empty($filterNames)) {
            $filtered = [];
            foreach ($filterNames as $name) {
                if (!isset($allSubscriptions[$name])) {
                    $this->error("Subscription '{$name}' is not configured.");
                    return self::FAILURE;
                }
                $filtered[$name] = $allSubscriptions[$name];
            }
            $allSubscriptions = $filtered;
        }

        // Validate subscription configs
        foreach ($allSubscriptions as $name => $config) {
            $type = $config['type'] ?? 'data_change';
            if (!isset($config['job'])) {
                $this->error("Subscription '{$name}' is missing the 'job' key.");
                return self::FAILURE;
            }
            if ($type === 'data_change' && empty($config['nodes'])) {
                $this->error("Subscription '{$name}' (data_change) requires at least one entry in 'nodes'.");
                return self::FAILURE;
            }
            if ($type === 'event' && empty($config['node_id'])) {
                $this->error("Subscription '{$name}' (event) requires a 'node_id'.");
                return self::FAILURE;
            }
        }

        $this->info('Starting OPC UA subscription worker...');
        $this->table(
            ['Subscription', 'Type', 'Connection', 'Job'],
            collect($allSubscriptions)->map(fn ($c, $n) => [
                $n,
                $c['type'] ?? 'data_change',
                !empty($c['endpoint']) ? $c['endpoint'] . ' (ad-hoc)' : ($c['connection'] ?? config('opcua.default', 'default')),
                $c['job'],
            ])->toArray(),
        );

        $worker = new SubscriptionWorker(
            manager: $manager,
            subscriptions: $allSubscriptions,
            logger: function (string $message, string $level) {
                match ($level) {
                    'error' => $this->error($message),
                    'warning' => $this->warn($message),
                    default => $this->info($message),
                };
            },
        );

        $this->trap([SIGTERM, SIGINT], function () use ($worker) {
            $this->info('Received shutdown signal...');
            $worker->stop();
        });

        $worker->run();

        $this->info('Subscription worker stopped.');

        return self::SUCCESS;
    }
}
