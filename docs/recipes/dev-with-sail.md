---
eyebrow: 'Docs · Recipes'
lede:    'Laravel Sail with an OPC UA test server sidecar. Docker Compose, the simplest possible dev loop, and the few gotchas around socket paths in containerised environments.'

see_also:
  - { href: '../getting-started/installation.md',        meta: '5 min' }
  - { href: '../testing/integration-tests.md',           meta: '6 min' }
  - { href: './production-deployment.md',                meta: '6 min' }

prev: { label: 'Using companion specs',  href: './using-companion-specs.md' }
next: { label: 'Production deployment',  href: './production-deployment.md' }
---

# Dev with Sail

[Laravel Sail](https://laravel.com/docs/sail) is the lightest
local-development environment for Laravel. Add an OPC UA test
server as a sidecar and you have an end-to-end dev environment
in two Docker images.

## Add the OPC UA test server

In `docker-compose.yml`, add a service:

<!-- @code-block language="text" label="docker-compose.yml" -->
```text
services:
  laravel.test:
    # ... existing Sail config

  opcua-test:
    image: ghcr.io/php-opcua/uanetstandard-test-suite:latest
    ports:
      - "${OPCUA_PORT:-4840}:4840"   # unsecured
      - "${OPCUA_SECURE_PORT:-4841}:4841"   # secured
    networks:
      - sail
    healthcheck:
      test: ["CMD", "sh", "-c", "nc -z localhost 4840"]
      interval: 5s
      retries: 10
```
<!-- @endcode-block -->

Bring it up:

<!-- @code-block language="bash" label="terminal" -->
```bash
./vendor/bin/sail up -d
```
<!-- @endcode-block -->

## Configure Laravel to point at it

In `.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_ENDPOINT=opc.tcp://opcua-test:4840
```
<!-- @endcode-block -->

Note: the hostname is `opcua-test` (the Docker service name),
not `localhost` — Sail networks containers together.

For the **secured** endpoint:

<!-- @code-block language="bash" label=".env (secured)" -->
```bash
OPCUA_ENDPOINT=opc.tcp://opcua-test:4841
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/var/www/html/dev-certs/client.pem
OPCUA_CLIENT_KEY=/var/www/html/dev-certs/client.key
```
<!-- @endcode-block -->

Generate dev certs:

<!-- @code-block language="bash" label="terminal — dev certs" -->
```bash
mkdir -p dev-certs
./vendor/bin/sail exec laravel.test openssl req -x509 -newkey rsa:2048 \
    -keyout dev-certs/client.key \
    -out dev-certs/client.pem \
    -days 365 -nodes \
    -subj "/CN=Sail Dev/O=Local" \
    -addext "subjectAltName=URI:urn:sail-dev:client"
```
<!-- @endcode-block -->

`dev-certs/` is gitignored by default. Don't commit dev keys.

## Running the daemon under Sail

The daemon is just a long-running PHP process — it runs inside
the `laravel.test` container.

For interactive dev (start it when you need it):

<!-- @code-block language="bash" label="terminal — daemon" -->
```bash
./vendor/bin/sail artisan opcua:session
```
<!-- @endcode-block -->

For backgrounded dev:

<!-- @code-block language="text" label="docker-compose.yml — daemon service" -->
```text
services:
  opcua-daemon:
    image: sail-8.4/app
    extends: laravel.test
    command: php /var/www/html/artisan opcua:session
    depends_on:
      opcua-test:
        condition: service_healthy
    volumes:
      - .:/var/www/html
    networks:
      - sail
```
<!-- @endcode-block -->

Now `sail up` brings up Laravel, the OPC UA test server, AND the
daemon. Three-container dev environment.

## Connecting the dots

In `.env` (managed mode under Sail):

<!-- @code-block language="bash" label=".env (managed)" -->
```bash
OPCUA_ENDPOINT=opc.tcp://opcua-test:4840
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=/var/www/html/storage/framework/opcua-session-manager.sock
```
<!-- @endcode-block -->

Both `laravel.test` and `opcua-daemon` see the same socket file
via the shared `.` volume.

## A first test

<!-- @code-block language="bash" label="terminal — tinker" -->
```bash
./vendor/bin/sail artisan tinker
> Opcua::isSessionManagerRunning()
=> true
> Opcua::read('i=2256')->value
=> 0
```
<!-- @endcode-block -->

`i=2256` is the standard `Server_ServerStatus_State` node — value
`0` means "Running". If you see this, the dev stack is wired.

## Trust-store flow under Sail

The trust store lives at `storage/app/opcua/trust/`. Under Sail,
that's inside the `laravel.test` container but mapped to your
host:

<!-- @code-block language="bash" label="terminal — pin server" -->
```bash
# laravel-opcua does not ship opcua:trust:add — use opcua-cli.
./vendor/bin/sail bash -lc \
    'OPCUA_TRUST_STORE_PATH=storage/app/opcua/trust \
      vendor/bin/opcua-cli trust:add opc.tcp://opcua-test:4841'
```
<!-- @endcode-block -->

The cert lands in `storage/app/opcua/trust/<hash>.pem`. Both your
host and the Sail container see it — convenient for editing
during dev.

## Auto-publish in dev

For dev work on subscription / event flow:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_AUTO_PUBLISH=true
```
<!-- @endcode-block -->

Restart the daemon:

<!-- @code-block language="bash" label="terminal" -->
```bash
./vendor/bin/sail restart opcua-daemon
```
<!-- @endcode-block -->

Now `Opcua::subscribe()` fires Laravel events that your dev
listeners receive.

## Sail + Pest

Run tests as usual:

<!-- @code-block language="bash" label="terminal — test" -->
```bash
./vendor/bin/sail test                         # everything except Integration
./vendor/bin/sail test tests/Integration       # Integration with the live test server
```
<!-- @endcode-block -->

The integration tests target `opcua-test` automatically because
that's what `.env` points at.

## Sail + Reverb

For broadcasting dev:

<!-- @code-block language="text" label="docker-compose.yml" -->
```text
services:
  reverb:
    image: sail-8.4/app
    extends: laravel.test
    command: php /var/www/html/artisan reverb:start
    ports:
      - "${REVERB_PORT:-8080}:8080"
    volumes:
      - .:/var/www/html
    networks:
      - sail
```
<!-- @endcode-block -->

<!-- @code-block language="bash" label=".env" -->
```bash
REVERB_HOST=localhost              # browser-visible
VITE_REVERB_HOST=localhost
REVERB_PORT=8080
```
<!-- @endcode-block -->

Run:

<!-- @code-block language="bash" label="terminal — dev with broadcast" -->
```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev      # Vite
```
<!-- @endcode-block -->

Browse `http://localhost`. Real-time tag updates appear in the
browser as the test server's `CurrentTime` ticks (or whatever
tags you subscribe to).

