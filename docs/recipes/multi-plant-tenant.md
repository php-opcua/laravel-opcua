---
eyebrow: 'Docs · Recipes'
lede:    'Multi-tenant Laravel apps with per-tenant OPC UA endpoints, isolated trust stores, scoped persistence, and tenant-aware listeners. The complete shape.'

see_also:
  - { href: '../using-the-client/named-connections.md', meta: '5 min' }
  - { href: '../using-the-client/ad-hoc-connections.md', meta: '5 min' }
  - { href: '../security/trust-store.md',              meta: '6 min' }

prev: { label: 'Livewire real-time dashboard', href: './livewire-realtime-dashboard.md' }
next: { label: 'Using companion specs',         href: './using-companion-specs.md' }
---

# Multi-plant tenant

A single Laravel app serving many plants, each with its own
OPC UA infrastructure. This recipe covers the four hard parts:

1. Per-tenant connection config.
2. Per-tenant credentials and trust stores.
3. Tenant-aware listeners.
4. Per-tenant data isolation in the persistence layer.

## Tenancy model

The recipe assumes you've already adopted a tenancy approach —
[stancl/tenancy](https://github.com/archtechx/tenancy),
[spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy),
or a homegrown `tenant_id`-on-every-row pattern.

This page is OPC UA-specific. Pick your tenancy library
separately; the patterns adapt to all of them.

## Per-tenant connection config

### Approach A — static config

For a small, known set of tenants (10-100), put them all in
`config/opcua.php`:

<!-- @code-block language="php" label="static tenants" -->
```php
'connections' => [
    'plc-tenant-acme' => [
        'endpoint'         => env('OPCUA_ACME_ENDPOINT'),
        'security_policy'  => 'Basic256Sha256',
        'security_mode'    => 'SignAndEncrypt',
        'client_cert_path' => '/etc/opcua/tenants/acme/cert.pem',
        'client_key_path'  => '/etc/opcua/tenants/acme/cert.key',
        'username'         => env('OPCUA_ACME_USER'),
        'password'         => env('OPCUA_ACME_PASS'),
        'trust_store_path' => '/var/lib/opcua/tenants/acme/trust',
    ],
    'plc-tenant-globex' => [
        'endpoint'         => env('OPCUA_GLOBEX_ENDPOINT'),
        // ... mirror structure
    ],
],
```
<!-- @endcode-block -->

A helper to resolve the current tenant:

<!-- @code-block language="php" label="resolver" -->
```php
namespace App\Services;

class OpcuaConnectionResolver
{
    public function forCurrentTenant(): string
    {
        $tenantSlug = tenant()->slug ?? request()->user()->tenant->slug;

        return "plc-tenant-{$tenantSlug}";
    }
}
```
<!-- @endcode-block -->

…used everywhere:

<!-- @code-block language="php" label="usage" -->
```php
$conn = app(OpcuaConnectionResolver::class)->forCurrentTenant();
$dv   = Opcua::connection($conn)->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

### Approach B — dynamic per-tenant

For a large or growing tenant set, store config in a database
table and use `connectTo()`:

<!-- @code-block language="php" label="dynamic" -->
```php
namespace App\Models;

class TenantPlcConfig extends Model
{
    protected $guarded = [];
    protected $casts   = ['password' => 'encrypted'];

    public function toOpcuaConfig(): array
    {
        return [
            'endpoint'         => $this->endpoint,
            'security_policy'  => $this->security_policy,
            'security_mode'    => $this->security_mode,
            'client_cert_path' => $this->cert_path,
            'client_key_path'  => $this->key_path,
            'username'         => $this->username,
            'password'         => $this->password,
            'trust_store_path' => "/var/lib/opcua/tenants/{$this->tenant->slug}/trust",
        ];
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="usage — dynamic" -->
```php
$config = tenant()->plcConfigs()->first()->toOpcuaConfig();
$client = Opcua::connectTo($config);
$dv     = $client->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

`encrypted` cast keeps the password at rest encrypted using
Laravel's `APP_KEY`. The decrypted value lives in memory only.

## Per-tenant trust stores

Each tenant has its own pinned server certs:

<!-- @code-block language="bash" label="filesystem" -->
```bash
/var/lib/opcua/tenants/
├── acme/
│   └── trust/
│       └── <fingerprint>.pem
├── globex/
│   └── trust/
│       └── <fingerprint>.pem
└── soylent/
    └── trust/
        └── <fingerprint>.pem
```
<!-- @endcode-block -->

Add for a specific tenant — use the companion
[`opcua-cli`](https://github.com/php-opcua/opcua-cli) tool (the
Laravel package doesn't ship `opcua:trust:add`):

<!-- @code-block language="bash" label="terminal" -->
```bash
OPCUA_TRUST_STORE_PATH=/var/lib/opcua/tenants/acme/trust \
  vendor/bin/opcua-cli trust:add opc.tcp://acme-plc.factory.local:4840
```
<!-- @endcode-block -->

Permissions:

<!-- @code-block language="bash" label="terminal — perms" -->
```bash
sudo chown -R www-data:www-data /var/lib/opcua/tenants/
sudo chmod 750 /var/lib/opcua/tenants/
sudo find /var/lib/opcua/tenants -type d -exec chmod 750 {} \;
sudo find /var/lib/opcua/tenants -type f -exec chmod 640 {} \;
```
<!-- @endcode-block -->

A compromised tenant's trust store doesn't affect others.

## Tenant-aware listeners

The event arrives without tenant context. The listener resolves
from the connection name:

<!-- @code-block language="php" label="tenant-aware listener" -->
```php
namespace App\Listeners;

use App\Models\{PlcReading, Tenant};
use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreReadingForTenant implements ShouldQueue
{
    public string $queue = 'opcua-data';

    /**
     * Build a clientHandle => tenantSlug map at subscription time.
     * The handle is what the event carries — the connection name is not.
     */
    private const HANDLE_TENANT_MAP = [
        // 1000-1999 = ACME, 2000-2999 = Globex, …
        // Bucket the ranges any way that fits your scheme.
    ];

    public function handle(DataChangeReceived $event): void
    {
        $slug = $this->slugFromHandle($event->clientHandle);
        $tenant = $slug ? Tenant::where('slug', $slug)->first() : null;
        if (! $tenant) {
            \Log::warning("Unknown tenant for handle {$event->clientHandle}");
            return;
        }

        $tenant->run(function () use ($event) {
            PlcReading::create([
                'client_handle' => $event->clientHandle,
                'value'         => $event->dataValue->getValue(),
                'status_code'   => $event->dataValue->statusCode,
                'source_at'     => $event->dataValue->sourceTimestamp,
            ]);
        });
    }

    private function slugFromHandle(int $handle): ?string
    {
        return match (true) {
            $handle >= 1000 && $handle < 2000 => 'acme',
            $handle >= 2000 && $handle < 3000 => 'globex',
            default                            => null,
        };
    }
}
```
<!-- @endcode-block -->

`$tenant->run(...)` is the multi-tenancy library's
"execute-in-tenant-context" wrapper. The closure runs with the
tenant's database connection scoped, so `PlcReading::create`
lands in the right schema.

For single-database tenancy (where `tenant_id` is a column),
adapt to:

<!-- @code-block language="php" label="single-db variant" -->
```php
PlcReading::create([
    'tenant_id'     => $tenant->id,
    'client_handle' => $event->clientHandle,
    // ...
]);
```
<!-- @endcode-block -->

## Per-tenant daemons

For hard tenant isolation, run **one daemon per tenant**:

<!-- @code-block language="text" label="systemd template" -->
```text
[Unit]
Description=OPC UA daemon for tenant %i
After=network-online.target

[Service]
User=opcua-%i
Group=opcua-%i
ExecStart=/usr/bin/php /var/www/html/artisan opcua:session
# socket_path, allowed_cert_dirs and auth_token come from
# /var/www/html-tenant-%i/config/opcua.php — one Laravel install per
# tenant. There are no --socket-path / --allowed-cert-dirs /
# --auth-token CLI flags on opcua:session.
Environment=APP_BASE_PATH=/var/www/html-tenant-%i
Restart=on-failure

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

Saved as `/etc/systemd/system/opcua-session-manager@.service`,
then enabled per tenant:

<!-- @code-block language="bash" label="terminal" -->
```bash
systemctl enable opcua-session-manager@acme.service
systemctl enable opcua-session-manager@globex.service
systemctl start opcua-session-manager@acme.service
systemctl start opcua-session-manager@globex.service
```
<!-- @endcode-block -->

Per-tenant Unix user means a compromise of one tenant's daemon
can't touch another's. Worth it for high-stakes deployments.

The Laravel config points each tenant connection at the right
daemon:

<!-- @code-block language="php" label="per-daemon socket" -->
```php
// Per-tenant Laravel install — config/opcua.php for the ACME tenant:
'session_manager' => [
    'socket_path' => '/var/run/opcua/tenants/acme/sessions.sock',
    // ...
],
'connections' => [
    'plc' => [
        'endpoint' => 'opc.tcp://acme-plc.factory.local:4840',
        // ...
    ],
],

// Per-tenant Laravel install — config/opcua.php for the Globex tenant:
// (separate Laravel install, separate config file)
'session_manager' => [
    'socket_path' => '/var/run/opcua/tenants/globex/sessions.sock',
],
'connections' => [
    'plc' => [
        'endpoint' => 'opc.tcp://globex-plc.factory.local:4840',
    ],
],
```
<!-- @endcode-block -->

## Tenant onboarding command

Automate new-tenant setup:

<!-- @code-block language="php" label="OnboardPlcTenant command" -->
```php
class OnboardPlcTenant extends Command
{
    protected $signature = 'plc:onboard
        {tenant : Tenant slug}
        {endpoint : OPC UA endpoint}
        {username : OPC UA username}
        {password : OPC UA password}';

    public function handle(): int
    {
        $slug = $this->argument('tenant');
        $base = "/var/lib/opcua/tenants/{$slug}";

        // 1. Create directories
        mkdir("$base/trust", recursive: true);

        // 2. Pin the server cert (using opcua-cli — laravel-opcua does not
        // ship an opcua:trust:add command).
        $endpoint = $this->argument('endpoint');
        $env = ['OPCUA_TRUST_STORE_PATH' => "$base/trust"];
        $cmd = ['vendor/bin/opcua-cli', 'trust:add', '--force', $endpoint];
        $proc = new \Symfony\Component\Process\Process($cmd, env: $env);
        $proc->mustRun();

        // 3. Generate a client cert
        $this->generateClientCert("$base/cert.pem", "$base/cert.key", $slug);

        // 4. Persist the tenant config
        TenantPlcConfig::create([
            'tenant_id'        => Tenant::where('slug', $slug)->firstOrFail()->id,
            'endpoint'         => $this->argument('endpoint'),
            'username'         => $this->argument('username'),
            'password'         => $this->argument('password'),
            'cert_path'        => "$base/cert.pem",
            'key_path'         => "$base/cert.key",
            'security_policy'  => 'Basic256Sha256',
            'security_mode'    => 'SignAndEncrypt',
        ]);

        $this->info("Onboarded tenant {$slug}");
        $this->warn("Now register the client cert on the OPC UA server!");
        return self::SUCCESS;
    }

    private function generateClientCert(string $pemPath, string $keyPath, string $slug): void
    {
        \Process::run([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', $keyPath, '-out', $pemPath, '-days', '365', '-nodes',
            '-subj', "/CN=Laravel-OPCUA-{$slug}/O=Acme",
            '-addext', "subjectAltName=URI:urn:laravel-opcua:{$slug}",
        ])->throw();

        chmod($keyPath, 0600);
        chmod($pemPath, 0640);
    }
}
```
<!-- @endcode-block -->

## Tenant offboarding

The complement:

<!-- @code-block language="php" label="OffboardPlcTenant" -->
```php
class OffboardPlcTenant extends Command
{
    protected $signature = 'plc:offboard {tenant}';

    public function handle(): int
    {
        $slug = $this->argument('tenant');

        // 1. Stop the per-tenant daemon (if applicable)
        \Process::run(['systemctl', 'stop', "opcua-session-manager@{$slug}.service"]);
        \Process::run(['systemctl', 'disable', "opcua-session-manager@{$slug}.service"]);

        // 2. Remove the tenant config
        TenantPlcConfig::whereHas('tenant', fn($q) => $q->where('slug', $slug))->delete();

        // 3. Archive (don't delete) the trust store + certs
        $from = "/var/lib/opcua/tenants/{$slug}";
        $to   = "/var/lib/opcua/archive/" . now()->format('YmdHis') . "-{$slug}";
        rename($from, $to);

        // 4. Tenant data: per-app policy. Often you keep it for audit.
        $this->info("Offboarded {$slug}. Data archived to {$to}");
        return 0;
    }
}
```
<!-- @endcode-block -->

## Cost model

| Tenants  | Single daemon                 | Per-tenant daemons              |
| -------- | ----------------------------- | ------------------------------- |
| 1-10     | Recommended                   | Overkill                         |
| 10-50    | Fine if tenants trust each other | Recommended for prod          |
| 50+      | Memory pressure on the daemon | Required                         |

Single-daemon CPU: 1-2% per active subscription.
Per-tenant-daemon CPU: same, plus IPC overhead per call.
Per-tenant-daemon memory: ~50 MB base per daemon.

For 100 tenants on per-tenant daemons, budget ~5 GB RAM just
for daemons.

## Where to read next

- [Using companion specs](./using-companion-specs.md) —
  type-aware browsing for tenant-specific tag taxonomies.
- [Production deployment](./production-deployment.md) — putting
  it all on a server.
