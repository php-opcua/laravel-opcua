# Roadmap

> **A note on versioning:** We're aware of the rapid major releases in a short time frame. This library is under active, full-time development right now — the goal is to reach a production-stable state as quickly as possible. Breaking changes are being bundled and shipped deliberately to avoid dragging them out across many minor releases. Once the API surface settles, major version bumps will become rare. Thanks for your patience.

## v4.0.0

### Features

- [ ] Health check endpoint — `Opcua::health()` returning connection state, daemon status, latency, and session count for monitoring dashboards
- [ ] Artisan commands — `opcua:browse`, `opcua:read`, `opcua:write` for quick debugging from the terminal without writing code
- [ ] Queue integration — `OpcuaReadJob`, `OpcuaWriteJob` for dispatching OPC UA operations to Laravel queues, with automatic retry and connection management
- [ ] Event broadcasting — fire Laravel events on subscription notifications (e.g. `OpcuaDataChanged`, `OpcuaEventReceived`) so listeners and broadcasting channels work out of the box
- [ ] Blade/Livewire component — `<x-opcua-value node="ns=2;i=1001" />` for real-time PLC value display in dashboards
- [ ] Horizon-style dashboard — web-based monitoring UI for active sessions, connection health, operation history, and daemon status
- [ ] Symfony integration — Symfony bundle wrapping the client with service container, config, and console commands (`php-opcua/symfony-opcua`)

### Improvements

- [ ] Config validation — validate `config/opcua.php` at boot time and throw clear exceptions for invalid security policy names, unreachable socket paths, or missing required keys
- [ ] Connection pooling — warm up named connections on provider boot (opt-in) to eliminate the first-request latency hit
- [ ] Middleware — `EnsureOpcuaConnected` middleware that guarantees a live connection for the duration of the request and disconnects in `terminate()`
- [ ] Lazy proxy — return a lazy proxy from `connection()` that defers the actual TCP/IPC handshake until the first operation, reducing boot overhead when the connection may not be needed

## Won't Do (by design)

### Merge opcua-client or session-manager code

This package is a thin Laravel wrapper. It delegates all OPC UA protocol work to `opcua-client` and all session persistence to `opcua-session-manager`. It will never reimplement or duplicate their logic. New OPC UA features are automatically available through the `__call()` proxy and Facade PHPDoc updates — no code changes in this package beyond documentation.

### Custom Cache / Logger Implementations

This package uses Laravel's bound `Psr\Log\LoggerInterface` and `Psr\SimpleCache\CacheInterface`. It will not ship its own cache drivers or logger implementations — that's what `config/logging.php` and `config/cache.php` are for. The underlying `opcua-client` provides `InMemoryCache` and `FileCache` for use cases outside Laravel.

### Eloquent Models / Database Integration

OPC UA data is real-time process data, not relational data. Mapping it to Eloquent models would create a misleading abstraction. If you need to persist OPC UA values to a database, read the values and store them yourself — the two concerns should remain separate.

### WebSocket / SSE Broadcasting of OPC UA Subscriptions

While OPC UA subscriptions produce real-time data, bridging them to Laravel Broadcasting (Pusher, Ably, WebSockets) requires a long-running worker process that holds the subscription open. This is better implemented in application code tailored to your specific use case (which nodes, what format, which channel) rather than as a generic package feature. The session manager daemon already keeps subscriptions alive — your worker just needs to call `publish()` in a loop.

---

Have a suggestion? Open an [issue](https://github.com/php-opcua/laravel-opcua/issues) or check the [contributing guide](CONTRIBUTING.md).
