---
eyebrow: 'Docs · Session manager'
lede:    'Production-grade orchestration: Supervisor and systemd unit files, Horizon coordination, log rotation, deploy-aware restarts. The unit files Laravel devs already trust.'

see_also:
  - { href: './starting-the-daemon.md',     meta: '5 min' }
  - { href: './monitoring-the-daemon.md',   meta: '5 min' }
  - { href: '../recipes/production-deployment.md', meta: '6 min' }

prev: { label: 'Auto-publish',           href: './auto-publish.md' }
next: { label: 'Monitoring the daemon',  href: './monitoring-the-daemon.md' }
---

# Production supervisor

The daemon is a long-running PHP process. It needs:

- A process supervisor to keep it up.
- A log rotation strategy.
- A deploy hook to restart it on code updates.

Two recommended supervisors: **Supervisor** (the same one
Laravel uses for queue workers) and **systemd** (the OS-level
choice).

## Supervisor — the Laravel-native pattern

<!-- @code-block language="bash" label="install supervisor" -->
```bash
sudo apt install supervisor    # Debian/Ubuntu
sudo dnf install supervisor    # Fedora/RHEL
```
<!-- @endcode-block -->

Drop a config file at `/etc/supervisor/conf.d/opcua-session-manager.conf`:

<!-- @code-block language="text" label="/etc/supervisor/conf.d/opcua-session-manager.conf" -->
```text
[program:opcua-session-manager]
process_name=%(program_name)s
command=php /var/www/html/artisan opcua:session
    --socket-mode=0660
    --log-channel=opcua
    --cache-store=redis
# socket_path and auto_publish come from config/opcua.php
# (driven by OPCUA_SOCKET_PATH and OPCUA_AUTO_PUBLISH in the env).
# opcua:session has no --socket-path or --auto-publish flag.

autostart=true
autorestart=true
startretries=10
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/opcua-session-manager.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=10
stopwaitsecs=30
stopsignal=TERM
```
<!-- @endcode-block -->

Apply:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start opcua-session-manager
sudo supervisorctl status
```
<!-- @endcode-block -->

`stopsignal=TERM` and `stopwaitsecs=30` give the daemon
enough time to close sessions cleanly.

## systemd — the OS-native pattern

Drop a unit at `/etc/systemd/system/opcua-session-manager.service`:

<!-- @code-block language="text" label="/etc/systemd/system/opcua-session-manager.service" -->
```text
[Unit]
Description=OPC UA Session Manager (Laravel)
After=network-online.target redis-server.service
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
# socket_path, auth_token, and auto_publish all come from
# /etc/opcua/laravel.env via config/opcua.php — opcua:session
# has no --socket-path / --auth-token / --auto-publish flag.

Restart=on-failure
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

# Hardening
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
NoNewPrivileges=true
ReadWritePaths=/var/run/opcua /var/log /var/www/html/storage

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

