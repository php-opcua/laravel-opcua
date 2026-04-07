<?php

declare(strict_types=1);

namespace PhpOpcua\LaravelOpcua\Commands;

use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Artisan command to start the OPC UA session manager daemon.
 */
class SessionCommand extends Command
{
    protected $signature = 'opcua:session
        {--timeout= : Session inactivity timeout in seconds}
        {--cleanup-interval= : Cleanup check interval in seconds}
        {--max-sessions= : Maximum concurrent sessions}
        {--socket-mode= : Socket file permissions (octal)}
        {--log-channel= : Laravel log channel name}
        {--cache-store= : Laravel cache store name}';

    protected $description = 'Start the OPC UA session manager daemon';

    /**
     * @return int
     */
    public function handle(): int
    {
        $config = config('opcua.session_manager');

        $socketPath = $config['socket_path'];
        $timeout = $this->option('timeout') ?? $config['timeout'];
        $cleanupInterval = $this->option('cleanup-interval') ?? $config['cleanup_interval'];
        $maxSessions = $this->option('max-sessions') ?? $config['max_sessions'];
        $socketMode = $this->option('socket-mode')
            ? intval($this->option('socket-mode'), 8)
            : $config['socket_mode'];

        $allowedCertDirs = $config['allowed_cert_dirs'];
        $authToken = $config['auth_token'];

        $logger = $this->resolveLogger($config);
        $cache = $this->resolveCache($config);

        $socketDir = dirname($socketPath);
        if (!is_dir($socketDir)) {
            mkdir($socketDir, 0755, true);
        }

        $logChannelName = $this->option('log-channel') ?? $config['log_channel'] ?? 'default';
        $cacheStoreName = $this->option('cache-store') ?? $config['cache_store'] ?? 'default';

        $this->info('Starting OPC UA Session Manager...');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Socket', $socketPath],
                ['Timeout', $timeout . 's'],
                ['Cleanup Interval', $cleanupInterval . 's'],
                ['Max Sessions', $maxSessions],
                ['Socket Mode', sprintf('0%o', $socketMode)],
                ['Auth Token', $authToken ? 'configured' : 'none'],
                ['Cert Dirs', $allowedCertDirs ? implode(', ', $allowedCertDirs) : 'any'],
                ['Log Channel', $logChannelName],
                ['Cache Store', $cacheStoreName],
            ],
        );

        $daemon = $this->createDaemon(
            socketPath: $socketPath,
            timeout: (int)$timeout,
            cleanupInterval: (int)$cleanupInterval,
            authToken: $authToken,
            maxSessions: (int)$maxSessions,
            socketMode: $socketMode,
            allowedCertDirs: $allowedCertDirs,
            logger: $logger,
            clientCache: $cache,
        );

        $daemon->run();

        return self::SUCCESS;
    }

    /**
     * Create the session manager daemon instance.
     *
     * @param string $socketPath
     * @param int $timeout
     * @param int $cleanupInterval
     * @param ?string $authToken
     * @param int $maxSessions
     * @param int $socketMode
     * @param ?array $allowedCertDirs
     * @param LoggerInterface $logger
     * @param ?CacheInterface $clientCache
     * @return SessionManagerDaemon
     */
    protected function createDaemon(
        string          $socketPath,
        int             $timeout,
        int             $cleanupInterval,
        ?string         $authToken,
        int             $maxSessions,
        int             $socketMode,
        ?array          $allowedCertDirs,
        LoggerInterface $logger,
        ?CacheInterface $clientCache,
    ): SessionManagerDaemon
    {
        return new SessionManagerDaemon(socketPath: $socketPath, timeout: $timeout, cleanupInterval: $cleanupInterval, authToken: $authToken, maxSessions: $maxSessions, socketMode: $socketMode, allowedCertDirs: $allowedCertDirs, logger: $logger, clientCache: $clientCache,);
    }

    /**
     * Resolve the PSR-3 logger for the daemon using a Laravel log channel.
     *
     * @param array $config
     * @return LoggerInterface
     */
    protected function resolveLogger(array $config): LoggerInterface
    {
        $channel = $this->option('log-channel') ?? $config['log_channel'] ?? null;

        return app('log')->channel($channel);
    }

    /**
     * Resolve the PSR-16 cache for the daemon using a Laravel cache store.
     *
     * @param array $config
     * @return CacheInterface
     */
    protected function resolveCache(array $config): CacheInterface
    {
        $store = $this->option('cache-store') ?? $config['cache_store'] ?? null;

        return app('cache')->store($store);
    }
}
