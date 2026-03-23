<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

// ── Direct mode (no daemon) ──────────────────────────────────────────

describe('Connection via OpcuaManager (direct mode)', function () {

    it('connects to no-security server and returns a Client instance', function () {
        $manager = TestHelper::createDirectManager();
        try {
            $client = $manager->connect();
            expect($client)->toBeInstanceOf(Client::class);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('reads ServerState via connect()', function () {
        $manager = TestHelper::createDirectManager();
        try {
            $client = $manager->connect();
            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeInt()->toBe(0);
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('connects to a named connection', function () {
        $manager = TestHelper::createDirectManager([
            'default' => 'plc1',
            'connections' => [
                'plc1' => ['endpoint' => TestHelper::ENDPOINT_NO_SECURITY],
            ],
        ]);
        try {
            $client = $manager->connect('plc1');
            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('plc1', $manager);
        }
    })->group('integration');

    it('connects to multiple named connections simultaneously', function () {
        $manager = TestHelper::createDirectManager([
            'connections' => [
                'default' => ['endpoint' => TestHelper::ENDPOINT_NO_SECURITY],
                'second' => ['endpoint' => TestHelper::ENDPOINT_NO_SECURITY],
            ],
        ]);
        try {
            $clientA = $manager->connect('default');
            $clientB = $manager->connect('second');

            expect($clientA)->not->toBe($clientB);

            $refsA = $clientA->browse(NodeId::numeric(0, 85));
            $refsB = $clientB->browse(NodeId::numeric(0, 85));
            expect($refsA)->toBeArray()->not->toBeEmpty();
            expect($refsB)->toBeArray()->not->toBeEmpty();
        } finally {
            $manager->disconnectAll();
        }
    })->group('integration');

    it('disconnects cleanly and removes connection', function () {
        $manager = TestHelper::createDirectManager();
        $client = $manager->connect();
        $refs = $client->browse(NodeId::numeric(0, 85));
        expect($refs)->not->toBeEmpty();

        $manager->disconnect();

        // Connection should be removed, next call creates a new one
        $client2 = $manager->connect();
        expect($client2)->not->toBe($client);

        $manager->disconnect();
    })->group('integration');

    it('disconnectAll cleans up everything', function () {
        $manager = TestHelper::createDirectManager([
            'connections' => [
                'default' => ['endpoint' => TestHelper::ENDPOINT_NO_SECURITY],
                'second' => ['endpoint' => TestHelper::ENDPOINT_NO_SECURITY],
            ],
        ]);

        $manager->connect('default');
        $manager->connect('second');
        $manager->disconnectAll();

        $ref = new ReflectionProperty($manager, 'connections');
        expect($ref->getValue($manager))->toBeEmpty();
    })->group('integration');

    it('connects with username/password config', function () {
        $manager = TestHelper::createDirectManager([
            'connections' => [
                'default' => [
                    'endpoint' => TestHelper::ENDPOINT_USERPASS,
                    'security_policy' => 'Basic256Sha256',
                    'security_mode' => 'SignAndEncrypt',
                    'username' => TestHelper::USER_ADMIN['username'],
                    'password' => TestHelper::USER_ADMIN['password'],
                    'client_certificate' => TestHelper::getClientCertPath(),
                    'client_key' => TestHelper::getClientKeyPath(),
                    'ca_certificate' => TestHelper::getCaCertPath(),
                ],
            ],
        ]);
        try {
            $client = $manager->connect();
            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('connects with security policy but no client certificate (auto-generated)', function () {
        $manager = TestHelper::createDirectManager([
            'connections' => [
                'default' => [
                    'endpoint' => TestHelper::ENDPOINT_AUTO_ACCEPT,
                    'security_policy' => 'Basic256Sha256',
                    'security_mode' => 'SignAndEncrypt',
                    // No client_certificate / client_key — auto-generation kicks in
                ],
            ],
        ]);
        try {
            $client = $manager->connect();
            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('connects with certificate config', function () {
        $manager = TestHelper::createDirectManager([
            'connections' => [
                'default' => [
                    'endpoint' => TestHelper::ENDPOINT_AUTO_ACCEPT,
                    'security_policy' => 'Basic256Sha256',
                    'security_mode' => 'SignAndEncrypt',
                    'client_certificate' => TestHelper::getClientCertPath(),
                    'client_key' => TestHelper::getClientKeyPath(),
                    'ca_certificate' => TestHelper::getCaCertPath(),
                    'user_certificate' => TestHelper::getClientCertPath(),
                    'user_key' => TestHelper::getClientKeyPath(),
                ],
            ],
        ]);
        try {
            $client = $manager->connect();
            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

})->group('integration');

// ── Managed mode (via session manager daemon) ────────────────────────

describe('Connection via OpcuaManager (managed mode)', function () {

    it('connects via daemon and returns a ManagedClient instance', function () {
        $manager = TestHelper::createManagedManager();
        try {
            $client = $manager->connect();
            expect($client)->toBeInstanceOf(ManagedClient::class);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('reads ServerState via managed connection', function () {
        $manager = TestHelper::createManagedManager();
        try {
            $client = $manager->connect();
            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeInt()->toBe(0);
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('connects with username/password via daemon', function () {
        $manager = TestHelper::createManagedManager([
            'connections' => [
                'default' => [
                    'endpoint' => TestHelper::ENDPOINT_USERPASS,
                    'security_policy' => 'Basic256Sha256',
                    'security_mode' => 'SignAndEncrypt',
                    'username' => TestHelper::USER_ADMIN['username'],
                    'password' => TestHelper::USER_ADMIN['password'],
                    'client_certificate' => TestHelper::getClientCertPath(),
                    'client_key' => TestHelper::getClientKeyPath(),
                    'ca_certificate' => TestHelper::getCaCertPath(),
                ],
            ],
        ]);
        try {
            $client = $manager->connect();
            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('connects with security policy but no client certificate via daemon (auto-generated)', function () {
        $manager = TestHelper::createManagedManager([
            'connections' => [
                'default' => [
                    'endpoint' => TestHelper::ENDPOINT_AUTO_ACCEPT,
                    'security_policy' => 'Basic256Sha256',
                    'security_mode' => 'SignAndEncrypt',
                    // No client_certificate / client_key — auto-generation kicks in
                ],
            ],
        ]);
        try {
            $client = $manager->connect();
            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect('default', $manager);
        }
    })->group('integration');

    it('isSessionManagerRunning returns true when daemon is active', function () {
        $manager = TestHelper::createManagedManager();
        expect($manager->isSessionManagerRunning())->toBeTrue();
    })->group('integration');

})->group('integration');

// ── connectTo (ad-hoc connections) ───────────────────────────────────

describe('connectTo ad-hoc connections', function () {

    it('connects to an arbitrary endpoint (direct mode)', function () {
        $manager = TestHelper::createDirectManager();
        try {
            $client = $manager->connectTo(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client)->toBeInstanceOf(OpcUaClientInterface::class);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            $manager->disconnectAll();
        }
    })->group('integration');

    it('connects to an arbitrary endpoint with inline config', function () {
        $manager = TestHelper::createDirectManager();
        try {
            $client = $manager->connectTo(TestHelper::ENDPOINT_USERPASS, [
                'security_policy' => 'Basic256Sha256',
                'security_mode' => 'SignAndEncrypt',
                'username' => TestHelper::USER_ADMIN['username'],
                'password' => TestHelper::USER_ADMIN['password'],
                'client_certificate' => TestHelper::getClientCertPath(),
                'client_key' => TestHelper::getClientKeyPath(),
                'ca_certificate' => TestHelper::getCaCertPath(),
            ]);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            $manager->disconnectAll();
        }
    })->group('integration');

    it('stores ad-hoc connection under custom name', function () {
        $manager = TestHelper::createDirectManager();
        try {
            $manager->connectTo(TestHelper::ENDPOINT_NO_SECURITY, as: 'temp-plc');

            $retrieved = $manager->connection('temp-plc');
            $refs = $retrieved->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            $manager->disconnectAll();
        }
    })->group('integration');

    it('connectTo via managed mode uses daemon', function () {
        $manager = TestHelper::createManagedManager();
        try {
            $client = $manager->connectTo(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client)->toBeInstanceOf(ManagedClient::class);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            $manager->disconnectAll();
        }
    })->group('integration');

})->group('integration');
