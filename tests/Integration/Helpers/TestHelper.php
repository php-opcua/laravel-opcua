<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers;

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

final class TestHelper
{
    // ── Endpoint URLs ──────────────────────────────────────────────────
    public const ENDPOINT_NO_SECURITY   = 'opc.tcp://localhost:4840/UA/TestServer';
    public const ENDPOINT_USERPASS      = 'opc.tcp://localhost:4841/UA/TestServer';
    public const ENDPOINT_CERTIFICATE   = 'opc.tcp://localhost:4842/UA/TestServer';
    public const ENDPOINT_ALL_SECURITY  = 'opc.tcp://localhost:4843/UA/TestServer';
    public const ENDPOINT_DISCOVERY     = 'opc.tcp://localhost:4844';
    public const ENDPOINT_AUTO_ACCEPT   = 'opc.tcp://localhost:4845/UA/TestServer';
    public const ENDPOINT_SIGN_ONLY     = 'opc.tcp://localhost:4846/UA/TestServer';
    public const ENDPOINT_LEGACY        = 'opc.tcp://localhost:4847/UA/TestServer';

    // ── Socket path for test daemon ────────────────────────────────────
    public const SOCKET_PATH = '/tmp/opcua-laravel-session-manager-test.sock';

    // ── Certificate paths ──────────────────────────────────────────────
    public static function getCertsDir(): string
    {
        $dir = getenv('OPCUA_CERTS_DIR') ?: __DIR__ . '/../../../../opcua-test-server-suite/certs';

        return realpath($dir) ?: $dir;
    }

    public static function getClientCertPath(): string
    {
        return self::getCertsDir() . '/client/cert.pem';
    }

    public static function getClientKeyPath(): string
    {
        return self::getCertsDir() . '/client/key.pem';
    }

    public static function getCaCertPath(): string
    {
        return self::getCertsDir() . '/ca/ca-cert.pem';
    }

    // ── Users ──────────────────────────────────────────────────────────
    public const USER_ADMIN    = ['username' => 'admin',    'password' => 'admin123'];
    public const USER_OPERATOR = ['username' => 'operator', 'password' => 'operator123'];
    public const USER_VIEWER   = ['username' => 'viewer',   'password' => 'viewer123'];
    public const USER_TEST     = ['username' => 'test',     'password' => 'test'];

    // ── Well-known NodeIds ─────────────────────────────────────────────
    public const NODE_ROOT            = [0, 84];
    public const NODE_OBJECTS          = [0, 85];
    public const NODE_SERVER           = [0, 2253];
    public const NODE_SERVER_STATUS    = [0, 2256];
    public const NODE_SERVER_STATE     = [0, 2259];

    // ── Daemon process management ──────────────────────────────────────
    private static $daemonProcess = null;
    private static int $daemonStartCount = 0;

    public static function startDaemon(): void
    {
        self::$daemonStartCount++;
        if (self::$daemonProcess !== null) {
            return;
        }

        if (file_exists(self::SOCKET_PATH)) {
            unlink(self::SOCKET_PATH);
        }
        if (file_exists(self::SOCKET_PATH . '.pid')) {
            unlink(self::SOCKET_PATH . '.pid');
        }

        // Use the session manager binary from vendor
        $binPath = __DIR__ . '/../../../vendor/gianfriaur/opcua-php-client-session-manager/bin/opcua-session-manager';
        if (!file_exists($binPath)) {
            // Fallback: sibling project
            $binPath = __DIR__ . '/../../../../opcua-php-client-session-manager/bin/opcua-session-manager';
        }

        $cmd = sprintf(
            'php %s --socket %s --timeout 60 --cleanup-interval 5',
            escapeshellarg($binPath),
            escapeshellarg(self::SOCKET_PATH),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$daemonProcess = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource(self::$daemonProcess)) {
            throw new \RuntimeException('Failed to start daemon');
        }

        $maxWait = 50;
        while ($maxWait > 0 && !file_exists(self::SOCKET_PATH)) {
            usleep(100_000);
            $maxWait--;
        }

        if (!file_exists(self::SOCKET_PATH)) {
            self::stopDaemon();
            throw new \RuntimeException('Daemon socket did not appear within 5 seconds');
        }

        $response = SocketConnection::send(self::SOCKET_PATH, ['command' => 'ping']);
        if (!($response['success'] ?? false)) {
            self::stopDaemon();
            throw new \RuntimeException('Daemon is not responding to ping');
        }
    }

