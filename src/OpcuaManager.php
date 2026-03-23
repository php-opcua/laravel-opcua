<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel;

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Manages OPC UA client connections within a Laravel application.
 *
 * @see OpcUaClientInterface
 */
class OpcuaManager
{
    /** @var array<string, OpcUaClientInterface> */
    protected array $connections = [];

    /**
     * @param array $config
     * @param ?LoggerInterface $defaultLogger
     * @param ?CacheInterface $defaultCache
     */
    public function __construct(
        protected array $config,
        protected ?LoggerInterface $defaultLogger = null,
        protected ?CacheInterface $defaultCache = null,
    ) {}

    /**
     * Get an OPC UA client connection by name.
     *
     * @param ?string $name
     * @return OpcUaClientInterface
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
     *
     * @return string
     */
    public function getDefaultConnection(): string
    {
        return $this->config['default'] ?? 'default';
    }

    /**
     * Create a new connection instance.
     *
     * @param string $name
     * @return OpcUaClientInterface
     *
     * @throws \InvalidArgumentException
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
     *
     * @return OpcUaClientInterface
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
     *
     * @param array $smConfig
     * @return bool
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
     *
     * @param OpcUaClientInterface $client
     * @param array $config
     * @return void
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

        // Timeout
        if (isset($config['timeout']) && $config['timeout'] !== null) {
            $client->setTimeout((float) $config['timeout']);
        }

        // Auto-retry
        if (isset($config['auto_retry']) && $config['auto_retry'] !== null) {
            $client->setAutoRetry((int) $config['auto_retry']);
        }

        // Batch size
        if (isset($config['batch_size']) && $config['batch_size'] !== null) {
            $client->setBatchSize((int) $config['batch_size']);
        }

        // Browse max depth
        if (isset($config['browse_max_depth']) && $config['browse_max_depth'] !== null) {
            $client->setDefaultBrowseMaxDepth((int) $config['browse_max_depth']);
        }

        // Logger (PSR-3): explicit config > Laravel default
        if (isset($config['logger']) && $config['logger'] instanceof LoggerInterface) {
            $client->setLogger($config['logger']);
        } elseif ($this->defaultLogger !== null) {
            $client->setLogger($this->defaultLogger);
        }

        // Cache (PSR-16): explicit config > Laravel default
        if (array_key_exists('cache', $config)) {
            if ($config['cache'] instanceof CacheInterface) {
                $client->setCache($config['cache']);
            } elseif ($config['cache'] === null) {
                $client->setCache(null);
            }
        } elseif ($this->defaultCache !== null) {
            $client->setCache($this->defaultCache);
        }
    }

    /**
     * Resolve a security policy name or URI to the full URI.
     *
     * @param string $policy
     * @return string
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
     *
     * @param string|int $mode
     * @return SecurityMode
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
     * @return OpcUaClientInterface
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
     *
     * @param ?string $name
     * @return OpcUaClientInterface
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
     *
     * @param ?string $name
     * @return void
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
     *
     * @return void
     */
    public function disconnectAll(): void
    {
        foreach (array_keys($this->connections) as $name) {
            $this->disconnect($name);
        }
    }

    /**
     * Check if the session manager daemon is currently running.
     *
     * @return bool
     */
    public function isSessionManagerRunning(): bool
    {
        $socketPath = $this->config['session_manager']['socket_path'] ?? null;

        return $socketPath !== null && file_exists($socketPath);
    }

    /**
     * Dynamically proxy methods to the default connection.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->$method(...$parameters);
    }
}
