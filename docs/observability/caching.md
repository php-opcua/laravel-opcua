---
eyebrow: 'Docs · Observability'
lede:    'The package caches metadata, certificate validation, and per-server protocol features. What it caches, where, what to do when it goes wrong.'

see_also:
  - { href: './logging.md',                         meta: '5 min' }
  - { href: '../configuration/config-file.md',      meta: '6 min' }
  - { href: '../security/trust-store.md',           meta: '6 min' }

prev: { label: 'Logging',           href: './logging.md' }
next: { label: 'Debugging',         href: './debugging.md' }
---

# Caching

The package uses Laravel's cache (`CacheInterface` / PSR-16) for
two purposes:

1. **Metadata caching** — node attribute reads, browse results.
2. **Protocol state** — per-server chunk sizes, certificate
   validation outcomes, trust-store fingerprints.

Both are entirely optional. With caching off, the package issues
extra network calls but works correctly.

## Choice of cache store

In `config/opcua.php`:

<!-- @code-block language="php" label="cache store" -->
```php
'session_manager' => [
    'cache_store' => env('OPCUA_CACHE_STORE'),
],
```
<!-- @endcode-block -->

In the published config `OPCUA_CACHE_STORE` falls back to
`CACHE_STORE`, which in turn defaults to `'file'`. So the actual
effective default is `'file'` unless your `.env` says otherwise.

Recommended stores:

| Store      | When                                                       |
| ---------- | ---------------------------------------------------------- |
| `redis`    | Multi-process apps (FPM, Octane, Horizon). The canonical choice. |
| `database` | When Redis isn't an option                                  |
| `file`     | Single-process dev / staging                                |
| `array`    | Tests (in-memory, no leak across tests with refresh)        |

## What gets cached

### Node metadata

Reads of non-Value attributes (`DisplayName`, `Description`,
`DataType`, `NodeClass`, etc.) are cached when
`read_metadata_cache` is `true` in the connection config:

<!-- @code-block language="php" label="enable metadata cache" -->
```php
'connections' => [
    'default' => [
        'endpoint'             => '...',
        'read_metadata_cache'  => true,
    ],
],
```
<!-- @endcode-block -->

Cache keys are managed by the underlying `opcua-client` library
and are not part of this package's public surface — don't depend
on a particular key shape. The Value attribute is **never**
cached: values must be live.

### Browse results

`browseRecursive()` can cache its tree:

<!-- @code-block language="php" label="manual browse cache" -->
```php
$tree = Cache::remember(
    "opcua-browse:plc-line-a:{$root}",
    minutes: 60,
    callback: fn() => Opcua::connection('plc-line-a')
        ->browseRecursive($root, maxDepth: 10),
);
```
<!-- @endcode-block -->

The package doesn't auto-cache browse — too varied across use
cases. Do it explicitly in application code.

### Certificate validation

`opcua-client` validates each cert once per (fingerprint + trust
store state) and caches the result internally. The Laravel
package doesn't manage these keys directly — see the upstream
[`opcua-client` docs](https://github.com/php-opcua/opcua-client)
for the underlying behaviour.

### Per-server protocol features

After the first connection to a server, `opcua-client` caches
the negotiated chunk size, the server's capabilities, and the
server's product info — short-circuiting subsequent handshakes.
The Laravel package just hands a PSR-16 cache to the underlying
builder; the key layout belongs to `opcua-client`.

## Cache TTLs

The Laravel package does **not** read a `cache_ttl` per-connection
config block. Cache TTLs are owned by `opcua-client` and configured
via its builder surface — not exposed as keys in
`config/opcua.php`.

## Flushing

When something looks wrong with cached state, flush:

<!-- @code-block language="bash" label="terminal — full flush" -->
```bash
php artisan cache:clear            # all Laravel cache
```
<!-- @endcode-block -->

For targeted clearing without touching unrelated Laravel cache,
use a dedicated cache store:

<!-- @code-block language="php" label="config/cache.php" -->
```php
'stores' => [
    'opcua' => [
        'driver'     => 'redis',
        'connection' => 'opcua',  // dedicated Redis db
    ],
],
```
<!-- @endcode-block -->

…then `php artisan cache:clear --store=opcua`. The application's
main cache is untouched.

## When NOT to cache

- **Live values.** Already mentioned. Never cache `read()` Value
  results.
- **Per-request browse results.** If the operator is browsing a
  device's structure interactively, fresh browse is what they
  want.
- **Cert validation during initial trust-store setup.** Disable
  metadata caching while configuring certificates — a stale
  validation result will block you for the duration of the TTL.

## Cache as a leak vector

Two failure modes worth understanding:

### 1 — Stale validation after cert rotation

You rotated the server cert. `opcua-client`'s cached validation
for the old fingerprint may still be present until its internal
TTL expires. The new cert triggers fresh validation independently.
Not actually broken — just wastes a little cache space.

### 2 — Trust-store changes propagating

If you add a PEM to `trust_store_path/` outside the
`Opcua::trustCertificate()` API, downstream caches may take a
moment to notice. The `trustCertificate()` / `untrustCertificate()`
facade calls invalidate the relevant entries on the spot.

## Octane / FrankenPHP — long-process cache

In Octane workers, an array-driver cache **persists across
requests**, which is a desirable speedup. The package's metadata
and protocol caches are happy with this.

The same applies to **request scope leakage**: an Octane worker
that opens a connection caches results that the next request
reuses. Use named connections to keep cache identity stable —
`connectTo()`-driven ad-hoc configs each get a fresh cache entry.

## Multi-instance coordination

Multi-server deployments (multiple FPM hosts behind a load
balancer) need Redis for cache coherence:

| Topology                     | Cache store     | Coherent?           |
| ---------------------------- | --------------- | ------------------- |
| Single host                  | `file`          | Yes                 |
| Single host                  | `redis` (local) | Yes                 |
| Multi-host                   | `file`          | No — each host has its own cache |
| Multi-host                   | `redis` (shared) | Yes                |
| Multi-host                   | `database`      | Yes                 |

Use shared Redis for multi-host. The session manager daemon
typically runs as a singleton — no coordination needed at the
daemon layer.

## Cache size

Per-server cached state is ~1 KB. With 1000 cached metadata
entries, you're at 1 MB. With 100 000 entries, 100 MB. Set
`MAXMEMORY_POLICY=allkeys-lru` on Redis to prevent unbounded
growth.

## Where to read next

- [Debugging](./debugging.md) — when cache makes diagnosis
  harder.
- [Security · Trust store](../security/trust-store.md) — the
  cache layer over the trust store.