    public static function stopDaemon(): void
    {
        if (self::$daemonProcess === null) {
            return;
        }

        $status = proc_get_status(self::$daemonProcess);
        if ($status['running']) {
            proc_terminate(self::$daemonProcess, 15);
            $maxWait = 30;
            while ($maxWait > 0) {
                $status = proc_get_status(self::$daemonProcess);
                if (!$status['running']) {
                    break;
                }
                usleep(100_000);
                $maxWait--;
            }

            $status = proc_get_status(self::$daemonProcess);
            if ($status['running']) {
                proc_terminate(self::$daemonProcess, 9);
            }
        }

        proc_close(self::$daemonProcess);
        self::$daemonProcess = null;

        if (file_exists(self::SOCKET_PATH)) {
            unlink(self::SOCKET_PATH);
        }
        if (file_exists(self::SOCKET_PATH . '.pid')) {
            unlink(self::SOCKET_PATH . '.pid');
        }
    }

    public static function isDaemonRunning(): bool
    {
        if (self::$daemonProcess === null) {
            return false;
        }

        $status = proc_get_status(self::$daemonProcess);

        return $status['running'];
    }

    // ── Config builders ────────────────────────────────────────────────

    /**
     * Build an OpcuaManager config for direct mode (no session manager).
     *
     * @param array $overrides
     * @return array
     */
    public static function makeDirectConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'default' => 'default',
            'session_manager' => [
                'enabled' => false,
                'socket_path' => self::SOCKET_PATH,
                'timeout' => 60,
                'cleanup_interval' => 5,
                'auth_token' => null,
                'max_sessions' => 100,
                'socket_mode' => 0600,
                'allowed_cert_dirs' => null,
            ],
            'connections' => [
                'default' => [
                    'endpoint' => self::ENDPOINT_NO_SECURITY,
                    'security_policy' => null,
                    'security_mode' => null,
                    'username' => null,
                    'password' => null,
                    'client_certificate' => null,
                    'client_key' => null,
                    'ca_certificate' => null,
                    'user_certificate' => null,
                    'user_key' => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * Build an OpcuaManager config for managed mode (with session manager).
     *
     * @param array $overrides
     * @return array
     */
    public static function makeManagedConfig(array $overrides = []): array
    {
        return array_replace_recursive(self::makeDirectConfig([
            'session_manager' => [
                'enabled' => true,
                'socket_path' => self::SOCKET_PATH,
            ],
        ]), $overrides);
    }

    /**
     * Create an OpcuaManager in direct mode.
     *
     * @param array $overrides
     * @return OpcuaManager
     */
    public static function createDirectManager(array $overrides = []): OpcuaManager
    {
        return new OpcuaManager(self::makeDirectConfig($overrides));
    }

    /**
     * Create an OpcuaManager in managed mode (daemon must be running).
     *
     * @param array $overrides
     * @return OpcuaManager
     */
    public static function createManagedManager(array $overrides = []): OpcuaManager
    {
        return new OpcuaManager(self::makeManagedConfig($overrides));
    }

    // ── Node browsing helpers ──────────────────────────────────────────

    /**
     * @param OpcUaClientInterface $client
     * @param array $path
     * @return NodeId
     */
    public static function browseToNode(OpcUaClientInterface $client, array $path): NodeId
    {
        $currentNodeId = NodeId::numeric(0, 85);

        foreach ($path as $name) {
            $refs = $client->browse($currentNodeId);
            $found = false;

            foreach ($refs as $ref) {
                $browseName = $ref->browseName->name;
                $displayName = (string) $ref->displayName;

                if ($browseName === $name || $displayName === $name) {
                    $currentNodeId = $ref->nodeId;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $availableNames = array_map(
                    fn(ReferenceDescription $r) => $r->browseName->name,
                    $refs,
                );
                throw new \RuntimeException(
                    "Could not find child node '{$name}' under node. "
                    . "Available: " . implode(', ', $availableNames)
                );
            }
        }

        return $currentNodeId;
    }

    /**
     * @param ReferenceDescription[] $refs
     * @param string $name
     * @return ?ReferenceDescription
     */
    public static function findRefByName(array $refs, string $name): ?ReferenceDescription
    {
        foreach ($refs as $ref) {
            if ($ref->browseName->name === $name || (string) $ref->displayName === $name) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @param ?string $connectionName
     * @param ?OpcuaManager $manager
     * @return void
     */
    public static function safeDisconnect(?string $connectionName, ?OpcuaManager $manager): void
    {
        if ($manager === null) {
            return;
        }
        try {
            $manager->disconnect($connectionName);
        } catch (\Throwable) {
        }
    }
}
