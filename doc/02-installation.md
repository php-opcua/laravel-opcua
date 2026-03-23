# Installation & Configuration

## Installation

```bash
composer require gianfriaur/opcua-laravel-client
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=opcua-config
```

This creates `config/opcua.php` in your application.

## Basic Setup

Add your OPC UA server endpoint to `.env`:

```dotenv
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
```

That's all you need to get started.

## Configuration File

The config file has three sections: default connection, session manager, and connections.

### Default Connection

```php
'default' => env('OPCUA_CONNECTION', 'default'),
```

Set `OPCUA_CONNECTION` to switch the default connection name.

### Session Manager

```php
'session_manager' => [
    'enabled'          => env('OPCUA_SESSION_MANAGER_ENABLED', true),
    'socket_path'      => env('OPCUA_SOCKET_PATH', storage_path('app/opcua-session-manager.sock')),
    'timeout'          => env('OPCUA_SESSION_TIMEOUT', 600),
    'cleanup_interval' => env('OPCUA_CLEANUP_INTERVAL', 30),
    'auth_token'       => env('OPCUA_AUTH_TOKEN'),
    'max_sessions'     => env('OPCUA_MAX_SESSIONS', 100),
    'socket_mode'      => 0600,
    'allowed_cert_dirs' => null,
    'log_channel'      => env('OPCUA_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),
    'cache_store'      => env('OPCUA_CACHE_STORE', env('CACHE_STORE', 'file')),
],
```

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `enabled` | `OPCUA_SESSION_MANAGER_ENABLED` | `true` | Enable daemon auto-detection |
| `socket_path` | `OPCUA_SOCKET_PATH` | `storage/app/opcua-session-manager.sock` | Unix socket path |
| `timeout` | `OPCUA_SESSION_TIMEOUT` | `600` | Session inactivity timeout (seconds) |
| `cleanup_interval` | `OPCUA_CLEANUP_INTERVAL` | `30` | Expired session check interval |
| `auth_token` | `OPCUA_AUTH_TOKEN` | `null` | Shared secret for daemon IPC |
| `max_sessions` | `OPCUA_MAX_SESSIONS` | `100` | Maximum concurrent sessions |
| `socket_mode` | — | `0600` | Socket file permissions |
| `allowed_cert_dirs` | — | `null` | Certificate directory whitelist |
| `log_channel` | `OPCUA_LOG_CHANNEL` | Laravel default | Log channel for the daemon |
| `cache_store` | `OPCUA_CACHE_STORE` | Laravel default | Cache store for the daemon |

### Connections

Each connection can have its own endpoint, security settings, and credentials:

```php
'connections' => [

    'default' => [
        'endpoint'           => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),
        'security_policy'    => env('OPCUA_SECURITY_POLICY', 'None'),
        'security_mode'      => env('OPCUA_SECURITY_MODE', 'None'),
        'username'           => env('OPCUA_USERNAME'),
        'password'           => env('OPCUA_PASSWORD'),
        'client_certificate' => env('OPCUA_CLIENT_CERT'),
        'client_key'         => env('OPCUA_CLIENT_KEY'),
        'ca_certificate'     => env('OPCUA_CA_CERT'),
        'user_certificate'   => env('OPCUA_USER_CERT'),
        'user_key'           => env('OPCUA_USER_KEY'),
        'timeout'            => env('OPCUA_TIMEOUT', 5.0),
        'auto_retry'         => env('OPCUA_AUTO_RETRY'),
        'batch_size'         => env('OPCUA_BATCH_SIZE'),
        'browse_max_depth'   => env('OPCUA_BROWSE_MAX_DEPTH', 10),
    ],

],
```

| Key | Default | Description |
|-----|---------|-------------|
| `endpoint` | `opc.tcp://localhost:4840` | OPC UA server URL |
| `security_policy` | `None` | Security policy name or URI |
| `security_mode` | `None` | `None`, `Sign`, or `SignAndEncrypt` |
| `username` / `password` | `null` | Username/password authentication |
| `client_certificate` / `client_key` | `null` | Client certificate (auto-generated if omitted) |
| `ca_certificate` | `null` | CA certificate for server validation |
| `user_certificate` / `user_key` | `null` | X.509 certificate authentication |
| `timeout` | `5.0` | Network timeout in seconds |
| `auto_retry` | `null` | Max reconnection retries |
| `batch_size` | `null` | Max items per read/write batch |
| `browse_max_depth` | `10` | Default depth for `browseRecursive()` |

## Multiple Connections

Define additional connections following the same pattern as `config/database.php`:

```php
'connections' => [

    'default' => [
        'endpoint' => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),
    ],

    'plc-line-1' => [
        'endpoint' => 'opc.tcp://10.0.0.10:4840',
        'username' => 'operator',
        'password' => 'pass123',
    ],

    'plc-line-2' => [
        'endpoint'           => 'opc.tcp://10.0.0.11:4840',
        'security_policy'    => 'Basic256Sha256',
        'security_mode'      => 'SignAndEncrypt',
        'client_certificate' => '/etc/opcua/certs/client.pem',
        'client_key'         => '/etc/opcua/certs/client.key',
    ],

],
```

## Environment Variables Reference

```dotenv
# Connection
OPCUA_CONNECTION=default
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840

# Authentication
OPCUA_USERNAME=admin
OPCUA_PASSWORD=secret

# Security
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/path/to/client.pem
OPCUA_CLIENT_KEY=/path/to/client.key
OPCUA_CA_CERT=/path/to/ca.pem

# Client behaviour
OPCUA_TIMEOUT=10.0
OPCUA_AUTO_RETRY=3
OPCUA_BATCH_SIZE=100
OPCUA_BROWSE_MAX_DEPTH=20

# Session manager
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=
OPCUA_SESSION_TIMEOUT=600
OPCUA_CLEANUP_INTERVAL=30
OPCUA_AUTH_TOKEN=my-secret-token
OPCUA_MAX_SESSIONS=100

# Daemon logging & cache
OPCUA_LOG_CHANNEL=stack
OPCUA_CACHE_STORE=redis
```