## Hot-reloading the daemon

PHP doesn't hot-reload. After editing daemon-touching code, kill
and restart:

<!-- @code-block language="bash" label="terminal — restart" -->
```bash
./vendor/bin/sail restart opcua-daemon
```
<!-- @endcode-block -->

For listener changes, you don't need to restart the daemon —
listener resolution happens per-event from the application's
container, which gets re-bootstrapped each time.

## Tearing down

<!-- @code-block language="bash" label="terminal" -->
```bash
./vendor/bin/sail down -v          # -v drops volumes too
```
<!-- @endcode-block -->

For a clean slate including the trust store:

<!-- @code-block language="bash" label="terminal — full clean" -->
```bash
./vendor/bin/sail down -v
rm -rf storage/app/opcua/trust
```
<!-- @endcode-block -->

## CI parity

The same `docker-compose.yml` runs in CI. The CI workflow:

<!-- @code-block language="text" label=".github/workflows/test.yml" -->
```text
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: docker compose up -d
      - run: docker compose exec -T laravel.test composer install
      - run: docker compose exec -T laravel.test php artisan test
      - run: docker compose down
```
<!-- @endcode-block -->

Local dev and CI use the same fixtures — surprises are rare.

## Other Sail-compatible test servers

| Image                                                  | When                                   |
| ------------------------------------------------------ | -------------------------------------- |
| `ghcr.io/php-opcua/uanetstandard-test-suite:latest`    | Full coverage (8 endpoints)             |
| `ghcr.io/php-opcua/extra-test-suite:latest`            | open62541-backed, supports method calls |
| `mcr.microsoft.com/iotedge/opc-plc:latest`             | Microsoft's open-source PLC simulator   |

For most dev, the `uanetstandard-test-suite` is the right choice
— it exercises every OPC UA feature the package supports.

## Where to read next

- [Production deployment](./production-deployment.md) — same
  patterns, real iron.
- [Integration tests](../testing/integration-tests.md) — the
  full test-suite story.
