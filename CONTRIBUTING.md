# Contributing to OPC UA Laravel Client

## Welcome!

Thank you for considering contributing to this project! Every contribution matters, whether it's a bug report, a feature suggestion, a documentation fix, or a code change. This project is open to everyone, you're welcome here.

If you have any questions or need help getting started, don't hesitate to open an issue. We're happy to help.

## Development Setup

### Requirements

- PHP >= 8.2
- `ext-openssl`
- Composer
- Laravel 11.x or 12.x (for integration context)
- [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) (for integration tests)

### Installation

```bash
git clone https://github.com/php-opcua/laravel-opcua.git
cd laravel-opcua
composer install
```

### Test Server

Integration tests require the OPC UA test server suite running locally:

```bash
git clone https://github.com/php-opcua/uanetstandard-test-suite.git
cd uanetstandard-test-suite
docker compose up -d
```

## Running Tests

```bash
# All tests
./vendor/bin/pest

# Unit tests only
./vendor/bin/pest tests/Unit/

# Integration tests only
./vendor/bin/pest tests/Integration/ --group=integration

# A specific test file
./vendor/bin/pest tests/Unit/OpcuaManagerTest.php

# With coverage report
php -d pcov.enabled=1 ./vendor/bin/pest --coverage
```

All tests must pass before submitting a pull request.

## Project Structure

```
src/
├── OpcuaManager.php           # Connection management, client creation, configuration
├── OpcuaServiceProvider.php   # Service container registration (singleton, logger, cache)
├── Facades/
│   └── Opcua.php              # Static Facade with full PHPDoc for IDE autocompletion
└── Commands/
    └── SessionCommand.php     # Artisan command for session manager daemon

config/
└── opcua.php                  # Default connection, session manager, per-connection settings

doc/                           # Detailed documentation (9 files)

tests/
├── Unit/                      # Unit tests (no server or daemon required)
│   ├── ConfigTest.php
│   ├── FacadeTest.php
│   ├── MockClientTest.php
│   ├── OpcuaManagerTest.php
│   └── OpcuaServiceProviderTest.php
└── Integration/               # Integration tests (require test server + daemon)
    ├── Helpers/
    │   └── TestHelper.php     # Endpoints, config builders, daemon lifecycle
    ├── ConnectionTest.php
    ├── ReadTest.php
    ├── WriteTest.php
    ├── BrowseTest.php
    ├── ...                    # 20+ test files covering all features
    └── ErrorHandlingTest.php
```

## Design Principles

### Laravel Conventions

This package follows Laravel idioms: service provider for registration, Facade for static access, `.env`-driven configuration, Artisan command for the daemon. Configuration keys follow `snake_case`. Named connections work like `config/database.php`.

### Transparent Session Management

`OpcuaManager` checks for the daemon's Unix socket. If present, a `ManagedClient` is created and configured via `configureManagedClient()`. If not, a `ClientBuilder` is created, configured via `configureBuilder()`, and `connect()` returns a connected `Client`. Application code should never need to care which mode it's in.

### Automatic Logger & Cache Injection

The `OpcuaServiceProvider` resolves `Psr\Log\LoggerInterface`, `Psr\SimpleCache\CacheInterface`, and `Psr\EventDispatcher\EventDispatcherInterface` from the Laravel container and passes them to `OpcuaManager`. Every client gets these defaults via `configureBuilder()` (direct mode) or `configureManagedClient()` (managed mode). Explicit per-connection overrides take precedence.

### Thin Wrapper

This package wraps `php-opcua/opcua-client` and `php-opcua/opcua-session-manager` — it should not reimplement, duplicate, or override their behavior. New OPC UA features are exposed via the Facade PHPDoc and proxied through `OpcuaManager::__call()`.

## Guidelines

### Code Style

- Follow the existing code style and conventions
- Use strict types (`declare(strict_types=1)`)
- Use type declarations for parameters, return types, and properties
- Keep methods focused and concise

### Documentation & Comments

- Every class, trait, interface, and enum must have a PHPDoc description
- Every public method must have a PHPDoc block with `@param`, `@return`, `@throws`, and `@see` where applicable
- `@return` and `@param` must be on their own line, not inline with the description
- **Do not add comments inside function bodies.** No `//`, no `/* */`, no section headers. If the code needs a comment to be understood, the method is too complex — split it into smaller, well-named methods instead. The method name and its PHPDoc should be enough to understand what it does.
- Update relevant files in `doc/` for new features
- Update `CHANGELOG.md` with your changes
- Update `README.md` features list if adding a major feature
- Update `llms.txt` and `llms-full.txt` if the change affects the public API or architecture

### OpcuaManager Changes

- Direct mode uses `configureBuilder()`, managed mode uses `configureManagedClient()` — add new settings at the end of both methods
- Logger, cache, and event dispatcher injection follow a two-tier priority: explicit config key > default from service provider
- New configuration keys must have a corresponding `.env` variable in `config/opcua.php`

### Facade PHPDoc

- Any new method on `OpcUaClientInterface` must be added to the `Opcua` Facade's PHPDoc `@method` annotations
- Include full parameter types, return types, and default values for IDE autocompletion
- Import all referenced types at the top of the file

### SessionCommand Changes

- New daemon options must be added to both the Artisan signature and the `handle()` method
- Use Laravel's `app('log')->channel()` and `app('cache')->store()` for daemon dependencies
- Display new options in the startup table

### Testing

- Write unit tests for all new functionality
- Write integration tests for features that interact with an OPC UA server via the daemon
- Use Pest PHP syntax (not PHPUnit)
- Group integration tests with `->group('integration')`
- Use `TestHelper::safeDisconnect()` in `finally` blocks
- Use `TestHelper::startDaemon()` / `TestHelper::stopDaemon()` in `beforeAll` / `afterAll`
- Test both direct and managed modes using the `foreach` loop pattern in integration tests
- Use `MockClient` for unit tests that need an `OpcUaClientInterface` without a real server
- **Code coverage must remain at or above 99.5%.** Pull requests that drop coverage below this threshold will not be merged. Run `php -d pcov.enabled=1 ./vendor/bin/pest --coverage` to check locally before submitting.

### Commits

- Use descriptive commit messages
- Prefix with `[ADD]`, `[UPD]`, `[PATCH]`, `[REF]`, `[DOC]`, `[TEST]` as appropriate

## Pull Request Process

1. Fork the repository and create a feature branch
2. Write your code and tests
3. Ensure all tests pass and coverage is >= 99.5%
4. Update documentation, changelog, and llms files
5. Submit a pull request
6. Wait for review — a maintainer will review your PR, may request changes or ask questions
7. Once approved, your PR will be merged

## Reporting Issues

Use the [issue tracker](https://github.com/php-opcua/laravel-opcua/issues) to report bugs, request features, or ask questions.
