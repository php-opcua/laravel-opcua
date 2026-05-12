---
eyebrow: 'Docs · Getting started'
lede:    'Version policy: major and minor track opcua-client. Upgrades within v4 are non-breaking by design; the CHANGELOG calls out anything that needs attention.'

see_also:
  - { href: 'https://github.com/php-opcua/laravel-opcua/blob/master/CHANGELOG.md', meta: 'external', label: 'CHANGELOG' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/CHANGELOG.md', meta: 'external', label: 'opcua-client CHANGELOG' }
  - { href: '../configuration/publishing-overriding.md', meta: '4 min' }

prev: { label: 'How laravel-opcua fits', href: './how-laravel-opcua-fits.md' }
next: { label: 'The config file',        href: '../configuration/config-file.md' }
---

# Upgrading

`laravel-opcua` follows `opcua-client`'s release cadence:
major.minor versions stay in sync; patches are independent.

## The lockstep

| `opcua-client` | `laravel-opcua` | Laravel versions supported       |
| -------------- | --------------- | -------------------------------- |
| `4.0.x`        | `4.0.x`         | 11.x                              |
| `4.1.x`        | `4.1.x`         | 11.x                              |
| `4.2.x`        | `4.2.x`         | 11.x, 12.x                        |
| `4.3.x`        | `4.3.x`         | 11.x, 12.x, 13.x                  |

The Composer constraint `^4.3` accepts every patch. Same on
`opcua-client` — both packages share the same major/minor.

## Same-minor upgrades (`4.3.0 → 4.3.1`)

Composer pulls the new version automatically with
`composer update`. No code changes, no config changes, no
artisan steps.

<!-- @code-block language="bash" label="terminal" -->
```bash
composer update php-opcua/laravel-opcua
php artisan config:clear
```
<!-- @endcode-block -->

The `config:clear` step is precautionary — same-minor changes
don't touch the config schema, but it ensures Laravel re-reads
on the next request if you had a stale cache.

## Cross-minor upgrades (`4.2 → 4.3`)

Two scripted steps and a CHANGELOG read.

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/laravel-opcua:^4.3
php artisan config:clear
```
<!-- @endcode-block -->

Then check both CHANGELOGs:

- [`laravel-opcua` CHANGELOG](https://github.com/php-opcua/laravel-opcua/blob/master/CHANGELOG.md)
  — Laravel-specific changes (new config keys, new events,
  facade additions).
- [`opcua-client` CHANGELOG](https://github.com/php-opcua/opcua-client/blob/master/CHANGELOG.md)
  — underlying-library changes (often non-visible at the
  Laravel layer, but worth knowing).

### New config keys

A new minor often adds config keys. The package merges its
defaults with your `config/opcua.php`, so your published config
still works — the new keys take their defaults.

To explicitly adopt new keys in your published file:

<!-- @code-block language="bash" label="terminal — republish" -->
```bash
php artisan vendor:publish --tag=opcua-config --force
```
<!-- @endcode-block -->

`--force` overwrites your file. Diff it against version control
to merge your customisations back in. Or copy the new keys by
hand from the package's `config/opcua.php`.

### New environment variables

Same — defaults work. Add the new variables to `.env` only when
you want to override their defaults. The
[Environment variables](../configuration/environment-variables.md)
page is kept current.

## Cross-major upgrades (`3.x → 4.0`)

Major versions can change shape. Read the migration notes in the
CHANGELOG before upgrading, expect:

- **Config schema changes**. Republish, merge.
- **Facade signature shifts**. Affects code that uses the
  facade — IDE autocomplete usually catches these at edit time.
- **Event class renames**. Listeners may need updating.
- **Dependency-version bumps**. Composer's solver enforces
  these.

The CHANGELOG carries a per-major migration section when needed.
Recent majors (v4.0 from v3) are documented; older transitions
are out of band.

## Laravel version upgrades

The package supports multiple Laravel versions simultaneously
(11, 12, 13 at this writing). Upgrading Laravel itself doesn't
require a `laravel-opcua` change as long as the new Laravel is
in the supported set.

When a new Laravel major lands (e.g. 14.x in the future):

1. The package adds support in a new minor — typically
   non-breaking, additive.
2. The previous Laravel version stays supported until the
   following major of `laravel-opcua`.

Plan ahead: if your Laravel is one major behind, plan to bump
Laravel **first**, then `laravel-opcua`, never the other way
around.

## After upgrading

Run the test suite. The integration with `laravel-opcua` is at
the controller / service layer; your tests cover that interface.
A passing suite is the strongest signal nothing regressed.

<!-- @code-block language="bash" label="terminal — verify" -->
```bash
php artisan test
# or
vendor/bin/pest
```
<!-- @endcode-block -->

For dependency-only upgrades (no config changes), the test
output should be identical pre- vs post-upgrade.

## Common gotchas

### "Class not found" after upgrade

Composer's autoload cache is stale. Refresh:

<!-- @code-block language="bash" label="terminal — autoload refresh" -->
```bash
composer dump-autoload
```
<!-- @endcode-block -->

### Daemon won't restart cleanly

When upgrading `opcua-session-manager` (transitive dependency),
the daemon's wire protocol may have changed. If you have the
daemon running as a systemd service, restart it after the
Composer install:

<!-- @code-block language="bash" label="terminal — restart" -->
```bash
sudo systemctl restart opcua-session-manager
```
<!-- @endcode-block -->

The daemon's `--version` flag exposes the running version. Match
it against the package version to verify.

### Octane's worker memory after upgrade

Under [Octane](../integrations/octane-and-frankenphp.md),
long-lived workers cache `OpcuaManager` for their lifetime. After
upgrade, restart workers to pick up the new code:

<!-- @code-block language="bash" label="terminal — Octane restart" -->
```bash
php artisan octane:reload
```
<!-- @endcode-block -->

### Cache invalidation

OPC UA browse / endpoints caches persist in your configured
Laravel cache store. If a new minor changes the cache codec
(rare but possible — `opcua-client` v4.3 changed it once), flush
the OPC UA-related entries:

<!-- @code-block language="bash" label="terminal — flush cache" -->
```bash
# All cache (heavy-handed)
php artisan cache:clear

# Or programmatically, just the OPC UA entries
php artisan tinker
>>> Opcua::flushCache();
```
<!-- @endcode-block -->

See [Observability · Caching](../observability/caching.md).

## When to upgrade

- **Patches** — always, on the next regular Composer update
  cycle. Patches are bug fixes and small security improvements.
- **Minors** — within a few weeks. Minors add features without
  breaking existing code.
- **Majors** — plan a dedicated window. Read the migration
  notes, run the full test suite, watch for production
  surprises.

The CHANGELOG marks security releases explicitly; treat those
as priority.
