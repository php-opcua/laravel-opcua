<?php

declare(strict_types=1);

namespace PhpOpcua\LaravelOpcua;

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ClientBuilderInterface;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\SessionManager\Client\ManagedClient;
use Psr\EventDispatcher\EventDispatcherInterface;
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
     * @param ?EventDispatcherInterface $defaultEventDispatcher
     */
    public function __construct(
        protected array $config,
        protected ?LoggerInterface $defaultLogger = null,
        protected ?CacheInterface $defaultCache = null,
        protected ?EventDispatcherInterface $defaultEventDispatcher = null,
    ) {}

    /**
     * Get an OPC UA client connection by name.
     *
     * For managed mode, returns a configured but not yet connected ManagedClient.
     * For direct mode, returns a connected Client (auto-connects to the configured endpoint).
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
     * For managed mode, creates and configures a ManagedClient (not connected).
     * For direct mode, creates a ClientBuilder, configures it, and connects
     * to the configured endpoint, returning the connected Client.
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

        $smConfig = $this->config['session_manager'] ?? [];

        if ($this->shouldUseSessionManager($smConfig)) {
            $client = $this->createManagedClient($smConfig);
            $this->configureManagedClient($client, $connectionConfig);
            return $client;
        }

        $builder = $this->createBuilder();
        $this->configureBuilder($builder, $connectionConfig);
        return $builder->connect($connectionConfig['endpoint']);
    }

    /**
     * Create a ManagedClient instance for daemon-proxied connections.
     *
     * @param array $smConfig
     * @return ManagedClient
     */
    protected function createManagedClient(array $smConfig): ManagedClient
    {
        return new ManagedClient(
            socketPath: $smConfig['socket_path'],
            timeout: 30.0,
            authToken: $smConfig['auth_token'] ?? null,
        );
    }

    /**
     * Create a ClientBuilder instance for direct connections.
     *
     * @return ClientBuilderInterface
     */
    protected function createBuilder(): ClientBuilderInterface
    {
        return ClientBuilder::create();
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
     * Apply connection configuration to a ClientBuilder for direct mode.
     *
     * @param ClientBuilderInterface $builder
     * @param array $config
     * @return void
     */
    protected function configureBuilder(ClientBuilderInterface $builder, array $config): void
    {
        if (!empty($config['security_policy'])) {
            $policy = SecurityPolicy::from(
                $this->resolveSecurityPolicyUri($config['security_policy'])
            );
            $builder->setSecurityPolicy($policy);
        }

        if (!empty($config['security_mode'])) {
            $mode = $this->resolveSecurityMode($config['security_mode']);
            $builder->setSecurityMode($mode);
        }

        if (!empty($config['username'])) {
            $builder->setUserCredentials($config['username'], $config['password'] ?? '');
        }

        if (!empty($config['client_certificate']) && !empty($config['client_key'])) {
            $builder->setClientCertificate(
                $config['client_certificate'],
                $config['client_key'],
                $config['ca_certificate'] ?? null,
            );
        }

        if (!empty($config['user_certificate']) && !empty($config['user_key'])) {
            $builder->setUserCertificate(
                $config['user_certificate'],
                $config['user_key'],
            );
        }

        if (isset($config['timeout']) && $config['timeout'] !== null) {
            $builder->setTimeout((float) $config['timeout']);
        }

        if (isset($config['auto_retry']) && $config['auto_retry'] !== null) {
            $builder->setAutoRetry((int) $config['auto_retry']);
        }

        if (isset($config['batch_size']) && $config['batch_size'] !== null) {
            $builder->setBatchSize((int) $config['batch_size']);
        }

        if (isset($config['browse_max_depth']) && $config['browse_max_depth'] !== null) {
            $builder->setDefaultBrowseMaxDepth((int) $config['browse_max_depth']);
        }

        if (isset($config['logger']) && $config['logger'] instanceof LoggerInterface) {
            $builder->setLogger($config['logger']);
        } elseif ($this->defaultLogger !== null) {
            $builder->setLogger($this->defaultLogger);
        }

        if (array_key_exists('cache', $config)) {
            if ($config['cache'] instanceof CacheInterface) {
                $builder->setCache($config['cache']);
            } elseif ($config['cache'] === null) {
                $builder->setCache(null);
            }
        } elseif ($this->defaultCache !== null) {
            $builder->setCache($this->defaultCache);
        }

        if (isset($config['event_dispatcher']) && $config['event_dispatcher'] instanceof EventDispatcherInterface) {
            $builder->setEventDispatcher($config['event_dispatcher']);
        } elseif ($this->defaultEventDispatcher !== null) {
            $builder->setEventDispatcher($this->defaultEventDispatcher);
        }

        if (!empty($config['trust_store_path'])) {
            $builder->setTrustStore(new FileTrustStore($config['trust_store_path']));
        }

        if (isset($config['trust_policy']) && $config['trust_policy'] !== null) {
            $builder->setTrustPolicy($this->resolveTrustPolicy($config['trust_policy']));
        }

        if (!empty($config['auto_accept'])) {
            $builder->autoAccept(true, $config['auto_accept_force'] ?? false);
        }

        if (isset($config['auto_detect_write_type']) && $config['auto_detect_write_type'] !== null) {
            $builder->setAutoDetectWriteType((bool) $config['auto_detect_write_type']);
        }

        if (isset($config['read_metadata_cache']) && $config['read_metadata_cache'] !== null) {
            $builder->setReadMetadataCache((bool) $config['read_metadata_cache']);
        }
    }

    /**
     * Apply connection configuration to a ManagedClient for managed mode.
     *
     * @param ManagedClient $client
     * @param array $config
     * @return void
     */
    protected function configureManagedClient(ManagedClient $client, array $config): void
    {
        if (!empty($config['security_policy'])) {
            $policy = SecurityPolicy::from(
                $this->resolveSecurityPolicyUri($config['security_policy'])
            );
            $client->setSecurityPolicy($policy);
        }

        if (!empty($config['security_mode'])) {
            $mode = $this->resolveSecurityMode($config['security_mode']);
            $client->setSecurityMode($mode);
        }

        if (!empty($config['username'])) {
            $client->setUserCredentials($config['username'], $config['password'] ?? '');
        }

        if (!empty($config['client_certificate']) && !empty($config['client_key'])) {
            $client->setClientCertificate(
                $config['client_certificate'],
                $config['client_key'],
                $config['ca_certificate'] ?? null,
            );
        }

        if (!empty($config['user_certificate']) && !empty($config['user_key'])) {
            $client->setUserCertificate(
                $config['user_certificate'],
                $config['user_key'],
            );
        }

        if (isset($config['timeout']) && $config['timeout'] !== null) {
            $client->setTimeout((float) $config['timeout']);
        }

        if (isset($config['auto_retry']) && $config['auto_retry'] !== null) {
            $client->setAutoRetry((int) $config['auto_retry']);
        }

        if (isset($config['batch_size']) && $config['batch_size'] !== null) {
            $client->setBatchSize((int) $config['batch_size']);
        }

        if (isset($config['browse_max_depth']) && $config['browse_max_depth'] !== null) {
            $client->setDefaultBrowseMaxDepth((int) $config['browse_max_depth']);
        }

        if (isset($config['logger']) && $config['logger'] instanceof LoggerInterface) {
            $client->setLogger($config['logger']);
        } elseif ($this->defaultLogger !== null) {
            $client->setLogger($this->defaultLogger);
        }

        if (array_key_exists('cache', $config)) {
            if ($config['cache'] instanceof CacheInterface) {
                $client->setCache($config['cache']);
            } elseif ($config['cache'] === null) {
                $client->setCache(null);
            }
        } elseif ($this->defaultCache !== null) {
            $client->setCache($this->defaultCache);
        }

        if (isset($config['event_dispatcher']) && $config['event_dispatcher'] instanceof EventDispatcherInterface) {
            $client->setEventDispatcher($config['event_dispatcher']);
        } elseif ($this->defaultEventDispatcher !== null) {
            $client->setEventDispatcher($this->defaultEventDispatcher);
        }

        if (!empty($config['trust_store_path'])) {
            $client->setTrustStorePath($config['trust_store_path']);
        }

        if (isset($config['trust_policy']) && $config['trust_policy'] !== null) {
            $client->setTrustPolicy($this->resolveTrustPolicy($config['trust_policy']));
        }

        if (!empty($config['auto_accept'])) {
            $client->autoAccept(true, $config['auto_accept_force'] ?? false);
        }

        if (isset($config['auto_detect_write_type']) && $config['auto_detect_write_type'] !== null) {
            $client->setAutoDetectWriteType((bool) $config['auto_detect_write_type']);
        }

        if (isset($config['read_metadata_cache']) && $config['read_metadata_cache'] !== null) {
            $client->setReadMetadataCache((bool) $config['read_metadata_cache']);
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
     * Resolve a trust policy name to a TrustPolicy enum.
     *
     * @param string $policy
     * @return TrustPolicy
     */
    protected function resolveTrustPolicy(string $policy): TrustPolicy
    {
        return match ($policy) {
            'fingerprint' => TrustPolicy::Fingerprint,
            'fingerprint+expiry' => TrustPolicy::FingerprintAndExpiry,
            'full' => TrustPolicy::Full,
            default => TrustPolicy::from($policy),
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
        $smConfig = $this->config['session_manager'] ?? [];
        $name = $as ?? 'ad-hoc:' . $endpointUrl;

        if ($this->shouldUseSessionManager($smConfig)) {
            $client = $this->createManagedClient($smConfig);
            $this->configureManagedClient($client, $config);
            $client->connect($endpointUrl);
            $this->connections[$name] = $client;
            return $client;
        }

        $builder = $this->createBuilder();
        $this->configureBuilder($builder, $config);
        $client = $builder->connect($endpointUrl);
        $this->connections[$name] = $client;
        return $client;
    }

    /**
     * Connect a named connection to its endpoint.
     *
     * For managed mode, explicitly connects the ManagedClient to the configured endpoint.
     * For direct mode, equivalent to connection() since the Client is already connected.
     *
     * @param ?string $name
     * @return OpcUaClientInterface
     */
    public function connect(?string $name = null): OpcUaClientInterface
    {
        $name ??= $this->getDefaultConnection();
        $client = $this->connection($name);

        if ($client instanceof ManagedClient && !$client->isConnected()) {
            $endpoint = $this->config['connections'][$name]['endpoint'];
            $client->connect($endpoint);
        }

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
