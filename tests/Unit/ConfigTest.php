<?php

declare(strict_types=1);

describe('opcua config', function () {

    beforeEach(function () {
        // Stub storage_path() so the config file can be loaded outside Laravel
        if (!function_exists('storage_path')) {
            function storage_path(string $path = ''): string
            {
                return sys_get_temp_dir() . '/storage/' . $path;
            }
        }

        $this->config = require __DIR__ . '/../../config/opcua.php';
    });

    it('has a default connection key', function () {
        expect($this->config)->toHaveKey('default');
    });

    it('has a session_manager section', function () {
        expect($this->config)->toHaveKey('session_manager');
        expect($this->config['session_manager'])->toHaveKeys([
            'enabled',
            'socket_path',
            'timeout',
            'cleanup_interval',
            'auth_token',
            'max_sessions',
            'socket_mode',
            'allowed_cert_dirs',
            'log_channel',
            'cache_store',
        ]);
    });

    it('has a connections section with a default connection', function () {
        expect($this->config)->toHaveKey('connections');
        expect($this->config['connections'])->toHaveKey('default');
    });

    it('default connection has all expected keys', function () {
        $conn = $this->config['connections']['default'];

        expect($conn)->toHaveKeys([
            'endpoint',
            'security_policy',
            'security_mode',
            'username',
            'password',
            'client_certificate',
            'client_key',
            'ca_certificate',
            'user_certificate',
            'user_key',
            'timeout',
            'auto_retry',
            'batch_size',
            'browse_max_depth',
        ]);
    });

    it('has sensible session manager defaults', function () {
        $sm = $this->config['session_manager'];

        expect($sm['timeout'])->toBe(600);
        expect($sm['cleanup_interval'])->toBe(30);
        expect($sm['max_sessions'])->toBe(100);
        expect($sm['socket_mode'])->toBe(0600);
        expect($sm['auth_token'])->toBeNull();
        expect($sm['allowed_cert_dirs'])->toBeNull();
    });

    it('has sensible daemon logging and cache defaults', function () {
        $sm = $this->config['session_manager'];

        expect($sm['log_channel'])->toBeString();
        expect($sm['cache_store'])->toBeString();
    });
});
