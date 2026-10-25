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
docker compose -f docker-compose.drivers.yml up -d --wait && composer test:drivers   # monitor vs real MySQL/PostgreSQL (PSF_DRIVERS=1); then `down -v`
composer analyse                                  # PHPStan level 1 (phpstan.neon); CI runs it too
./vendor/bin/pest tests/Unit/Model/MetadataCacheTest.php   # single file
./vendor/bin/pest --filter='maps properties'      # single test by name
./vendor/bin/pest --coverage

# Migrations (bin/psf = same wrapper as bin/phinx; NOT plain vendor/robmorgan/phinx -- the wrapper boots PSF first)
# Consumers: vendor/bin/psf. vendor/bin/phinx is the PSF wrapper only if Composer linked it (robmorgan/phinx declares a bin named phinx too).
./bin/psf migrate
./bin/psf migrate -e <env-name>                 # env name = key under config['db']
./bin/psf rollback
./bin/psf status
./bin/psf create NomeDaMigration
./bin/psf make:migration-from-model 'App\Models\Usuario'        # generates a migration from a model
./bin/psf make:model-from-table usuarios 'App\Models\Usuario'   # generates a model from a table
./bin/psf analyze:table-vs-model usuarios 'App\Models\Usuario'  # diff model vs DB
./bin/psf monitor:install [-e <db-key>]         # creates monitor tables (needs robmorgan/phinx)
./bin/psf monitor:status [-e <db-key>]          # installed? enabled? rollup watermark ("Rollup até")
./bin/psf monitor:rollup [-e <db-key>] [--max-hours N]    # aggregates complete hours into psf_monitor_rollups
./bin/psf monitor:maintain [-e <db-key>] [--max-hours N]  # rollup + prune (what the project schedules hourly)
./bin/psf monitor:prune [-e <db-key>]           # deletes raw rows past raw_days (watermark-aware, 4x ceiling) and aggregates past aggregate_days
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
2. `Router` constructor first calls `Monitor::boot()` (no-op unless `settings.monitor.enabled`, see Monitor section), then delegates to [src/http/RouteCacheManager.php](src/http/RouteCacheManager.php), which scans the controller dirs (from `config['settings']['controllers']`) for `#[Router(...)]` attributes via reflection and caches the route table in APCu (`psf_routes_v1`). In `prd` env the cache key is static; in `dev` it's invalidated by file mtime.
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
[`DialectFactory::fromConfig($configDb)`](src/database/Dialect/DialectFactory.php) returns the right [`DialectInterface`](src/database/Dialect/DialectInterface.php) (`MySQLDialect`, `PostgreSQLDialect`, `SQLServerDialect`, `SQLiteDialect`) for things that vary per RDBMS: DSN, identifier quoting, `listTablesQuery`, `LIMIT`/`OFFSET`, etc. **Anywhere a query is built, use the dialect — don't hardcode backticks or `LIMIT` syntax.**

`DBDriver` is a backed int enum, but legacy configs may pass the raw int; code that reads `$config['driver']` should tolerate both (see [phinx.php](phinx.php) for the canonical `DBDriver::tryFrom((int) $driver)` pattern).

### Phinx integration
[bin/phinx](bin/phinx) (also exposed as [bin/psf](bin/psf), a one-line delegate that avoids the Composer bin-name clash with robmorgan/phinx) walks up the directory tree from the consumer project, finds the consumer's autoloader and a bootstrap file (`bootstrap/app.php`, `config/bootstrap.php`, `bootstrap.php`, or `config.php`), defines `PSF_PROJECT_ROOT` (the consumer root; `ROOT` from settings.php points at `vendor/psf/framework` in the CLI), inits PSF with it and runs the Phinx application. Phinx ignores the `PHINX_CONFIG_FILE` constant and only looks for `phinx.*` in the cwd, so when the cwd has none the wrapper makes the framework's [phinx.php](phinx.php) the default of `--configuration` ([`PhinxCli`](src/database/PhinxCli.php)); path computations for CLI commands go through `PhinxCli::projectRoot()`, never `ROOT` directly. [phinx.php](phinx.php) translates `config['db']` entries into Phinx environments (Phinx cannot register commands from config, so [bin/phinx](bin/phinx) adds them itself with `addCommands()`): [`ModelAwareMigration`](src/database/Command/ModelAwareMigration.php) (`make:migration-from-model`), [`ModelGenerator`](src/database/Command/ModelGenerator.php) (`make:model-from-table`), [`TableAnalyzer`](src/database/Command/TableAnalyzer.php) (`analyze:table-vs-model`). Each command calls `parent::configure()` and `$this->bootstrap()`, and gets its adapter via `getManager()->getEnvironment(...)`. Migrations are written into the **consumer's** `db/migrations`, not the framework's.

