<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel;

use Gianfriaur\OpcuaLaravel\Subscriptions\SubscriptionBuilder;
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;

class OpcuaManager
{
    /** @var array<string, OpcUaClientInterface> */
    protected array $connections = [];

    /** @var array<string, SubscriptionBuilder> */
    protected array $registeredSubscriptions = [];

    public function __construct(
        protected array $config,
    ) {}

    /**
     * Get an OPC UA client connection by name.
     */
    public function connection(?string $name = null): OpcUaClientInterface
    {
        $name ??= $this->getDefaultConnection();

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->makeConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return $this->config['default'] ?? 'default';
    }

    /**
     * Create a new connection instance.
     */
    protected function makeConnection(string $name): OpcUaClientInterface
    {
        $connectionConfig = $this->config['connections'][$name] ?? null;

        if ($connectionConfig === null) {
            throw new \InvalidArgumentException("OPC UA connection [{$name}] is not configured.");
        }

        $client = $this->createClient();
        $this->configureClient($client, $connectionConfig);

        return $client;
    }

    /**
     * Create the appropriate client based on session manager availability.
     */
    protected function createClient(): OpcUaClientInterface
    {
        $smConfig = $this->config['session_manager'] ?? [];

        if ($this->shouldUseSessionManager($smConfig)) {
            return new ManagedClient(
                socketPath: $smConfig['socket_path'],
                timeout: 30.0,
                authToken: $smConfig['auth_token'] ?? null,
            );
        }

        return new Client();
    }

    /**
     * Determine if the session manager daemon is available and should be used.
     */
    protected function shouldUseSessionManager(array $smConfig): bool
    {
        if (!($smConfig['enabled'] ?? true)) {
            return false;
        }

        $socketPath = $smConfig['socket_path'] ?? null;

        if ($socketPath === null) {
            return false;
        }

        return file_exists($socketPath);
    }

    /**
     * Apply connection configuration to a client.
     */
    protected function configureClient(OpcUaClientInterface $client, array $config): void
    {
        // Security policy
        if (!empty($config['security_policy'])) {
            $policy = SecurityPolicy::from(
                $this->resolveSecurityPolicyUri($config['security_policy'])
            );
            $client->setSecurityPolicy($policy);
        }

        // Security mode
        if (!empty($config['security_mode'])) {
            $mode = $this->resolveSecurityMode($config['security_mode']);
            $client->setSecurityMode($mode);
        }

        // User credentials
        if (!empty($config['username'])) {
            $client->setUserCredentials($config['username'], $config['password'] ?? '');
        }

        // Client certificate
        if (!empty($config['client_certificate']) && !empty($config['client_key'])) {
            $client->setClientCertificate(
                $config['client_certificate'],
                $config['client_key'],
                $config['ca_certificate'] ?? null,
            );
        }

        // User certificate
        if (!empty($config['user_certificate']) && !empty($config['user_key'])) {
            $client->setUserCertificate(
                $config['user_certificate'],
                $config['user_key'],
            );
        }
    }

    /**
     * Resolve a security policy name or URI to the full URI.
     */
    protected function resolveSecurityPolicyUri(string $policy): string
    {
        $map = [
            'None' => SecurityPolicy::None->value,
            'Basic128Rsa15' => SecurityPolicy::Basic128Rsa15->value,
            'Basic256' => SecurityPolicy::Basic256->value,
            'Basic256Sha256' => SecurityPolicy::Basic256Sha256->value,
            'Aes128Sha256RsaOaep' => SecurityPolicy::Aes128Sha256RsaOaep->value,
            'Aes256Sha256RsaPss' => SecurityPolicy::Aes256Sha256RsaPss->value,
        ];

        return $map[$policy] ?? $policy;
    }

    /**
     * Resolve a security mode name or int to a SecurityMode enum.
     */
    protected function resolveSecurityMode(string|int $mode): SecurityMode
    {
        if (is_int($mode)) {
            return SecurityMode::from($mode);
        }

        return match ($mode) {
            'None' => SecurityMode::None,
            'Sign' => SecurityMode::Sign,
            'SignAndEncrypt' => SecurityMode::SignAndEncrypt,
            default => SecurityMode::from((int) $mode),
        };
    }

    /**
     * Create a client for an arbitrary endpoint not defined in config.
     *
     * The client is created, configured, connected, and tracked internally
     * so it can be retrieved later by name or cleaned up with disconnectAll().
     *
     * @param string $endpointUrl  The OPC UA endpoint URL (e.g. opc.tcp://host:4840)
     * @param array  $config       Optional connection config (same keys as a connection entry)
     * @param string|null $as      Optional name to store the connection under for later retrieval
     */
    public function connectTo(string $endpointUrl, array $config = [], ?string $as = null): OpcUaClientInterface
    {
        $client = $this->createClient();
        $this->configureClient($client, $config);
        $client->connect($endpointUrl);

        $name = $as ?? 'ad-hoc:' . $endpointUrl;
        $this->connections[$name] = $client;

        return $client;
    }

    /**
     * Connect a named connection to its endpoint.
     */
    public function connect(?string $name = null): OpcUaClientInterface
    {
        $name ??= $this->getDefaultConnection();
        $client = $this->connection($name);
        $endpoint = $this->config['connections'][$name]['endpoint'];

        $client->connect($endpoint);

        return $client;
    }

    /**
     * Disconnect a named connection.
     */
    public function disconnect(?string $name = null): void
    {
        $name ??= $this->getDefaultConnection();

        if (isset($this->connections[$name])) {
            $this->connections[$name]->disconnect();
            unset($this->connections[$name]);
        }
    }

    /**
     * Disconnect all connections.
     */
    public function disconnectAll(): void
    {
        foreach (array_keys($this->connections) as $name) {
            $this->disconnect($name);
        }
    }

    /**
     * Check if the session manager daemon is currently running.
     */
    public function isSessionManagerRunning(): bool
    {
        $socketPath = $this->config['session_manager']['socket_path'] ?? null;

        return $socketPath !== null && file_exists($socketPath);
    }

    /**
     * @param string $name
     * @return SubscriptionBuilder
     */
    public function subscription(string $name): SubscriptionBuilder
    {
        $builder = new SubscriptionBuilder($name);
        $this->registeredSubscriptions[$name] = $builder;

        return $builder;
    }

    /**
     * @return array<string, array>
     */
    public function getRegisteredSubscriptions(): array
    {
        $result = [];
        foreach ($this->registeredSubscriptions as $name => $builder) {
            $result[$name] = $builder->toArray();
        }

        return $result;
    }

    /**
     * Dynamically proxy methods to the default connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->$method(...$parameters);
    }
}
