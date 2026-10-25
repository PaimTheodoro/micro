# Observabilidade — Fase 1 (Núcleo + requests) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar o núcleo do monitor: cada request é medido, amostrado e gravado (sem I/O durante o request) numa tabela do banco do projeto, com `X-Request-Id`, circuit breaker, `auto_migrate`, instalação via Phinx e prune por retenção.

**Architecture:** `Psf\Monitor\Monitor` (fachada estática) acumula o contexto do request em memória e, no `register_shutdown_function` (após `fastcgi_finish_request()`), decide a amostragem (`Sampler`) e grava via `DatabaseSink` numa conexão PDO dedicada, protegida por `CircuitBreaker`. As tabelas vêm de migrations Phinx em `src/monitor/migrations/`, aplicadas por `Installer` (comando `monitor:install` ou `AutoMigrator`). Tudo é no-op quando `settings.monitor.enabled` não é verdadeiro.

**Tech Stack:** PHP 8.1+, PDO (MySQL/PostgreSQL/SQL Server/SQLite), Phinx 0.16 (Manager), Symfony Console, Pest 3, PHPStan nível 1.

**Spec:** `docs/superpowers/specs/2026-10-24-observabilidade-design.md` (esta fase cobre a "Fase 1"; queries, exceções, rollups, `Reader` e chamadas externas ficam para os planos das fases 2–4).

## Global Constraints

