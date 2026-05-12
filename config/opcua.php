<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default OPC UA connection to use when calling Opcua::read(), etc.
    |
    */

    'default' => env('OPCUA_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Session Manager
    |--------------------------------------------------------------------------
    |
    | Configuration for the session manager daemon. The daemon keeps OPC UA
    | connections alive across PHP requests, avoiding reconnection overhead.
    |
    | If the daemon is not running, the client will fall back to direct
    | connections (a new TCP connection per request).
    |
    */

    'session_manager' => [
        'enabled' => env('OPCUA_SESSION_MANAGER_ENABLED', true),

        // IPC endpoint URI. Accepts:
        //   - unix://<absolute/path.sock>
        //   - tcp://127.0.0.1:<port>   (loopback-only — any non-loopback host is refused)
        //   - scheme-less path         (interpreted as unix://<path>)
        //
        // Defaults to PhpOpcua\SessionManager\Ipc\TransportFactory::defaultEndpoint():
        //   - Linux/macOS: unix://<storage_path('app/opcua-session-manager.sock')>
        //   - Windows:     tcp://127.0.0.1:9990
        'socket_path' => env('OPCUA_SOCKET_PATH')
            ?? (PHP_OS_FAMILY === 'Windows'
                ? 'tcp://127.0.0.1:9990'
                : storage_path('app/opcua-session-manager.sock')),
        'timeout' => env('OPCUA_SESSION_TIMEOUT', 600),
        'cleanup_interval' => env('OPCUA_CLEANUP_INTERVAL', 30),
        'auth_token' => env('OPCUA_AUTH_TOKEN'),
        'max_sessions' => env('OPCUA_MAX_SESSIONS', 100),
        'socket_mode' => 0600,
        'allowed_cert_dirs' => null,

        // Daemon logging — uses a Laravel log channel.
        // Falls back to Laravel's default channel when not specified.
        'log_channel' => env('OPCUA_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),

        // Daemon client cache — uses a Laravel cache store.
        // Falls back to Laravel's default cache store when not specified.
        'cache_store' => env('OPCUA_CACHE_STORE', env('CACHE_STORE', 'file')),

        // Auto-publish: when enabled, the daemon automatically calls publish()
        // for sessions with active subscriptions. Notifications are delivered
        // via PSR-14 events (DataChangeReceived, EventNotificationReceived,
        // AlarmActivated, etc.). Register listeners in your EventServiceProvider.
        'auto_publish' => env('OPCUA_AUTO_PUBLISH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Define your OPC UA server connections here. Each connection can have
    | its own endpoint, security settings, and credentials.
    |
    */

    'connections' => [

        'default' => [
            'endpoint' => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),

            // Security (optional)
            'security_policy' => env('OPCUA_SECURITY_POLICY','None'), // None, Basic128Rsa15, Basic256, Basic256Sha256, Aes128Sha256RsaOaep, Aes256Sha256RsaPss, ECC_nistP256, ECC_nistP384, ECC_brainpoolP256r1, ECC_brainpoolP384r1
            'security_mode' => env('OPCUA_SECURITY_MODE','None'),     // None, Sign, SignAndEncrypt

            // User authentication (optional)
            'username' => env('OPCUA_USERNAME',null),
            'password' => env('OPCUA_PASSWORD',null),

            // Client certificate (optional — if omitted, a self-signed certificate is
            // auto-generated in memory when a security policy/mode is active)
            'client_certificate' => env('OPCUA_CLIENT_CERT',null),
            'client_key' => env('OPCUA_CLIENT_KEY',null),
            'ca_certificate' => env('OPCUA_CA_CERT',null),

            // User certificate (optional)
            'user_certificate' => env('OPCUA_USER_CERT',null),
            'user_key' => env('OPCUA_USER_KEY',null),

            // Client behaviour (optional)
            'timeout' => env('OPCUA_TIMEOUT',5.0),
            'auto_retry' => env('OPCUA_AUTO_RETRY',null),
            'batch_size' => env('OPCUA_BATCH_SIZE',null),
            'browse_max_depth' => env('OPCUA_BROWSE_MAX_DEPTH',10),

            // Trust store (optional, v4.0+)
            'trust_store_path' => env('OPCUA_TRUST_STORE_PATH',null),
            'trust_policy' => env('OPCUA_TRUST_POLICY',null),            // fingerprint, fingerprint+expiry, full
            'auto_accept' => env('OPCUA_AUTO_ACCEPT',false),
            'auto_accept_force' => env('OPCUA_AUTO_ACCEPT_FORCE',false),

            // Write type auto-detection (v4.0+ — auto-detects the OPC UA type on write)
            'auto_detect_write_type' => env('OPCUA_AUTO_DETECT_WRITE_TYPE',true),

            // Read metadata cache (v4.0+ — caches non-Value attribute reads)
            'read_metadata_cache' => env('OPCUA_READ_METADATA_CACHE',false),

            // Client-side logging (optional) — name of a Laravel log channel.
            // The package resolves it lazily at connection time, so you don't
            // need a Facade in this config file. When null, falls back to
            // Laravel's default logger (LOG_CHANNEL).
            // Example: set OPCUA_DEFAULT_LOG_CHANNEL=stderr to stream client
            // logs to the console when running an artisan command.
            'log_channel' => 'stdout',

            // Auto-connect (optional) — when true and auto_publish is enabled,
            // the daemon connects to this endpoint on startup and registers
            // the subscriptions defined below.
            // 'auto_connect' => false,

            // Subscriptions (optional) — auto-registered when auto_connect is true.
            // Each subscription can have monitored_items and/or event_monitored_items.
            // 'subscriptions' => [
            //     [
            //         'publishing_interval' => 500.0,
            //         'max_keep_alive_count' => 5,
            //         'monitored_items' => [
            //             ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
            //         ],
            //         'event_monitored_items' => [
            //             [
            //                 'node_id' => 'i=2253',
            //                 'client_handle' => 10,
            //                 'select_fields' => ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
            //             ],
            //         ],
            //     ],
            // ],
        ],

    ],

];
