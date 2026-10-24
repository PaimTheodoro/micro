# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository purpose

PSF Micro-Framework PHP — a Composer library (`psf/framework`) for building APIs. It is consumed by other projects, so all public APIs must stay backwards-compatible. PHP 8.1+ (enums), optional APCu for caching. Drivers: MySQL, PostgreSQL, SQL Server, SQLite.

## Common commands

```bash
# Tests (Pest v3, engine = PHPUnit; bootstrap inits PSF with tests/Fixtures/config.php)
./vendor/bin/pest                                 # all tests
./vendor/bin/pest --testsuite=Unit                # unit only
./vendor/bin/pest --testsuite=Integration         # SQLite :memory: (no DB server needed); schema built in tests/Pest.php
composer analyse                                  # PHPStan level 1 (phpstan.neon); CI runs it too
./vendor/bin/pest tests/Unit/Model/MetadataCacheTest.php   # single file
./vendor/bin/pest --filter='maps properties'      # single test by name
./vendor/bin/pest --coverage

# Migrations (uses bin/phinx wrapper, NOT vendor/bin/phinx — wrapper boots PSF first)
./bin/phinx migrate
./bin/phinx migrate -e <env-name>                 # env name = key under config['db']
./bin/phinx rollback
./bin/phinx status
./bin/phinx create NomeDaMigration
./bin/phinx make:migration-from-model 'App\Models\Usuario'        # generates a migration from a model
./bin/phinx make:model-from-table usuarios 'App\Models\Usuario'   # generates a model from a table
./bin/phinx analyze:table-vs-model usuarios 'App\Models\Usuario'  # diff model vs DB
```

There is no build step and no linter configured. `composer install` is the only setup command.

## Big-picture architecture

