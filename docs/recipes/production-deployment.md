---
eyebrow: 'Docs · Recipes'
lede:    'Production deployment checklist: hardware, supervisor units, secrets, deploy scripts, post-deploy hooks, monitoring. The last page of the docs, the first you reach for when shipping.'

see_also:
  - { href: '../session-manager/production-supervisor.md', meta: '6 min' }
  - { href: '../session-manager/monitoring-the-daemon.md', meta: '5 min' }
  - { href: '../security/certificates.md',                meta: '7 min' }

prev: { label: 'Dev with Sail',                href: './dev-with-sail.md' }
next: { label: 'Top of docs',                  href: '../index.md' }
---

# Production deployment

A complete production checklist for shipping `laravel-opcua` to
a real plant.

## Hardware sizing

| Workload                          | CPU       | Memory     | Disk       |
| --------------------------------- | --------- | ---------- | ---------- |
| Single PLC, low traffic           | 1 vCPU    | 1 GB       | 20 GB      |
| 5 PLCs, real-time UI, auto-publish | 2 vCPU   | 2 GB       | 40 GB      |
| 50 PLCs, dashboard, history       | 4 vCPU    | 4 GB       | 100+ GB    |
| 500-PLC fleet                      | 8 vCPU   | 8 GB       | 500+ GB    |

The disk grows with `plc_readings`. Plan retention or aggregation.

## Required services

| Service               | Where             | Purpose                                |
| --------------------- | ----------------- | -------------------------------------- |
| PHP-FPM / Octane      | Web tier          | HTTP                                   |
| OPC UA daemon          | One per app host  | Session pooling                        |
| Redis                  | Web tier or shared | Cache + queue + broadcasting          |
| Reverb                 | Web tier          | Real-time WebSocket                    |
| Horizon                | App tier          | Queue supervision                      |
| MySQL / PostgreSQL    | DB tier           | Persistence                            |
| nginx                  | Web tier          | Reverse proxy                          |

For small deployments, all of these fit on one host. For larger,
split web/queue/db across hosts.

## Pre-deploy checklist

- [ ] OPC UA server credentials in the secrets manager.
- [ ] Server cert pinned in the trust store (drop the PEM in
      `trust_store_path`, or use `opcua-cli trust:add`).
- [ ] Client cert generated, registered server-side.
- [ ] `.env.production` lists every `OPCUA_*` variable.
- [ ] systemd / Supervisor unit files in place for the daemon.
- [ ] Horizon supervisor declarations match the queues your
      listeners use.
- [ ] DB migrations for `plc_readings`, `plc_alarms` (if used).
- [ ] Log channel `opcua` declared in `config/logging.php`.
- [ ] Health endpoint `/health/opcua` reachable from your
      monitoring.

## Initial deploy

<!-- @code-block language="bash" label="terminal — first deploy" -->
```bash
# 1. Code
git clone <repo> /var/www/html
cd /var/www/html
composer install --no-dev --optimize-autoloader

# 2. Env
cp .env.production .env
php artisan key:generate

# 3. Migrations
php artisan migrate --force

# 4. Cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Storage
mkdir -p storage/framework/{sessions,views,cache,opcua}
chown -R www-data:www-data storage

# 6. OPC UA setup
sudo mkdir -p /var/lib/opcua/trust
sudo chown www-data:www-data /var/lib/opcua/trust

OPCUA_TRUST_STORE_PATH=/var/lib/opcua/trust \
  vendor/bin/opcua-cli trust:add opc.tcp://plc.factory.local:4840

# 7. Services
sudo systemctl daemon-reload
sudo systemctl enable --now opcua-session-manager
sudo systemctl enable --now horizon
sudo systemctl enable --now reverb         # if using
```
<!-- @endcode-block -->

## Ongoing deploy script

`deploy.sh`:

<!-- @code-block language="bash" label="deploy.sh" -->
```bash
#!/bin/bash
set -e

cd /var/www/html

git fetch origin
git checkout origin/main

composer install --no-dev --optimize-autoloader --no-progress

# Migrations — abort if a destructive change is queued
php artisan migrate --force

# Caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Restart services that don't auto-reload PHP
sudo systemctl restart opcua-session-manager
sudo systemctl reload php8.4-fpm

php artisan horizon:terminate   # Horizon gracefully restarts itself
php artisan octane:reload       # if using Octane

# Post-deploy verification — use a small custom command (or curl
# your /health/opcua endpoint). The laravel-opcua package does not
# ship opcua:ping; see docs/observability/debugging.md for a
# minimal probe pattern.
sleep 2
curl -fsS http://127.0.0.1/health/opcua \
    || (echo "Daemon not up after deploy!" && exit 1)

echo "Deploy complete: $(git rev-parse HEAD)"
```
<!-- @endcode-block -->

## Octane variant

With Octane (FrankenPHP), don't restart php-fpm; instead
`octane:reload`:

<!-- @code-block language="bash" label="octane deploy step" -->
```bash
php artisan octane:reload
```
<!-- @endcode-block -->

Octane workers drain in-flight requests, restart with the new
code. The daemon needs a separate restart since it doesn't go
through Octane.

## Secrets management

| Where                          | Secrets                                       |
| ------------------------------ | --------------------------------------------- |
| `.env` (not committed)         | `APP_KEY`, DB creds                            |
| Secrets manager → process env  | `OPCUA_PASSWORD`, `OPCUA_AUTH_TOKEN`           |
| Filesystem (`mode=0600`)        | `client.key`, `cert.key`                      |
| `vault read kv/opcua/...`      | Per-tenant credentials                         |

