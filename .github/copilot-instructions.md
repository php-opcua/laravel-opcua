# OPC UA Laravel Client — Copilot Instructions

This repository contains `php-opcua/laravel-opcua`, a Laravel integration for the OPC UA PHP client.

## Project context

For a full understanding of this package, read these files in order:

1. **[llms.txt](../llms.txt)** — compact project summary: architecture, Facade, configuration, session manager
2. **[llms-full.txt](../llms-full.txt)** — comprehensive technical reference: every config key, method, DTO, event, trust store, managed client
3. **[llms-skills.md](../llms-skills.md)** — task-oriented recipes: install, read, write, browse, named connections, session manager, security, testing, events

## Architecture

```
HTTP Request
    │
    ▼
Opcua::connect()  (Facade → OpcuaManager)
    │
    ├── socket exists? → ManagedClient (IPC to session manager daemon)
    │
    └── socket missing? → ClientBuilder::create()->...->connect() (direct TCP)
                                │
                                ▼
                        OPC UA Server
```

## Key classes

- `src/OpcuaManager.php` — connection management, client creation, session manager detection
- `src/OpcuaServiceProvider.php` — service container registration (singleton, logger, cache injection)
- `src/Facades/Opcua.php` — static Facade with full PHPDoc for IDE autocompletion
- `src/Commands/SessionCommand.php` — Artisan `opcua:session` command for daemon

## Code conventions

- `declare(strict_types=1)` in every file
- Laravel idioms: service provider, Facade, `.env`-driven config, Artisan command
- Configuration keys in `snake_case`, following `config/database.php` patterns
- PHPDoc on every class and public method (`@param`, `@return`, `@throws`, `@see`)
- **No comments inside function bodies**
- Tests use Pest PHP (not PHPUnit)
- Integration tests grouped with `->group('integration')`
- Coverage target: 99.5%+

## Dependencies

- `php-opcua/opcua-client` ^4.0 — OPC UA client (required)
- `php-opcua/opcua-session-manager` ^4.0 — session persistence daemon (required)
- `illuminate/support`, `illuminate/console`, `illuminate/contracts` — Laravel framework
- `psr/event-dispatcher` ^1.0 — event dispatcher interface