Apply:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo systemctl daemon-reload
sudo systemctl enable opcua-session-manager
sudo systemctl start opcua-session-manager
sudo systemctl status opcua-session-manager
sudo journalctl -u opcua-session-manager -f
```
<!-- @endcode-block -->

The hardening flags are recommended — they restrict what the
daemon can touch on the host. `ReadWritePaths` must include
every writable directory the daemon needs.

## Auth token via environment

The daemon reads its auth token from `config/opcua.php` (driven by
`OPCUA_AUTH_TOKEN`). There is no `--auth-token` CLI flag. Either:

- **systemd**: `EnvironmentFile=/etc/opcua/laravel.env`, with the
  token inside that file (mode `0600`, root-owned).
- **Supervisor**: an `environment=` directive pointing at the same
  variable, or expand from your secrets manager at deploy time.

## Re-subscribe on restart

If you use auto-publish, register a startup hook that re-subscribes
known tags:

<!-- @code-block language="text" label="systemd — ExecStartPost" -->
```text
ExecStartPost=/usr/bin/php /var/www/html/artisan opcua:resubscribe
```
<!-- @endcode-block -->

The `opcua:resubscribe` command is yours to write — see
[Auto-publish](./auto-publish.md) for the template.

## Log rotation

Two log surfaces:

1. **Supervisor's `stdout_logfile`** — Supervisor rotates this
   itself with `stdout_logfile_maxbytes` and `stdout_logfile_backups`.
2. **Laravel's log channel** — if you used `--log-channel=opcua`
   and that's a daily channel, Laravel manages rotation under
   `storage/logs/opcua-YYYY-MM-DD.log`.

For systemd, use `journalctl` or pipe into a sidecar like
`logrotate`. Don't double-rotate (Supervisor + Laravel + logrotate)
— one mechanism is enough.

## Deploy-time restart

Add a daemon restart to your deploy script after the code-update
step. Examples:

### Envoyer / Forge

<!-- @code-block language="bash" label="deploy hook" -->
```bash
sudo systemctl restart opcua-session-manager
# OR
sudo supervisorctl restart opcua-session-manager
```
<!-- @endcode-block -->

### Capistrano-style

<!-- @code-block language="bash" label="deploy:cleanup hook" -->
```bash
{{release_path}}/artisan opcua:session-restart
```
<!-- @endcode-block -->

(Where `opcua:session-restart` is a simple `Process::run('systemctl restart ...')`
wrapper command.)

### Why restart?

PHP doesn't auto-reload on file changes. A long-running daemon
keeps the **old** code in memory until restart. After deploying
new code, the daemon needs to come up against the new files.

For zero-downtime, run two daemons on different sockets, switch
traffic between them at the load-balancer / config layer. Most
plant deployments accept a 5-30 second blip — the daemon comes
up quickly and reconnects to PLCs.

## Horizon coordination

If you use Horizon, the OPC UA daemon is **independent** of
Horizon's process supervision. The two coexist:

| Process supervisor    | Manages                                  |
| --------------------- | ---------------------------------------- |
| Horizon               | Queue workers                            |
| Supervisor / systemd  | The OPC UA daemon, Horizon itself        |
| Horizon's supervisor  | Workers spawned by Horizon               |

Horizon supervisor reads from `config/horizon.php`; the OPC UA
daemon doesn't appear there. Keep them as two separate concerns
under the system-level supervisor.

## Octane / FrankenPHP coordination

Same answer — the OPC UA daemon is separate. Octane workers
talk to it over IPC. No special config beyond
`session_manager.enabled = true` in the Laravel app and the
daemon being up.

For **Octane reloads** (`octane:reload` on deploy), the OPC UA
daemon doesn't need to know — Octane drops connections cleanly
and the next request reopens. The daemon's connection cache
absorbs the disruption.

## Health checks

Liveness:

<!-- @code-block language="bash" label="terminal — liveness probe" -->
```bash
echo '{"id":1,"t":"req","method":"ping","args":[]}' \
    | nc -U /var/run/opcua/sessions.sock \
    | grep -q '"ok":true'
echo $?    # 0 = healthy
```
<!-- @endcode-block -->

Add this to your monitoring (Datadog, Prometheus blackbox, etc.).
For a Laravel-native pattern, see [Monitoring](./monitoring-the-daemon.md).

## Resource limits

Set `LimitNOFILE=` for file descriptor exhaustion under heavy
load:

<!-- @code-block language="text" label="systemd — limits" -->
```text
[Service]
LimitNOFILE=65536
```
<!-- @endcode-block -->

Memory budget: 100-300 MB for typical workloads. Add ulimits if
your hosting platform doesn't let `php` use that much by default.

## Permission model

The daemon binds the socket at `--socket-mode=0660`. To let
both FPM and Horizon workers connect:

1. Both processes run as the same user (e.g. `www-data`), or
2. Both processes' users are in the socket's group (e.g.
   `www-data` group on the socket file).

The simplest config — same user — is also the most common.

## Where to read next

- [Monitoring the daemon](./monitoring-the-daemon.md) — metrics,
  logs, dashboards.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  putting it all together.