### PSF singleton + global config
`settings.php` (auto-loaded by Composer's `files`) defines:
- The global `PSF` class with `init(['config' => path])`, `getConfig()`, and `config(...$keys)` accessors.
- Constants `DR`, `ROOT`, and `Psf` (an instance of `PSF`).
- Spatie Ignition error pages.

Everything in the framework reads config via `\PSF::getConfig()->...` or `\PSF::config('db', 'default', 'driver')`. **Tests and the Phinx wrapper must call `PSF::init([...])` before any framework code runs** — see [tests/bootstrap.php](tests/bootstrap.php) and [bin/phinx](bin/phinx).

### Request lifecycle
1. Consumer's `public/index.php` instantiates `\Psf\Http\Router(...$controllerClasses)` and calls `handle()`.
2. `Router` constructor delegates to [src/http/RouteCacheManager.php](src/http/RouteCacheManager.php), which scans the controller dirs (from `config['settings']['controllers']`) for `#[Router(...)]` attributes via reflection and caches the route table in APCu (`psf_routes_v1`). In `prd` env the cache key is static; in `dev` it's invalidated by file mtime.
3. URL routing is parameterized: `{name:type}` with built-in patterns `int`, `string`, `slug`, `uuid4`, plus arbitrary regex `{cod:/^.../}` (see `Router::$patterns`).
4. Middlewares are configured through `config['settings']`: `authentication` calls `verifyauth` (returns user → stored in `Router::$auth`), `loggin` calls `logrequest`. Adding a new middleware = adding a config key + handling it in [src/http/Router.php](src/http/Router.php).
5. Controllers extend [`Psf\Model\ControllerBase`](src/model/ControllerBase.php). `$this->data` (parsed body), `$this->token` (Bearer), `$this->method` are populated via [`RequestParser`](src/http/RequestParser.php) in `__construct`.
6. Responses use `Http::response($message, $data, $status, $headers)` — **calls `exit`**, so anything after it never runs (and tests must wrap it with `ob_start()` + a try/catch).

### Models, attributes, and the metadata cache
Models extend [`Psf\Model\Model`](src/model/Model.php) and `use ModelTrait` to expose `find()` / `findById()`. Schema is declared with PHP attributes from `Psf\Model\Attributes\*`: `Table`, `Database`, `Column`, `PrimaryKey`, `Type`, `Nullable`, `Standard`, `ColumnCreatedDate`, `ColumnUpdatedDate`, `ColumnDeletedDate`, `Enum`.

[`MetadataCache`](src/model/MetadataCache.php) is a two-tier cache (L1 static array for the request, L2 APCu cross-request) keyed by class name. **Always go through `MetadataCache::getColumnMap()` / `getTable()` / `getDatabase()` instead of calling Reflection directly** — direct reflection bypasses the cache and is hot-path-sensitive. Tests must call `MetadataCache::clearCache()` in `beforeEach` when fixture models change.

Persistence goes through the thin CRUD shims in [src/database/](src/database/): `Connect`, `Create`, `Read`, `Update`, `Delete` (all use prepared statements). Don't add new CRUD pathways — extend these.

### Query builder pipeline
`Modelo::find()` (via `ModelTrait`) → [`ModelQuery`](src/model/ModelQuery.php) → [`QueryBuilder`](src/model/QueryBuilder.php) → SQL string → `Read::exe()`. Hydration of fetched rows into model instances (including `leftJoinAndSelect` 1:N relations) happens in [`ModelHydrator`](src/model/ModelHydrator.php) / [`QueryHydrator`](src/model/QueryHydrator.php). Serialization out (`toArray()`) lives in [`ModelSerializer`](src/model/ModelSerializer.php). These are an explicit split — keep responsibilities separate when modifying.

All entity/column references (where array/string, join `ON`, orderBy, groupBy, fields, expressions) go through [`ReferenceResolver`](src/model/ReferenceResolver.php) — **don't add another translator**. QueryBuilder defers resolution (`compileField()` returns a marker, `finalize()`/`writeQuery()` resolve it) so call order doesn't matter; use `generateField()` only when you need the resolved string immediately. Internal filters (soft delete) use bare property names, which always mean the root entity.

`QueryBuilder::$allowedSqlFunctions` is a hardcoded allow-list for SQL function calls in expressions; SQL injection has been an issue here historically, so widen it carefully.

### Database dialects
[`DialectFactory::fromConfig($configDb)`](src/database/Dialect/DialectFactory.php) returns the right [`DialectInterface`](src/database/Dialect/DialectInterface.php) (`MySQLDialect`, `PostgreSQLDialect`, `SQLServerDialect`) for things that vary per RDBMS: DSN, identifier quoting, `listTablesQuery`, `LIMIT`/`OFFSET`, etc. **Anywhere a query is built, use the dialect — don't hardcode backticks or `LIMIT` syntax.**

`DBDriver` is a backed int enum, but legacy configs may pass the raw int; code that reads `$config['driver']` should tolerate both (see [phinx.php](phinx.php) for the canonical `DBDriver::tryFrom((int) $driver)` pattern).

### Phinx integration
[bin/phinx](bin/phinx) walks up the directory tree from the consumer project, finds the consumer's autoloader and a bootstrap file (`bootstrap/app.php`, `config/bootstrap.php`, `bootstrap.php`, or `config.php`), inits PSF with it, then sets `PHINX_CONFIG_FILE` to the framework's [phinx.php](phinx.php) and runs the Phinx application. [phinx.php](phinx.php) translates `config['db']` entries into Phinx environments (Phinx cannot register commands from config, so [bin/phinx](bin/phinx) adds them itself with `addCommands()`): [`ModelAwareMigration`](src/database/Command/ModelAwareMigration.php) (`make:migration-from-model`), [`ModelGenerator`](src/database/Command/ModelGenerator.php) (`make:model-from-table`), [`TableAnalyzer`](src/database/Command/TableAnalyzer.php) (`analyze:table-vs-model`). Each command calls `parent::configure()` and `$this->bootstrap()`, and gets its adapter via `getManager()->getEnvironment(...)`. Migrations are written into the **consumer's** `db/migrations`, not the framework's.

### Test setup
`phpunit.xml` disables `apc.enable_cli` so APCu paths short-circuit during tests. `tests/bootstrap.php` calls `PSF::init()` with [tests/Fixtures/config.php](tests/Fixtures/config.php) — a placeholder MySQL config + a fixed JWT secret. Unit tests mock PDO; `tests/Integration` hits a real SQLite `:memory:` database (config in [tests/Fixtures/config.sqlite.php](tests/Fixtures/config.sqlite.php), tables created per test in [tests/Pest.php](tests/Pest.php)). `tests/bootstrap.php` defines `PSF_TESTING`, so `Http::response()` throws `HttpResponseException` instead of `exit`. Fixture models live in `tests/Fixtures/Models/` and use the `Tests\Fixtures\Models\` namespace (see `composer.json` `autoload-dev`).

## Documentation

Authoritative usage docs are in [docs/](docs/) — `guia.md` (how-to), `query-builder.md`, `models.md`, `database.md`, `rotas.md`, `helpers.md`, `melhorias.md` (changelog of v0.0.10 → v0.4.0 work, useful for understanding *why* a piece of code looks the way it does). Source comments and `docs/` are written in Brazilian Portuguese; match that when editing them.