### Monitor (observabilidade)
Opt-in via `config['settings']['monitor']`; docs in [docs/observabilidade.md](docs/observabilidade.md). Namespace `Psf\Monitor` in [src/monitor/](src/monitor/) (PSR-4 in `composer.json`). Records requests, SQL queries, exceptions and external calls (6 tables), aggregates them hourly (`Rollup`) and reads them (`Reader`); [`Api/MonitorApi`](src/monitor/Api/MonitorApi.php) is the optional JSON service (phase 4). Validated on SQLite plus real MySQL 8.4 and PostgreSQL 16 via `tests/Drivers`; SQL Server is still NOT validated (pending).
- [`Monitor`](src/monitor/Monitor.php): static facade (`boot`, `route`, `user`, `queryStart`/`queryEnd`, `exception`, `events`, `flush`, `requestId`, `reset`). Hooks live in [src/http/Router.php](src/http/Router.php) (`boot` in the constructor, `route` after matching / `(unmatched)` on 404, `user` after authentication, CORS expose of `X-Request-Id` in `applyCors`) and [src/http/Cors.php](src/http/Cors.php) (`expose_headers`). `flush()` runs at shutdown and never throws.
- Hooks in the CRUD shims ([Read](src/database/Read.php), [Create](src/database/Create.php) `exe`/`batch`, [Update](src/database/Update.php), [Delete](src/database/Delete.php)): `Monitor::queryStart()`/`queryEnd($start, $sql, $connection)` around `execute()`, one event per executed statement; `PDOException` also goes to `Monitor::exception()` (Read/Update/Delete). `Router::dispatchController` records the ORIGINAL exception (with its chain) on 5xx, not on 4xx/`HttpResponseException`. Hooks must stay cheap: they only buffer RAW SQL.
- [`Buffer`](src/monitor/Buffer.php): per-request in-memory events (queries capped by `max_events_per_request`, raw SQL cut at 8000 chars; 20 exceptions + the fatal, which ignores the cap; overflow counted as `dropped_events`; tracks max query ms for the sampler). [`SqlNormalizer`](src/monitor/SqlNormalizer.php): single-pass tokenizer (literals -> `?`, `IN (?)`, `VALUES (?)`, comments out, 2000 chars, sha1). [`ExceptionDescriber`](src/monitor/ExceptionDescriber.php): trace WITHOUT frame args, `getPrevious()` chain, size limits, `trace_hash` (throw site + top 3 frames as `Class::function`, no caller lines); `scrubMessage()` redacts values from `PDOException` (whole chain) and fatal messages (app messages kept unless `store_exception_message` is false); `fatal()` for `FatalError`. `Router::dispatchController` records `\Error`/`TypeError` and rethrows them unchanged. Normalization/description run only at flush, after `fastcgi_finish_request`. A fatal (read from `error_get_last()`) forces status >= 500.
- Tables (6, `Installer::TABLES`): `psf_monitor_requests`, `_queries`, `_exceptions`, `_external`, `_state`, `_rollups` (rollups filled by `Rollup`). Projects upgrading must run `monitor:install` (idempotent): without `_external` the batch of any request that had external calls fails and the breaker opens, and `monitor:rollup`/`prune`/`maintain` fail with "no such table".
- [`Config`](src/monitor/Config.php): defaults + `settings.monitor`, memoized. [`Sampler`](src/monitor/Sampler.php): weight or null. [`CircuitBreaker`](src/monitor/CircuitBreaker.php): APCu or temp-file flag. [`Sinks/DatabaseSink`](src/monitor/Sinks/DatabaseSink.php): multi-row INSERT in one transaction.
- [`Installer`](src/monitor/Installer.php) runs Phinx's `Manager` over [src/monitor/migrations/](src/monitor/migrations/) only (control table `psf_monitor_migrations`; these migrations are deliberately NOT in `phinx.php` `paths.migrations`). [`AutoMigrator`](src/monitor/AutoMigrator.php): opt-in, refused when environment is exactly `'prd'`, `flock`ed. [`Pruner`](src/monitor/Pruner.php) + `Command/Monitor{Install,Status,Prune,Rollup,Maintain}` (registered in [bin/phinx](bin/phinx)); [`CommandOptions`](src/monitor/Command/CommandOptions.php) validates `retention.*` (1..36500) and `--max-hours` (1..10000) BEFORE any write.
- **Deliberate exception to "don't add new CRUD pathways":** [`Connection::open()`](src/monitor/Connection.php) opens a dedicated PDO (not `Connect::getConnection()`) so the monitor does not inherit app transactions and is never instrumented by itself. Keep it that way and don't route other code through it.
- Phase 4 part A (external calls + API; contract in docs/observabilidade.md):
  - [`ExternalTarget`](src/monitor/ExternalTarget.php): the ONLY place that builds a `target` label (`fromUrl` = host only, lowercase, no scheme/userinfo/port/path/query, IPv6 without brackets; `smtp`; `storage` = `provider/operation`, lowercase; `(invalido)` on bad input; max 191 chars; never throws). Don't pass URLs, recipients, buckets or object keys anywhere else.
  - `Monitor::trackExternal($kind, $target, $call, ?$statusFrom)` / `external()` / `externalStart()` / `externalEnd()`: kinds `http|mail|storage`, never throw, no-op when disabled; `trackExternal` returns the call's result and rethrows its exact exception (recorded once, `status` null). Success without explicit status = 200; `external()` without status = error (null). Hooks: [Request](src/http/Request.php) `send`, [Http](src/http/Http.php) `request` (deprecated), [Email](src/utils/Email.php) `send`, [CloudStorage](src/utils/CloudStorage.php) (4 operations). Hooks must never change the original return/exception.
  - `Buffer::addExternal()`: separate cap (`max_events_per_request`, per event type; overflow in `dropped_events`) and `maxExternalMs()` tracked even for dropped calls, feeding `Sampler::weight(..., $maxExternalMs)` (last optional param; kept with weight 1 when >= `slow_ms.external`, default 1000). `Monitor::events()` also returns `'external'`.
  - Table `psf_monitor_external` (migration `20261026000001_psf_monitor_create_external.php`; `status` nullable); rollup metric `external` with `metric_key = kind:target` (error = `status IS NULL OR status >= 500`, 4xx is not an error); `Pruner::RAW_TABLES` has 4 tables; `Reader::external()` (kind/target split at the FIRST `:`) and the extra `external` key of `Reader::request()`.
  - [`Api/MonitorApi`](src/monitor/Api/MonitorApi.php): no routes; the project writes its own controller (`middlewares: ['authentication']`) and calls `handle($endpoint, $params)` returning `[message, data, status]` (200/404/422/500, never throws, never leaks DB errors). OFF by default (`settings.monitor.api.enabled`; disabled = 404 before touching the DB). Params are validated strictly (`from`/`to`, `limit` 1..1000, `order_by` whitelist, `id` regex); keep it that way.