- PHP `>=8.1`; sem mudar nenhuma assinatura pública existente; ganchos são no-op quando o monitor está desligado.
- Namespace `Psf\Monitor\` → `src/monitor/` (PSR-4 em `composer.json`).
- Comentários, mensagens de CLI e `docs/` em **português do Brasil**.
- Timestamps em **UTC** (`gmdate('Y-m-d H:i:s')`); durações em ms (`DECIMAL(10,2)`); tabelas com prefixo `psf_monitor_`.
- Todo identificador SQL do monitor passa por `DialectInterface::quoteIdentifier()`; nada de backticks nem `LIMIT` fixos.
- Nunca gravar body, headers, cookies, token, query string ou valores de bindings.
- Defaults: `sample.normal=0.1`, `slow_ms.request=1000`, `slow_ms.query=200`, `max_events_per_request=200`, `retention.raw_days=7`, `retention.aggregate_days=90`, `breaker.failures=3`, `breaker.cooldown_seconds=300`, `connect_timeout=1`, `expose_request_id=true`, `store_user=true`, `user_id_property='id'`, `auto_migrate=false`.
- `request_id`: aceita `X-Request-Id` de entrada se casar `/^[A-Za-z0-9-]{8,64}$/`; senão 16 bytes aleatórios em hex (32 chars).
- `route` gravada é o **padrão** da rota (ex.: `users/{id:int}`), truncada em 191; 404 → `(unmatched)`; preflight CORS não é registrado; `user_id` ≤ 64 chars.
- `auto_migrate` é recusado em `prd` (`Psf->config('environment')`), só roda no flush, com lock de arquivo.
- Testes: `./vendor/bin/pest` (base atual: 227 passando) e `composer analyse` limpos ao fim de cada tarefa.
- Convenção de commit: mensagem em português curta; sem linhas de co-autoria nem rodapés de geração.

## Review Focus

1. Monitor ligado com banco inalcançável ou tabela ausente (sem `auto_migrate`): a resposta do request não muda, o breaker abre após 3 falhas e há **um** `error_log` — nunca exceção para o usuário (Task 7).
2. `sample.normal` = `0`, negativo, `>1` ou não numérico: nunca dividir por zero; `0` descarta todos os normais; inválido cai no default 0.1 (Task 1 e 2).
3. `X-Request-Id` de entrada hostil (CRLF, >64 chars, não ASCII): descartado e substituído, sem injeção em header ou log (Task 7).
4. Dois requests simultâneos com `auto_migrate` e tabelas ausentes: só um migra; o outro descarta o lote sem abrir o breaker (Task 8).
5. `Router::$auth` em formas inesperadas (objeto sem a propriedade, array, valor não escalar, id gigante): `user_id` vira `null` ou é truncado, sem erro (Task 7).

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `src/monitor/Config.php` | lê `settings.monitor` com defaults e validação; ponto único de config |
| `src/monitor/Sampler.php` | decide `weight` (1.0 / 1÷p / null) |
| `src/monitor/CircuitBreaker.php` | abre após N falhas; estado em APCu ou arquivo-flag |
| `src/monitor/Connection.php` | abre a conexão PDO dedicada (timeout curto) |
| `src/monitor/Sinks/SinkInterface.php`, `Sinks/DatabaseSink.php` | gravação em lote, chunking, transação |
| `src/monitor/Installer.php` | aplica as migrations do monitor via Phinx `Manager` |
| `src/monitor/AutoMigrator.php` | decide/serializa a instalação automática (lock, `prd`) |
| `src/monitor/Monitor.php` | fachada: boot, request id, contexto, flush |
| `src/monitor/Pruner.php` | apaga linhas brutas antigas em lotes |
| `src/monitor/Command/MonitorInstall.php`, `MonitorStatus.php`, `MonitorPrune.php` | comandos CLI |
| `src/monitor/migrations/*.php` | `psf_monitor_requests`, `_state`, `_rollups` |
| `src/database/PhinxEnvironments.php` | tradução `config['db']` → ambientes Phinx (extraída de `phinx.php`) |
| Modificados: `composer.json`, `phinx.php`, `bin/phinx`, `src/http/Router.php`, `src/http/Cors.php`, `tests/Pest.php` | integração |
| `tests/Fixtures/monitor_helpers.php` | helpers de teste |
| Docs: `docs/observabilidade.md`, `docs/melhorias.md`, `CLAUDE.md` | documentação |

---

### Task 0: Branch e infraestrutura de testes

**Files:**
- Modify: `composer.json` (autoload psr-4), `tests/Pest.php`
- Create: `tests/Fixtures/monitor_helpers.php`

**Interfaces:**
- Produces (helpers globais, `tests/Fixtures/monitor_helpers.php`):
  - `monitorUseConfig(array $monitor = [], array $top = []): string` — grava um config temporário (`db.default` = SQLite em arquivo temporário único; `settings.monitor` = `$monitor`; `$top` mesclado com `array_replace_recursive`, ex. `['environment'=>'prd','settings'=>['verifyauth'=>[...]]]`), chama `PSF::init`, `Psf\Monitor\Config::reset()` e devolve o caminho do arquivo SQLite.
  - `monitorCleanup(): void` — apaga os arquivos temporários, `PSF::init` com `tests/Fixtures/config.php`, `Config::reset()`, `Monitor::reset()`.

- [ ] **Step 1:** `git checkout -b feat/monitor-observabilidade` e commitar o spec e este plano (`docs/superpowers/`).
- [ ] **Step 2:** Em `composer.json`, adicionar `"Psf\\Monitor\\": "src/monitor/"` ao `autoload.psr-4`; rodar `composer dump-autoload`.
- [ ] **Step 3:** Criar `tests/Fixtures/monitor_helpers.php` com as duas funções acima; em `tests/Pest.php` fazer `require_once __DIR__ . '/Fixtures/monitor_helpers.php';` e registrar `uses()->afterEach(fn () => monitorCleanup())->in('Unit/Monitor', 'Integration/Monitor');`. As funções só chamam classes do monitor dentro do corpo (as classes surgem nas tarefas seguintes).
- [ ] **Step 4:** Rodar `./vendor/bin/pest`. Expected: 227 passed.
- [ ] **Step 5: Commit** — `chore: prepara testes e autoload do monitor`.

---

### Task 1: Config

**Files:**
- Create: `src/monitor/Config.php`, `tests/Unit/Monitor/ConfigTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\Config` com
  `static enabled(): bool`; `static get(string $dotPath, mixed $default = null): mixed`; `static sampleNormal(): float`; `static database(): string` (`get('database')` ou `'default'`); `static fake(?array $settings): void` (testes); `static reset(): void` (limpa memoização e fake).
  Lê `\PSF::config('settings', 'monitor')` (devolve `false` quando ausente), mescla com os defaults da seção "Global Constraints" (merge recursivo), memoizado até `reset()`.

- [ ] **Step 1: Write the failing test** (`ConfigTest.php`), nomes e asserts:
  - `it('is disabled when settings.monitor is missing')`: `Config::fake(null)` com config padrão → `enabled()` false.
  - `it('applies defaults')`: `Config::fake(['enabled'=>true])` → `get('sample.normal')===0.1`, `get('slow_ms.request')===1000`, `get('slow_ms.query')===200`, `get('max_events_per_request')===200`, `get('retention.raw_days')===7`, `get('retention.aggregate_days')===90`, `get('breaker.failures')===3`, `get('breaker.cooldown_seconds')===300`, `get('connect_timeout')===1`, `get('expose_request_id')===true`, `get('store_user')===true`, `get('user_id_property')==='id'`, `get('auto_migrate')===false`, `database()==='default'`.
  - `it('merges nested overrides')`: `['enabled'=>true,'slow_ms'=>['query'=>50]]` → `slow_ms.query===50`, `slow_ms.request===1000`.
  - `it('clamps sample.normal')` (dataset): `0`→`0.0`; `1.5`→`1.0`; `-1`→`0.1`; `'abc'`→`0.1`; `0.25`→`0.25`.
  - `it('reads settings.monitor from PSF config')`: `monitorUseConfig(['enabled'=>true,'database'=>'monitor'])` → `enabled()` true, `database()==='monitor'`.
- [ ] **Step 2:** `./vendor/bin/pest tests/Unit/Monitor/ConfigTest.php` → FAIL (classe inexistente).
- [ ] **Step 3: Implement** `Psf\Monitor\Config` conforme Interfaces; `sampleNormal()` aplica a regra do teste (não numérico ou negativo → 0.1; >1 → 1.0).
- [ ] **Step 4:** Rodar o teste → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): config com defaults e validação`.

---

### Task 2: Sampler

**Files:**
- Create: `src/monitor/Sampler.php`, `tests/Unit/Monitor/SamplerTest.php`

**Interfaces:**
- Consumes: `Config::get('slow_ms.request')`, `Config::get('slow_ms.query')`, `Config::sampleNormal()`.
- Produces: `final class Psf\Monitor\Sampler` — `static weight(int $status, float $durationMs, bool $hasException = false, float $maxQueryMs = 0.0, ?float $rand = null): ?float`. `$rand` em `[0,1)`; padrão `mt_rand() / (mt_getrandmax() + 1)`.

- [ ] **Step 1: Write the failing test:** com `Config::fake(['enabled'=>true,'sample'=>['normal'=>0.1]])`:
  - `it('always keeps errors, exceptions and slow requests with weight 1')`: `weight(500, 10)`, `weight(503, 10)`, `weight(200, 10, true)`, `weight(200, 1000)`, `weight(200, 10, false, 200.0)` → todos `1.0`, mesmo com `rand=0.99`.
  - `it('keeps a sampled normal request with weight 1/p')`: `weight(200, 10, rand: 0.05) === 10.0`.
  - `it('drops a normal request outside the sample')`: `weight(200, 10, rand: 0.1)` → `null`; `rand: 0.9` → `null`.
  - `it('never divides by zero when sample.normal is 0')`: fake `normal=>0` → `weight(200, 10, rand: 0.0)` → `null`.
  - `it('keeps everything with sample.normal 1')`: `weight(200, 10, rand: 0.99) === 1.0`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** `Sampler::weight`.
- [ ] **Step 4:** Rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): amostragem por peso`.

---

### Task 3: CircuitBreaker

**Files:**
- Create: `src/monitor/CircuitBreaker.php`, `tests/Unit/Monitor/CircuitBreakerTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\CircuitBreaker` —
  `__construct(string $key, int $failures, int $cooldownSeconds, ?\Closure $clock = null, ?string $dir = null)` (`$clock(): int` segundos, padrão `time`; `$dir` padrão `sys_get_temp_dir()`);
  `isOpen(): bool`; `recordFailure(): bool` (true **só** na chamada que abre o breaker); `recordSuccess(): void`; `trip(): bool` (abre já; true se estava fechado).
  Armazenamento: APCu quando `function_exists('apcu_fetch')` e APCu ativo no SAPI atual; senão arquivo `psf-monitor-<md5($key)>.json` em `$dir` com `{failures, open_until}`.

- [ ] **Step 1: Write the failing test** (diretório temporário, relógio injetado, APCu fora no CLI):
  - `it('stays closed below the threshold')`: 2 falhas (limite 3) → `isOpen()` false; as duas retornam false.
  - `it('opens at the threshold and reports it once')`: 3ª `recordFailure()` true, `isOpen()` true; 4ª retorna false.
  - `it('closes after the cooldown')`: abre; relógio +301 → `isOpen()` false.
  - `it('success resets the failure count')`: 2 falhas, `recordSuccess()`, 2 falhas → fechado.
  - `it('trip opens immediately and only reports the first time')`: `trip()` true, `isOpen()` true, `trip()` false.
  - `it('keeps separate state per key')`: chaves `a` e `b` com o mesmo `$dir`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** com escrita atômica do arquivo (`file_put_contents` com `LOCK_EX`); qualquer erro de I/O vira "fechado" (o breaker nunca lança).
- [ ] **Step 4:** Rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): circuit breaker com fallback sem APCu`.

---

### Task 4: PhinxEnvironments (extração do `phinx.php`)

**Files:**
- Create: `src/database/PhinxEnvironments.php`, `tests/Unit/Database/PhinxEnvironmentsTest.php`
- Modify: `phinx.php`

**Interfaces:**
- Produces: `final class Psf\Database\PhinxEnvironments` — `static fromDbConfigs(array $dbConfigs): array` devolve `[envName => phinxEnvArray]`, **mesma lógica e mesmo resultado** que o `phinx.php` produz hoje (aliases `host/hostname`, `user/username`, `pass/password`; driver enum ou int legado; SQLite com `suffix`; defaults de porta/charset/collation por driver; entradas inválidas são ignoradas).

- [ ] **Step 1: Write the failing test:** `it('maps a sqlite config')` → `['adapter'=>'sqlite','name'=>':memory:','suffix'=>'']`; `it('maps a mysql config with legacy int driver and aliases')` (driver `1` ou o valor de `DBDriver::MySQL`) → `adapter 'mysql'`, `port 3306`, `charset 'utf8mb4'`, `collation 'utf8mb4_unicode_ci'`; `it('applies pgsql defaults')` → `port 5432`, `charset 'utf8'`, sem `collation`; `it('skips entries without driver or database')`; `it('skips server drivers missing host, user or pass')`; `it('phinx.php still returns the environments')`: com `PSF::init` do `config.sqlite.php`, `include 'phinx.php'` → `['environments']['default']['adapter']==='sqlite'` e `['paths']['migrations']` termina em `/db/migrations`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** movendo (sem alterar comportamento) o laço e o `$driverToAdapter` de `phinx.php` para `PhinxEnvironments::fromDbConfigs`; `phinx.php` passa a chamar a classe e manter `paths`, `default_environment` e a exceção "Nenhuma configuração...".
- [ ] **Step 4:** `./vendor/bin/pest` → todos passam (os 227 anteriores + novos).
- [ ] **Step 5: Commit** — `refactor: extrai tradução de ambientes do phinx.php`.

---

### Task 5: Migrations e Installer

**Files:**
- Create: `src/monitor/migrations/20261024000001_psf_monitor_create_requests.php` (classe `PsfMonitorCreateRequests`), `..._000002_psf_monitor_create_state.php` (`PsfMonitorCreateState`), `..._000003_psf_monitor_create_rollups.php` (`PsfMonitorCreateRollups`), `src/monitor/Installer.php`, `tests/Integration/Monitor/InstallerTest.php`

**Interfaces:**
- Consumes: `PhinxEnvironments::fromDbConfigs`, `Config::database()`, `Connection::open` (Task 6 — nesta tarefa `isInstalled()` usa `Connection` apenas depois da Task 6; **implementar `isInstalled()` aqui com `Connect`-independente `new \PDO` via `DialectFactory`+`buildDsn`, e na Task 6 trocar por `Connection::open`**).
- Produces: `class Psf\Monitor\Installer` (não `final`, os testes a estendem) —
  `__construct(?string $database = null)` (null → `Config::database()`);
  `static migrationsPath(): string`;
  `install(): void` (lança `\RuntimeException` se a chave não existir em `config['db']` ou se `Phinx\Migration\Manager` não existir);
  `isInstalled(): bool` (as 3 tabelas existem, via `listTablesQuery()` do dialeto).
  Phinx: `Config` com `paths.migrations=[migrationsPath()]`, `environments.default_migration_table='psf_monitor_migrations'`, `default_environment='monitor'`, ambiente `monitor` = entrada de `PhinxEnvironments`; `Manager(config, new ArrayInput([]), new NullOutput)->migrate('monitor')`.
- Esquema (todas via abstração do Phinx; `id` = `biginteger` com `identity`):
  - `psf_monitor_requests`: `id`, `request_id` string(64), `ts` datetime, `method` string(10), `route` string(191), `version` integer default 0, `status` integer, `duration_ms` decimal(10,2), `memory_kb` integer, `user_id` string(64) null, `env` string(20), `app_release` string(64) null, `dropped_events` integer default 0, `sample_weight` decimal(12,4). Índices: `idx_psf_monitor_requests_ts (ts)`, `idx_psf_monitor_requests_route_ts (route, ts)`, `idx_psf_monitor_requests_request_id (request_id)`.
  - `psf_monitor_state`: `name` string(64) PK, `value` string(255).
  - `psf_monitor_rollups`: `bucket_hour` datetime, `metric` string(16), `metric_key` string(191), `hits` decimal(14,4), `errors` decimal(14,4), `sum_ms` decimal(18,2), `max_ms` decimal(10,2), `histogram` text, `sample_text` text null, `last_ts` datetime null; PK composta `(bucket_hour, metric, metric_key)`.

- [ ] **Step 1: Write the failing test** (`monitorUseConfig(['enabled'=>true])`, SQLite em arquivo):
  - `it('creates the monitor tables')`: `(new Installer())->install()` → tabelas `psf_monitor_requests`, `_state`, `_rollups` existem; `isInstalled()` true.
  - `it('has the contracted columns and indexes')`: `PRAGMA table_info` de `psf_monitor_requests` contém exatamente as 14 colunas acima; `PRAGMA index_list` contém os 3 índices; `rollups` tem PK de 3 colunas (`pk` 1..3 em `table_info`).
  - `it('is idempotent')`: `install()` duas vezes sem erro; `psf_monitor_migrations` tem 3 linhas.
  - `it('keeps the project phinxlog untouched')`: depois do install a tabela `phinxlog` **não** existe.
  - `it('reports not installed on an empty database')`: `isInstalled()` false antes do install.
  - `it('throws when the database key does not exist')`: `new Installer('nao_existe')` → `install()` lança `RuntimeException`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** migrations (`change()` com `table()->create()`) e `Installer` conforme Interfaces.
- [ ] **Step 4:** Rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): migrations e instalador via Phinx`.

---

### Task 6: Connection e DatabaseSink

**Files:**
- Create: `src/monitor/Connection.php`, `src/monitor/Sinks/SinkInterface.php`, `src/monitor/Sinks/DatabaseSink.php`, `tests/Integration/Monitor/DatabaseSinkTest.php`
- Modify: `src/monitor/Installer.php` (`isInstalled()` passa a usar `Connection::open`)

**Interfaces:**
- Produces:
  `final class Psf\Monitor\Connection` — `static open(string $database): \PDO` (via `DialectFactory`/`buildDsn`; `ERRMODE_EXCEPTION`; `ATTR_TIMEOUT = Config::get('connect_timeout')`; `extras` do banco mesclados; **não** passa por `Connect`); lança `\RuntimeException` se a chave não existir.
  `interface Psf\Monitor\Sinks\SinkInterface { public function write(array $batch): void; }` — `$batch = ['requests' => list<array<string,mixed>>]` (cada linha: coluna → valor).
  `final class Psf\Monitor\Sinks\DatabaseSink implements SinkInterface` — `__construct(?\Closure $pdoResolver = null, ?string $database = null)` (resolvedor padrão: `Connection::open`); `write(array $batch): void` (lança `\PDOException` em falha, `\InvalidArgumentException` para chave de lote desconhecida); `schemaInstalled(): bool`; `static rowsPerStatement(int $columns): int` = `max(1, min(1000, intdiv(2000, $columns)))`.
  Escrita: transação única; uma `INSERT` multi-linha por chunk, com tabela/colunas via `quoteIdentifier`; rollback se qualquer chunk falhar.

- [ ] **Step 1: Write the failing test:**
  - `it('opens a dedicated connection')`: `Connection::open('default')` retorna `PDO` com `ATTR_ERRMODE===ERRMODE_EXCEPTION` e **instância diferente** de `Connect::getConnection('default')`.
  - `it('throws for an unknown database key')`.
  - `it('computes rows per statement')`: `rowsPerStatement(14)===142`, `(1)===1000`, `(3000)===1`.
  - `it('inserts a request row')` (schema instalado): `write(['requests'=>[$row]])` → 1 linha com os valores.
  - `it('inserts more rows than fit in one statement')`: 1200 linhas → `COUNT(*)===1200`.
  - `it('rolls back everything when a chunk fails')`: 1200 linhas com a de índice 1100 contendo coluna inexistente → `PDOException` e `COUNT(*)===0`.
  - `it('rejects unknown batch keys')`: `InvalidArgumentException`.
  - `it('detects the schema')`: `schemaInstalled()` false no banco vazio, true depois do `Installer::install()`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** conforme Interfaces; comentário no código explicando que a conexão dedicada é exceção deliberada ao "não criar caminhos paralelos de CRUD" (não herda transação da aplicação, não é instrumentada, evita recursão).
- [ ] **Step 4:** `./vendor/bin/pest` → PASS (inclui o `InstallerTest`).
- [ ] **Step 5: Commit** — `feat(monitor): conexão dedicada e sink em lote`.

---

### Task 7: Monitor (fachada)

**Files:**
- Create: `src/monitor/Monitor.php`, `tests/Unit/Monitor/MonitorTest.php`

**Interfaces:**
- Consumes: `Config`, `Sampler::weight`, `CircuitBreaker`, `SinkInterface`/`DatabaseSink`.
- Produces: `final class Psf\Monitor\Monitor` —
  `static boot(?string $method = null): void` (no-op se desligado; idempotente; define `request_id`, marca o início com `$_SERVER['REQUEST_TIME_FLOAT']`, registra **uma** `register_shutdown_function([self::class,'flush'])`, emite os headers de `responseHeaders()` se `!headers_sent()`; marca "ignorar" quando `$method==='OPTIONS'` e `$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']` existe);
  `static enabled(): bool`; `static requestId(): ?string`;
  `static resolveRequestId(?string $incoming): string`;
  `static responseHeaders(): array` (`['X-Request-Id'=>id]` se ligado e `expose_request_id`; senão `[]`);
  `static route(string $pattern, string $method, int $version): void` (trunca `pattern` em 191);
  `static user(mixed $auth): void` (aceita objeto → propriedade `user_id_property`, array → chave; só escalar; string ≤ 64; `null` se `store_user=false`);
  `static flush(?int $status = null, ?float $rand = null): void` (corpo todo em `try/catch \Throwable`; status padrão `http_response_code() ?: 200`; chama `fastcgi_finish_request()` se existir; monta a linha de `requests` com `ts` UTC do início, `duration_ms`, `memory_kb = intdiv(memory_get_peak_usage(true),1024)`, `env` = `\PSF::config('environment')` ou `'dev'`, `app_release` = `Config::get('release')`, `dropped_events=0`, `sample_weight`; não grava se `Sampler` devolver `null` ou se "ignorar"; respeita o breaker);
  `static snapshot(): array` (contexto atual: `request_id, route, method, version, user_id`);
  `static reset(): void`; seams de teste: `useSink(?SinkInterface)`, `useBreaker(?CircuitBreaker)`.
  Falha do sink: `breaker->recordFailure()`; quando retornar true, **um** `error_log('[PSF][monitor] ...')` com a causa (tabela ausente → mencionar `monitor:install`). Breaker aberto → não chama o sink.

- [ ] **Step 1: Write the failing test** (sink espião que guarda os lotes; breaker com `$dir` temporário):
  - `it('is a no-op when disabled')`: `boot()`; `requestId()` null; `flush(200)` não chama o sink.
  - `it('accepts a valid incoming request id')`: `resolveRequestId('abc-12345678')==='abc-12345678'`.
  - `it('replaces hostile request ids')` (dataset): `"abc\r\nX: y"`, `'abc'`, `str_repeat('a',65)`, `'ábcdefgh12'` → resultado casa `/^[0-9a-f]{32}$/`.
  - `it('exposes the request id header only when enabled')`: `['X-Request-Id'=>id]`; com `expose_request_id=false` → `[]`; desligado → `[]`.
  - `it('writes one request row')`: `boot('GET')`, `route('users/{id:int}','GET',1)`, `user((object)['id'=>42])`, `flush(200, 0.0)` com `sample.normal=1.0` → lote `requests` com 1 linha: `method 'GET'`, `route 'users/{id:int}'`, `version 1`, `status 200`, `user_id '42'`, `env 'dev'`, `sample_weight 1.0`, `request_id` igual a `requestId()`, `duration_ms >= 0`, `memory_kb > 0`.
  - `it('keeps errors even when sampled out')`: `sample.normal=0` e `flush(500)` → grava com `sample_weight 1.0`; `flush(200)` → não grava.
  - `it('skips CORS preflight')`: `$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']='POST'`, `boot('OPTIONS')`, `flush(204)` → nada gravado.
  - `it('truncates the route label')`: `route(str_repeat('a',300), 'GET', 1)` → linha com `strlen(route)===191`.
  - `it('resolves the user id defensively')` (dataset): objeto com `id=42` → `'42'`; array `['id'=>7]` → `'7'`; objeto sem `id` → `null`; `['id'=>['x']]` → `null`; id de 100 chars → 64 chars; `store_user=false` → `null`.
  - `it('never throws and opens the breaker after repeated sink failures')`: sink que lança `PDOException`; 3 `flush()` não lançam; o 4º não chama o sink; o log (`ini_set('error_log', arquivo)`) contém `[PSF][monitor]` exatamente 1 vez.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** `Monitor`.
- [ ] **Step 4:** Rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): fachada, request id e flush com breaker`.

---

### Task 8: AutoMigrator

**Files:**
- Create: `src/monitor/AutoMigrator.php`, `tests/Integration/Monitor/AutoMigrateTest.php`
- Modify: `src/monitor/Monitor.php` (integra no `flush`)

**Interfaces:**
- Consumes: `Installer::isInstalled()`/`install()`, `DatabaseSink::schemaInstalled()`.
- Produces: `final class Psf\Monitor\AutoMigrator` — `__construct(Installer $installer, string $environment, ?string $lockDir = null)`; `run(): string` devolve uma de `AutoMigrator::MIGRATED` (`'migrated'`; inclui "outro request já migrou"), `LOCKED` (`'locked'`), `UNAVAILABLE` (`'unavailable'`), `REFUSED` (`'refused'`, ambiente `prd`). Lock: `flock(LOCK_EX|LOCK_NB)` em `<lockDir>/psf-monitor-<md5(database)>.lock`; depois de obter o lock, reconfere `isInstalled()`. Nunca lança.
  `Monitor::useAutoMigrator(?AutoMigrator)` (teste).
  Integração no `flush`: na falha do sink, **se** `Config::get('auto_migrate')` e `!$sink->schemaInstalled()`: `run()` → `MIGRATED`: reescreve o lote **uma** vez (sucesso → `recordSuccess`, falha → fluxo normal de falha); `LOCKED`: descarta o lote, **sem** contar falha; `REFUSED`/`UNAVAILABLE`: `breaker->trip()` + um `error_log` (prd: "auto_migrate ignorado em prd; rode monitor:install"; indisponível: "auto_migrate requer robmorgan/phinx" ou a causa). Falha por outro motivo (esquema presente) → fluxo normal de falha.

- [ ] **Step 1: Write the failing test** (SQLite em arquivo, `sample.normal=1.0`, `error_log` redirecionado):
  - `it('does nothing when auto_migrate is off')`: banco vazio, flag desligada → `flush(200)` não cria tabelas, breaker registra 1 falha.
  - `it('creates the tables and writes the batch')`: `auto_migrate=true`, ambiente `dev` → após `flush(200)` as 3 tabelas existem e `psf_monitor_requests` tem 1 linha.
  - `it('refuses to migrate in prd')`: `monitorUseConfig([...,'auto_migrate'=>true], ['environment'=>'prd'])` → nenhuma tabela criada, breaker aberto, log contém `prd`.
  - `it('discards the batch without failing when the lock is taken')`: o teste segura `flock` no arquivo de lock → `run()==='locked'`; nenhuma tabela; `isOpen()` false e 0 falhas registradas.
  - `it('is idempotent on later requests')`: segundo `flush` não migra de novo (`psf_monitor_migrations` continua com 3 linhas) e grava a 2ª linha.
  - `it('opens the breaker when migrating is not possible')`: `Installer` de teste cuja `install()` lança → `run()==='unavailable'`, breaker aberto, log com a causa uma vez.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** `AutoMigrator` e a integração no `flush`.
- [ ] **Step 4:** `./vendor/bin/pest` → PASS (inclui `MonitorTest`).
- [ ] **Step 5: Commit** — `feat(monitor): auto_migrate opt-in com lock e recusa em prd`.

---

### Task 9: Ganchos no Router e CORS

**Files:**
- Modify: `src/http/Router.php` (construtor, `callMethodRoute`, `runAuthMiddleware`, `applyCors`), `src/http/Cors.php`, `tests/Unit/Http/RouterTest.php`, `tests/Unit/Http/CorsTest.php`

**Interfaces:**
- Consumes: `Monitor::boot/route/user/enabled`, `Config::get('expose_request_id')`.
- Produces: `Cors::resolve` passa a emitir `Access-Control-Expose-Headers` (lista separada por `, `) quando `$config['expose_headers']` for um array não vazio (nova chave opcional, documentar no docblock). `Router::applyCors` acrescenta `'X-Request-Id'` a `expose_headers` quando `Config::enabled() && Config::get('expose_request_id')`.
  Router: `__construct` chama `Monitor::boot($this->method)`; `callMethodRoute` chama `Monitor::route($matchedRoute['attributes']['arguments']['path'], $this->method, $this->version)` quando a rota casa, e `Monitor::route('(unmatched)', $this->method, (int) $this->version)` antes de lançar o 404; `runAuthMiddleware` chama `Monitor::user(self::$auth)` logo após definir `self::$auth`.

- [ ] **Step 1: Write the failing test:**
  - `CorsTest`: `it('emits expose headers when configured')` → `Access-Control-Expose-Headers: X-Request-Id, X-Total`; `it('omits expose headers by default')`.
  - `RouterTest` (com `Config::fake(['enabled'=>true])`, `Monitor::reset()` no `afterEach`): `it('records the matched route pattern for the monitor')` — `callMethodRoute` com `users/{id:int}` e `_url=v1/users/5` → `Monitor::snapshot()['route']==='users/{id:int}'`, `method 'GET'`, `version 1`; `it('records (unmatched) for a 404')` — rota inexistente: `callMethodRoute` lança com código 404 e `snapshot()['route']==='(unmatched)'`; `it('forwards the authenticated user to the monitor')` — com `monitorUseConfig(['enabled'=>true], ['settings'=>['verifyauth'=>[Tests\Fixtures\Controllers\FakeAuth::class,'verify']]])` e a fixture `FakeAuth::verify()` devolvendo `(object)['id'=>9]` → `snapshot()['user_id']==='9'`; `it('does not touch the monitor when disabled')` — `snapshot()` vazio.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** os ganchos e a chave `expose_headers`; criar `tests/Fixtures/Controllers/FakeAuth.php`.
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): ganchos no Router e expose-headers no CORS`.

---

### Task 10: Pruner

**Files:**
- Create: `src/monitor/Pruner.php`, `tests/Integration/Monitor/PrunerTest.php`

**Interfaces:**
- Consumes: `Connection::open`, `DialectFactory::fromConfig`, `DialectInterface::topClause/limitOffsetClause/quoteIdentifier`.
- Produces: `final class Psf\Monitor\Pruner` — `__construct(\PDO $pdo, DialectInterface $dialect, int $batchSize = 5000)`; `pruneRaw(\DateTimeImmutable $now, int $rawDays): int` (total de linhas apagadas). Tabelas brutas: constante `RAW_TABLES = ['psf_monitor_requests']` (as fases 2 e 4 acrescentam). Corte = `now - rawDays`; **se** `psf_monitor_state` tiver `name='rollup_watermark'` (valor `Y-m-d H:i:s` UTC), o corte vira `min(corte, watermark)`; tabela `state` ausente → sem watermark. Algoritmo por lote: `SELECT <top> id FROM t WHERE ts < :cutoff ORDER BY id <limit>` via dialeto (usa o **último id** devolvido) e `DELETE FROM t WHERE ts < :cutoff AND id <= :lastId`; repete até um `SELECT` vazio; pausa curta (`usleep(10000)`) entre lotes.

- [ ] **Step 1: Write the failing test** (schema instalado; inserir linhas direto por PDO):
  - `it('deletes only rows older than raw_days')`: linhas em −10d, −8d, −1d, `now=agora`, `rawDays=7` → restam só a de −1d; retorno `2`.
  - `it('deletes in batches')`: 5 linhas antigas, `batchSize=2` → retorno `5`, tabela sem as antigas.
  - `it('never deletes past the rollup watermark')`: `state` com `rollup_watermark = now−9d`, `rawDays=7`: −10d apagada; −8d e −1d mantidas.
  - `it('works without a state table row')`: sem watermark, −8d apagada.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** `Pruner`.
- [ ] **Step 4:** Rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): prune em lotes respeitando o watermark`.

---

### Task 11: Comandos CLI

**Files:**
- Create: `src/monitor/Command/MonitorInstall.php`, `MonitorStatus.php`, `MonitorPrune.php`, `tests/Integration/Monitor/CommandsTest.php`
- Modify: `bin/phinx`

**Interfaces:**
- Consumes: `Installer`, `Pruner`, `Connection`, `Config`.
- Produces: comandos Symfony (`Symfony\Component\Console\Command\Command`), todos com `-e|--environment <chave de config['db']>` opcional (padrão `Config::database()`):
  - `monitor:install` → `Installer::install()`; saída `Tabelas do monitor instaladas.`; exit 0; falha → mensagem de erro e exit 1.
  - `monitor:status` → linhas `Banco: <chave>`, `Instalado: sim|não`, `Monitor ligado: sim|não`; exit 0.
  - `monitor:prune` → `Pruner::pruneRaw(now UTC, Config::get('retention.raw_days'))`; saída `Linhas apagadas: <n>`; exit 0.
  `bin/phinx` registra os três em `$app->addCommands([...])` junto aos existentes.

- [ ] **Step 1: Write the failing test** (`CommandTester`, `monitorUseConfig(['enabled'=>true])`):
  - `it('installs the tables')`: `monitor:install` → exit 0, saída contém `Tabelas do monitor instaladas.`, `isInstalled()` true.
  - `it('reports the status')`: antes do install `Instalado: não`; depois `Instalado: sim`; `Monitor ligado: sim`.
  - `it('prunes old rows')`: install, inserir linha de −10d e de hoje → `monitor:prune` → `Linhas apagadas: 1`.
  - `it('fails with a clear message for an unknown database')`: `-e nao_existe` → exit 1.
  - `it('registers in a console application')`: os três nomes existem em um `Application` com os comandos adicionados.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3: Implement** os comandos e o registro em `bin/phinx`; `php -l bin/phinx`.
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): comandos monitor:install, status e prune`.

---

### Task 12: Documentação e verificação final

**Files:**
- Create: `docs/observabilidade.md`
- Modify: `docs/melhorias.md`, `CLAUDE.md`

- [ ] **Step 1:** Escrever `docs/observabilidade.md` em português: visão geral, configuração (todas as chaves e defaults), instalação (`monitor:install`, `auto_migrate` e suas restrições), o **esquema das 3 tabelas como contrato** (colunas, índices, UTC, `sample_weight` e a regra de agregados `hits = Σ sample_weight`), `X-Request-Id` (entrada validada, expose no CORS), amostragem, privacidade (o que nunca é gravado), breaker, retenção (`monitor:prune`, cron sugerido), limites conhecidos (sem `fastcgi_finish_request` a latência do flush é visível; queries/exceções/rollups/`Reader` chegam nas próximas fases).
- [ ] **Step 2:** Acrescentar a entrada do monitor em `docs/melhorias.md` (changelog) e uma seção "Monitor (observabilidade)" em `CLAUDE.md` (onde mora cada peça, `Monitor::reset()`/`Config::reset()` nos testes, conexão dedicada como exceção deliberada).
- [ ] **Step 3:** Rodar `./vendor/bin/pest` → todos passam (227 + novos); `composer analyse` → sem erros.
- [ ] **Step 4: Verificação manual** com SQLite em arquivo: script em `/private/tmp/claude-501/-Users-theodoropaim-Development-php-micro/e43280d4-db99-42b8-a94e-97bb96bb6f65/scratchpad/` que faz `PSF::init` com `monitor.enabled` + `auto_migrate`, instancia o `Router` simulando um `GET`, deixa o shutdown rodar e conta as linhas de `psf_monitor_requests`. Expected: 1 linha com `route` correto e `X-Request-Id` igual ao `request_id`.
- [ ] **Step 5: Commit** — `docs(monitor): guia de observabilidade e changelog`.

---

## Self-Review

- **Cobertura do spec (Fase 1):** `X-Request-Id` (T7, T9), `auto_migrate` (T8), `Monitor/Sampler/CircuitBreaker/DatabaseSink` (T2, T3, T6, T7), tabelas `requests`/`state`/`rollups` + migrations + `monitor:install`/`status` (T5, T11), ganchos no `Router` + preflight/404/`user_id` (T7, T9), CORS expose (T9), `monitor:prune` para requests com watermark (T10, T11), `docs/observabilidade.md` (T12). Fora desta fase: `Buffer`, queries, exceções/fatal, `SqlNormalizer`, `Histogram`, `Rollup`, `Reader`, `external`, `monitor:rollup/maintain`.
- **Consistência de tipos:** `Monitor::flush(?int, ?float)`, `Sampler::weight(...)`, `CircuitBreaker::{isOpen,recordFailure,recordSuccess,trip}`, `Installer::{install,isInstalled,migrationsPath}`, `AutoMigrator::{MIGRATED,LOCKED,UNAVAILABLE,REFUSED}` usados de forma idêntica nas tarefas consumidoras.
- **Dependência entre T5 e T6:** `Installer::isInstalled()` nasce com PDO próprio e é migrado para `Connection::open` na T6 (indicado nos dois lugares).
- **Proporção:** o plano define interfaces e testes; os corpos ficam para quem implementa.