Don't `cat /etc/opcua/client.key` into `.env`. Keep keys as
files referenced by path.

## systemd unit (recommended)

<!-- @code-block language="text" label="/etc/systemd/system/opcua-session-manager.service" -->
```text
[Unit]
Description=OPC UA Session Manager
After=network-online.target redis.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
EnvironmentFile=/etc/opcua/laravel.env
ExecStartPre=/usr/bin/mkdir -p /var/run/opcua
ExecStartPre=/usr/bin/chown www-data:www-data /var/run/opcua
ExecStart=/usr/bin/php /var/www/html/artisan opcua:session \
    --socket-mode=0660 \
    --log-channel=opcua \
    --cache-store=redis
# socket_path, auth_token, allowed_cert_dirs and auto_publish come
# from config/opcua.php (sourced from /etc/opcua/laravel.env via
# OPCUA_SOCKET_PATH, OPCUA_AUTH_TOKEN, OPCUA_AUTO_PUBLISH, and the
# `allowed_cert_dirs` key in the config file). There are no CLI
# flags for those settings on opcua:session.

ExecStartPost=/bin/sleep 2
ExecStartPost=/usr/bin/php /var/www/html/artisan opcua:resubscribe

Restart=on-failure
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
NoNewPrivileges=true
ReadWritePaths=/var/run/opcua /var/log /var/www/html/storage /var/lib/opcua

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

The `ExecStartPost` block runs the application's
`opcua:resubscribe` command — see [Auto-publish · Recovery](../session-manager/auto-publish.md#recovery-after-daemon-restart).

## Permissions matrix

| Path                                   | Mode        | Owner          |
| -------------------------------------- | ----------- | -------------- |
| `/var/www/html/`                       | `755`       | `www-data`     |
| `/var/www/html/storage/`               | `775`       | `www-data`     |
| `/var/lib/opcua/trust/`                | `750`       | `www-data`     |
| `/var/lib/opcua/trust/<hash>.pem`      | `640`       | `www-data`     |
| `/etc/opcua/client.pem`                | `640`       | `www-data`     |
| `/etc/opcua/client.key`                | `600`       | `www-data`     |
| `/etc/opcua/laravel.env`               | `600`       | `root`          |
| `/var/run/opcua/sessions.sock`         | `660`       | `www-data`     |
| `/var/log/opcua.log`                    | `640`       | `www-data`     |

## Monitoring hooks

A standard monitoring config might include:

| What                                       | How                                                  |
| ------------------------------------------ | ---------------------------------------------------- |
| HTTP up                                    | `GET /` returns 200                                  |
| Daemon up                                  | `GET /health/opcua` returns `"status":"up"`          |
| OPC UA reachable                           | `GET /health/opcua/detail` shows sessions > 0        |
| Event flow                                 | `GET /health/opcua/flow` shows `stale: false`        |
| Queue backlog                              | Horizon API or `Redis::llen('queues:opcua-data')`    |
| Cert expiry                                | Custom command exit code (cron daily) — see [Certificates](../security/certificates.md) |
| Failed jobs                                | `failed_jobs` table size                              |

Wire each to your alerting (Slack, PagerDuty).

## Rollback

If a deploy goes wrong:

<!-- @code-block language="bash" label="terminal — rollback" -->
```bash
cd /var/www/html
git checkout <previous-sha>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=1     # only if you migrated
php artisan config:cache
sudo systemctl restart opcua-session-manager
php artisan horizon:terminate
php artisan octane:reload
```
<!-- @endcode-block -->

Rollback time: ~30 seconds. The daemon restart is the slowest
part.

## Production gotchas

| Symptom                                              | Likely cause                                           |
| ---------------------------------------------------- | ------------------------------------------------------ |
| Daemon CPU climbs over time                          | A listener throws inside the daemon-side dispatcher.   |
| Memory grows without bound                           | Cache backend without LRU, or listener leak.            |
| Daemon-side socket file leaks after crash            | Missing `RemoveOnExit` semantics — clean manually.      |
| Workers can't connect after deploy                   | Daemon restarted slower than workers; brief failure window. Wait 2-5 s. |
| Browser doesn't update                               | Reverb / Echo wire issue, not OPC UA. Check WS connection. |
| Sessions accumulate server-side                       | Daemon `session_timeout` longer than server's `MaxSessionTimeout`. |

## Cost model — one-host deployment

A representative all-in-one EC2 (m5.large equivalent):

| Service             | Approximate cost (USD/mo) |
| ------------------- | ------------------------- |
| Compute              | ~70                       |
| EBS volumes          | ~10                       |
| Network              | ~5                        |
| Total                | ~85                       |

Plus the OPC UA licensing fees from your PLC vendor (separate).

## Where to read next

You've reached the end of the documentation. Useful next stops:

- The [package README on GitHub](https://github.com/php-opcua/laravel-opcua).
- [Top of docs](../index.md).
- Companion documentation:
  - [`opcua-client`](https://github.com/php-opcua/opcua-client)
  - [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)
  - [`opcua-client-nodeset`](https://github.com/php-opcua/opcua-client-nodeset)
  - [`opcua-cli`](https://github.com/php-opcua/opcua-cli)