- Phase 3 (aggregation/reading; semantics are a documented contract in docs/observabilidade.md):
  - [`Histogram`](src/monitor/Histogram.php): pure helper, 10 buckets with `EDGES = [10,25,50,100,250,500,1000,2500,5000]` (last is open), `encode/decode/merge/percentile` (interpolated ESTIMATES; open bucket uses `max_ms`; corrupt histogram = `empty()`, contributes nothing to a merged range). Never throws.
  - [`Watermark`](src/monitor/Watermark.php): `rollup_watermark` in `psf_monitor_state` (`read()` validates by round-trip and returns null only for missing row/invalid value/missing table — `42S02`/`42P01`/SQLite "no such table"; any other `PDOException` propagates so Rollup/Pruner stop instead of guessing; `write()` is UPDATE-then-INSERT, no vendor upsert; inside the caller's transaction the INSERT runs in a SAVEPOINT because PostgreSQL aborts the whole transaction on a duplicate key, except on SQL Server).
  - [`Rollup`](src/monitor/Rollup.php): `run($now, $maxHours = 48)` processes only COMPLETE hours + 300 s grace (`GRACE_SECONDS`), starting at the watermark or the oldest raw hour. Each hour = ONE transaction: aggregators (`aggregators()` list: requests/queries/exceptions/external; add new metrics there) -> `mergeByKey()` (truncates `metric_key` to 191 and merges keys equal after `mb_strtolower(rtrim())`, keeping the first spelling) -> DELETE+INSERT of the hour -> `Watermark::write`. Idempotent; weights via `sample_weight`; portable SQL only (no `MAX()` over text). With a watermark it first re-aggregates the previous `REAGGREGATE_HOURS = 2` hours (late rows; never older than the oldest raw hour or now − 1 day; hours with no raw rows skipped; not counted in `maxHours`/`hours`/`rows`; result key `reaggregated`). A new hour failing with SQLSTATE `23xxx` is rolled back and skipped (watermark advanced in its own transaction, result key `skipped`); other errors propagate. Every value bound to a datetime column goes through [`SqlDate::bind()`](src/monitor/SqlDate.php) (ISO `T` form only for `SQLServerDialect`; PHP-side values stay `Y-m-d H:i:s`).
  - [`Reader`](src/monitor/Reader.php): `routes/slowQueries/exceptions/external/throughput/request`. Half-open UTC hour range, `limit` clamped [1,1000], `orderBy` is a whitelist of fixed SQL expressions (never interpolate input), only `request()` reads RAW tables. Use `Dialect` for TOP/LIMIT.
  - Prune ceiling: raw cutoff = `max(min(now - raw_days, watermark), now - 4*raw_days)` (`Pruner::HARD_CEILING_FACTOR`): a long-stopped rollup loses hours instead of letting raw tables grow unbounded. `pruneAggregates()` deletes `bucket_hour < now - aggregate_days`. `monitor:maintain` = rollup then prune raw then aggregates; a rollup failure is printed, pruning still runs, exit code 1.
- Table schema is a documented **contract** (columns, `sample_weight`, aggregate rules); a change needs a new migration and a docs update.
- Test seams: `Config::fake(?array)` / `Config::reset()`; `Monitor::reset()`, `Monitor::useSink()`, `Monitor::useBreaker()`, `Monitor::useAutoMigrator()`, `Monitor::useFatalProvider()`, `Monitor::events()` (raw buffered events) (pass `null` to restore defaults). Helpers in [tests/Fixtures/monitor_helpers.php](tests/Fixtures/monitor_helpers.php): `monitorUseConfig($monitor, $top)` writes a temp SQLite-file config and runs `PSF::init`; `monitorCleanup()` runs automatically `afterEach` for `Unit/Monitor` and `Integration/Monitor`. `Monitor::reset()` does not unregister the shutdown function (the flush becomes a no-op without a new `boot()`).

### Test setup
`phpunit.xml` disables `apc.enable_cli` so APCu paths short-circuit during tests. `tests/bootstrap.php` calls `PSF::init()` with [tests/Fixtures/config.php](tests/Fixtures/config.php) — a placeholder MySQL config + a fixed JWT secret. Unit tests mock PDO; `tests/Integration` hits a real SQLite `:memory:` database (config in [tests/Fixtures/config.sqlite.php](tests/Fixtures/config.sqlite.php), tables created per test in [tests/Pest.php](tests/Pest.php)). `tests/bootstrap.php` defines `PSF_TESTING`, so `Http::response()` throws `HttpResponseException` instead of `exit`. Fixture models live in `tests/Fixtures/Models/` and use the `Tests\Fixtures\Models\` namespace (see `composer.json` `autoload-dev`). `tests/Drivers` (testsuite `Drivers`) runs the monitor against real MySQL/PostgreSQL from [docker-compose.drivers.yml](docker-compose.drivers.yml) only when `PSF_DRIVERS=1` (otherwise every test is skipped); each test gets a throwaway database via `driverUse($driver, ...)` and the `drivers` dataset ([tests/Fixtures/driver_helpers.php](tests/Fixtures/driver_helpers.php)); connection env vars `PSF_MYSQL_*`/`PSF_PGSQL_*`. Phinx 0.16 creates columns NULL by default, so monitor migrations always spell `'null' => false`.

## Documentation

Authoritative usage docs are in [docs/](docs/) — `guia.md` (how-to), `query-builder.md`, `models.md`, `database.md`, `rotas.md`, `helpers.md`, `exemplos.md`, `observabilidade.md` (monitor reference), `ROADMAP.md`, `melhorias.md` (changelog of v0.0.10 → v0.4.0 work, useful for understanding *why* a piece of code looks the way it does). Source comments and `docs/` are written in Brazilian Portuguese; match that when editing them.
