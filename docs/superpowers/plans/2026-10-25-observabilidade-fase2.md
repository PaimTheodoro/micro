# Observabilidade — Fase 2 (Queries e exceções) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar, por request, as queries SQL (normalizadas, com duração) e as exceções (com stack trace completo sem argumentos e erros fatais), gravando em `psf_monitor_queries` e `psf_monitor_exceptions` com a mesma amostragem por peso da fase 1.

**Architecture:** Os shims `Read/Create/Update/Delete` medem cada execução e entregam o SQL cru ao `Monitor`, que o guarda num `Buffer` com teto (`max_events_per_request`). O `Router` (e os shims, em falhas de PDO) entregam as exceções. No `flush()`, depois de decidida a gravação e liberada a resposta ao cliente, o monitor normaliza o SQL (`SqlNormalizer`), descreve as exceções (`ExceptionDescriber`) e grava tudo no mesmo lote/transação do request. Uma request com exceção ou query lenta passa a ser sempre mantida (peso 1).

**Tech Stack:** PHP 8.1+, PDO, Phinx 0.16 (migrations), Pest 3, PHPStan nível 1.

**Spec:** `docs/superpowers/specs/2026-10-24-observabilidade-design.md` (esta é a "Fase 2"). Base: fase 1 já implementada em `feat/monitor-observabilidade` (plano `docs/superpowers/plans/2026-10-24-observabilidade-fase1.md`).

## Global Constraints

- PHP `>=8.1`; nenhuma assinatura pública existente muda; todos os ganchos novos são no-op (uma checagem de booleano) quando o monitor está desligado e **nunca lançam** nem emitem warning/notice.
- Namespace `Psf\Monitor\` → `src/monitor/`. Comentários, mensagens e `docs/` em **português do Brasil**.
- Timestamps UTC `Y-m-d H:i:s`; durações em ms `DECIMAL(10,2)`; tabelas `psf_monitor_*`; identificadores SQL via `DialectInterface::quoteIdentifier()`.
- **Privacidade:** SQL gravado só normalizado (literais viram `?`, comentários removidos, `IN (...)` → `IN (?)`, `VALUES (...), (...)` → `VALUES (?)`, espaços colapsados, ≤ 2000 caracteres); `sql_hash` = `sha1` do SQL normalizado. Exceções: classe ≤ 191, mensagem ≤ 500, arquivo ≤ 255, `trace_hash` = `sha1`, **trace completo sem argumentos dos frames**, com a cadeia `getPrevious()`, ≤ 16 384 bytes (corte seguro em UTF-8, terminado por `... [truncado]`). Nunca gravar body, headers, token nem valores de bindings.
- Teto: `max_events_per_request` (padrão 200) para queries; exceções têm teto fixo de 20 por request (`Buffer::EXCEPTION_CAP`); o que passar do teto é descartado e somado em `dropped_events` (coluna de `psf_monitor_requests`). O maior tempo de query é acompanhado **mesmo para as descartadas**.
- Request **mantido com peso 1** quando: status ≥ 500, qualquer exceção registrada, duração ≥ `slow_ms.request` ou alguma query ≥ `slow_ms.query` (200). Linhas de queries/exceções levam o mesmo `sample_weight` do request.
- Exceções 4xx explícitas e `HttpResponseException` **não** são registradas como exceção (o status já as mostra). Erro fatal (`error_get_last`) é registrado com classe `FatalError`, mensagem só até `Stack trace:`, sem trace, e força o status do request a 500.
- Normalização/descrição de SQL e exceções ocorre **no flush, depois de `finishResponse()`** (nunca no caminho quente do request).
- Testes: `./vendor/bin/pest` (base atual: 368 passando) e `composer analyse` limpos ao fim de cada tarefa. Commits em português, sem linhas de co-autoria nem rodapés de geração; nunca commitar `.superpowers/`.

## Review Focus

1. SQL hostil ao normalizador (aspas não terminadas, `--`/`/*` dentro de literais, `$$...$$`, UTF-8 inválido, 1 MB de texto): nunca lança, nunca vaza o literal, tempo limitado (Task 2).
2. Loop N+1 com 10 000 queries: memória limitada pelo teto de 200 eventos, `dropped_events` correto, request mantido por query lenta mesmo descartada (Task 1 e 6).
3. Exceção com argumento secreto no frame, cadeia `previous`, mensagem enorme/multibyte: sem argumentos no trace, ≤ 16 KB, UTF-8 válido (Task 3).
4. Banco com o esquema da fase 1 (sem as tabelas novas): o flush falha de forma segura (breaker, um `error_log` com a dica `monitor:install`); com `auto_migrate` em dev as tabelas novas são criadas e o lote regravado (Task 4 e 6).
5. Falha de PDO dentro dos shims: a query e a exceção são registradas **uma única vez** e o comportamento original (propagar a exceção / `explodeException`) não muda (Task 7 e 8).

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `src/monitor/Buffer.php` | eventos do request em memória, com teto e contagem de descartes |
| `src/monitor/SqlNormalizer.php` | normaliza SQL (tokenizador) e calcula o hash |
| `src/monitor/ExceptionDescriber.php` | descreve uma exceção (campos + trace sem args + hash) |
| `src/monitor/migrations/20261025000001_psf_monitor_create_queries.php`, `20261025000002_psf_monitor_create_exceptions.php` | novas tabelas |
| Modificados: `Monitor.php`, `Installer.php`, `Sinks/DatabaseSink.php`, `Pruner.php`, `src/database/Read.php`, `Create.php`, `Update.php`, `Delete.php`, `src/http/Router.php` | integração |
| Testes novos: `tests/Unit/Monitor/{Buffer,SqlNormalizer,ExceptionDescriber,MonitorEvents}Test.php`, `tests/Integration/Monitor/{QueryHooks,ExceptionFlow}Test.php` | |
| Docs: `docs/observabilidade.md`, `docs/melhorias.md`, `CLAUDE.md` | |

---

### Task 1: Buffer

**Files:**
- Create: `src/monitor/Buffer.php`, `tests/Unit/Monitor/BufferTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\Buffer` —
  `public const EXCEPTION_CAP = 20; public const RAW_SQL_MAX = 8000;`
  `__construct(int $maxEvents)` (valor < 1 vira 1);
  `addQuery(string $sql, float $durationMs, string $connection, int $ts): void` — guarda o SQL cortado em `RAW_SQL_MAX` caracteres (UTF-8, sem partir caractere) enquanto houver espaço; **sempre** atualiza o maior tempo e, se não coube, soma 1 em `droppedEvents`;
  `queries(): array` (lista de `['sql','duration_ms','connection','ts']`); `maxQueryMs(): float`;
  `addException(array $event): bool` (false e conta descarte quando já há `EXCEPTION_CAP`); `exceptions(): array`; `droppedEvents(): int`; `hasExceptions(): bool`.

- [ ] **Step 1: Write the failing test** (`BufferTest`):
  - `it('keeps queries up to the cap and counts the rest as dropped')`: teto 3, 5 queries → `count(queries())===3`, `droppedEvents()===2`.
  - `it('tracks the slowest query even when it was dropped')`: teto 1; query de 10 ms e depois de 450,5 ms → `maxQueryMs()===450.5`, `queries()` só a primeira.
  - `it('cuts a huge SQL safely')`: SQL de 20 000 caracteres multibyte (`str_repeat('é', 20000)`) → `mb_strlen(queries()[0]['sql'])===Buffer::RAW_SQL_MAX` e `mb_check_encoding(..., 'UTF-8')`.
  - `it('caps exceptions and counts the overflow')`: 25 chamadas de `addException` → as 20 primeiras true, as 5 últimas false, `droppedEvents()===5`, `hasExceptions()` true.
  - `it('treats a cap below 1 as 1')`.
  - `it('is memory-bounded for a 10000 query loop')`: teto 200, 10 000 `addQuery` → `count(queries())===200`, `droppedEvents()===9800`.
- [ ] **Step 2:** `./vendor/bin/pest tests/Unit/Monitor/BufferTest.php` → FAIL.
- [ ] **Step 3: Implement** `Buffer` conforme Interfaces.
- [ ] **Step 4:** rodar o teste → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): buffer de eventos com teto`.

---

### Task 2: SqlNormalizer

**Files:**
- Create: `src/monitor/SqlNormalizer.php`, `tests/Unit/Monitor/SqlNormalizerTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\SqlNormalizer` — `static normalize(string $sql): string` (nunca lança; resultado ≤ 2000 caracteres, corte seguro em UTF-8); `static hash(string $normalized): string` (`sha1`, 40 hex). Implementação por **tokenizador de passada única** (não regex global), com complexidade linear.

- [ ] **Step 1: Write the failing test** (`SqlNormalizerTest`, tabela de casos entrada → saída esperada, exatamente):
  - `SELECT * FROM users WHERE id = 5 AND name = 'bob'` → `SELECT * FROM users WHERE id = ? AND name = ?`
  - `WHERE id IN (1, 2, 3)` → `WHERE id IN (?)`; `IN ('a','b')` → `IN (?)`; `IN (:a, :b)` → `IN (?)`
  - `INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y')` → `INSERT INTO t (a, b) VALUES (?)`
  - `x = 'it''s'` e `x = 'it\'s'` → `x = ?`
  - identificadores preservados: `SELECT "user name", \`order\`, [dbo].[t1] FROM t1 WHERE t1.col2 = 3.14` → `SELECT "user name", \`order\`, [dbo].[t1] FROM t1 WHERE t1.col2 = ?`
  - `WHERE x = -5` → `WHERE x = ?`; `WHERE h = 0xDEADBEEF` → `WHERE h = ?`
  - comentários removidos: `SELECT 1 -- senha 'x'` → `SELECT ?`; `SELECT /* 'abc' */ 1` → `SELECT ?`
  - literais contendo marcadores de comentário: `WHERE a = '-- nao e comentario' AND b = 2` → `WHERE a = ? AND b = ?`
  - PostgreSQL: `WHERE a = $$segredo$$` e `E'a\'b'` → `WHERE a = ?`
  - aspas não terminada: `WHERE a = 'abc` → `WHERE a = ?` (consome o resto, sem lançar)
  - espaços colapsados e `trim`: `  SELECT   1 \n FROM   t  ` → `SELECT ? FROM t`
  - `''` → `''`; UTF-8 inválido (`"SELECT '\xff' FROM t"`) → não lança e não contém `\xff`
  - `strlen`: entrada de 1 MB (`'SELECT ' . str_repeat('a = 1 AND ', 100000)`) → `mb_strlen(normalize($x)) <= 2000` e termina em tempo razoável (< 1 s)
  - `hash()`: 40 hex, igual para a mesma string, diferente para strings diferentes.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** o tokenizador (strings simples `'..'` com `''` e `\'`, `$$..$$`, `E'..'`; identificadores entre `"` `` ` `` `[]` copiados sem alteração; comentários `--` e `/* */`; números/hex; `IN (` de lista só com literais/placeholders; `VALUES (...)` repetidos).
- [ ] **Step 4:** rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): normalizador de SQL`.

---

### Task 3: ExceptionDescriber

**Files:**
- Create: `src/monitor/ExceptionDescriber.php`, `tests/Unit/Monitor/ExceptionDescriberTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\ExceptionDescriber` — `static describe(\Throwable $e): array{exception_class: string, message: string, file: string, line_no: int, trace: string, trace_hash: string}` e `static fatal(array $error): array` (mesmo formato; `$error` é o formato de `error_get_last()`: `type, message, file, line`).
  Regras: classe ≤ 191, mensagem ≤ 500, arquivo ≤ 255 (cortes em caracteres UTF-8). `trace`: um frame por linha `#n arquivo(linha): Classe::método()` (sem argumentos), seguido, para cada `getPrevious()`, de `\n\nCaused by: Classe: mensagem (arquivo:linha)\n` + frames; ≤ 16 384 bytes com corte seguro e sufixo `... [truncado]`. `trace_hash` = `sha1(classe|arquivo:linha|frames "arquivo:linha:função" da exceção principal)` (independe de mensagem e argumentos). `fatal()`: classe `FatalError`, mensagem só até `Stack trace:` (cortada em 500), `trace` = `''`, hash de `FatalError|arquivo:linha`.

- [ ] **Step 1: Write the failing test:**
  - `it('never includes frame arguments')`: função `function falha(string $senha) { throw new RuntimeException('x'); }` chamada com `'hunter2'` → `trace` não contém `hunter2`; contém `falha(`.
  - `it('includes the previous chain')`: `new Exception('externa', 0, new RuntimeException('interna'))` → `trace` contém `Caused by: RuntimeException: interna`.
  - `it('caps message, class, file and trace')`: mensagem de 5000 caracteres multibyte → `mb_strlen(message)===500`, `mb_check_encoding` ok; exceção com cadeia profunda de 2000 frames/previous → `strlen(trace) <= 16384` e termina com `... [truncado]`.
  - `it('hashes by location, not by message or arguments')`: mesma linha com mensagens diferentes → mesmo `trace_hash`; linhas diferentes → hashes diferentes; hash tem 40 hex.
  - `it('describes a fatal error')`: `['type'=>E_ERROR,'message'=>"Uncaught Exception: boom in /a.php:12\nStack trace:\n#0 x('segredo')",'file'=>'/a.php','line'=>12]` → classe `FatalError`, mensagem sem `Stack trace`/`segredo`, `trace===''`, `line_no===12`.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** `ExceptionDescriber` (sem lançar; qualquer falha interna devolve campos mínimos).
- [ ] **Step 4:** rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): descrição de exceções sem argumentos`.

---

### Task 4: Migrations, Installer, sink e pruner para as novas tabelas

**Files:**
- Create: `src/monitor/migrations/20261025000001_psf_monitor_create_queries.php` (classe `PsfMonitorCreateQueries`), `src/monitor/migrations/20261025000002_psf_monitor_create_exceptions.php` (classe `PsfMonitorCreateExceptions`)
- Modify: `src/monitor/Installer.php` (`TABLES`), `src/monitor/Sinks/DatabaseSink.php` (mapa de tabelas), `src/monitor/Pruner.php` (`RAW_TABLES`), `tests/Integration/Monitor/InstallerTest.php`, `DatabaseSinkTest.php`, `PrunerTest.php`, `AutoMigrateTest.php` (as asserções que contam 3 tabelas/migrations/arquivos passam a 5)

**Interfaces:**
- Esquema (mesmo padrão da migration de `requests`, inclusive `id` integer na SQLite e biginteger identity nos demais):
  - `psf_monitor_queries`: `id`, `request_id` string(64), `ts` datetime, `sql_hash` string(40), `sql_text` text, `duration_ms` decimal(10,2), `connection_name` string(64), `sample_weight` decimal(12,4). Índices: `idx_psf_monitor_queries_request_id (request_id)`, `idx_psf_monitor_queries_hash_ts (sql_hash, ts)`, `idx_psf_monitor_queries_ts (ts)`.
  - `psf_monitor_exceptions`: `id`, `request_id` string(64), `ts` datetime, `exception_class` string(191), `message` string(500), `file` string(255), `line_no` integer, `trace_hash` string(40), `trace` text, `sample_weight` decimal(12,4). Índices: `idx_psf_monitor_exceptions_ts (ts)`, `idx_psf_monitor_exceptions_hash_ts (trace_hash, ts)`, `idx_psf_monitor_exceptions_request_id (request_id)`.
- `Installer::TABLES` passa a ter as 5 tabelas (`isInstalled()` exige todas). `DatabaseSink` aceita as chaves de lote `queries` → `psf_monitor_queries` e `exceptions` → `psf_monitor_exceptions`. `Pruner::RAW_TABLES = ['psf_monitor_requests','psf_monitor_queries','psf_monitor_exceptions']`.

- [ ] **Step 1: Write the failing tests:**
  - `InstallerTest`: `it('creates the five monitor tables')`; `it('has the contracted columns and indexes for queries and exceptions')` (nomes das colunas e dos índices, como nos testes existentes); `it('upgrades a phase-1 install')`: instala, `DROP TABLE` das duas novas e `DELETE FROM psf_monitor_migrations` das 2 versões novas, `isInstalled()` false, `install()` de novo → true e as 3 tabelas antigas intactas (uma linha inserida antes continua lá); atualizar as asserções existentes (`3` → `5` para migrations e arquivos).
  - `DatabaseSinkTest`: `it('inserts queries and exceptions in the same transaction')` (lote com as 3 chaves; uma linha inválida em `exceptions` desfaz também `requests` e `queries`).
  - `PrunerTest`: `it('prunes queries and exceptions older than the cutoff')` (linhas antigas e novas nas 3 tabelas; retorno = total apagado; novas permanecem).
  - `AutoMigrateTest`: ajustar a contagem de `psf_monitor_migrations` de 3 para 5.
- [ ] **Step 2:** rodar `./vendor/bin/pest tests/Integration/Monitor` → FAIL.
- [ ] **Step 3: Implement** as duas migrations e as 3 alterações de constantes/mapas.
- [ ] **Step 4:** rodar `./vendor/bin/pest` (suíte inteira) → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): tabelas de queries e exceções`.

---

### Task 5: Monitor — eventos, fatal e montagem do lote

**Files:**
- Modify: `src/monitor/Monitor.php`
- Create: `tests/Unit/Monitor/MonitorEventsTest.php`

**Interfaces:**
- Consumes: `Buffer`, `SqlNormalizer`, `ExceptionDescriber`, `Sampler::weight(int $status, float $durationMs, bool $hasException, float $maxQueryMs, ?float $rand)`, `Config::get('max_events_per_request')`.
- Produces em `Monitor`:
  `static queryStart(): ?int` (`hrtime(true)`; `null` se desligado);
  `static queryEnd(?int $start, string $sql, string $connection = 'default'): void` (no-op se `$start===null`; duração = `(hrtime(true)-$start)/1e6`; guarda no `Buffer`; nunca lança);
  `static exception(\Throwable $e): void` (descreve **no flush**: guarda o objeto; ignora o mesmo objeto repetido via `spl_object_id`; nunca lança);
  `static events(): array` — `['queries'=>list, 'exceptions'=>list, 'dropped'=>int]` (para testes/diagnóstico; `[]` se desligado);
  `static useFatalProvider(?callable $provider)` (seam; padrão `error_get_last`; `reset()` limpa);
  `flush()` passa a: ler o erro fatal (tipos `E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR|E_USER_ERROR|E_RECOVERABLE_ERROR`; outros tipos são ignorados) e, se houver, registrá-lo como exceção `FatalError` e **forçar `status = max(status, 500)`**; passar a `Sampler::weight` `hasException` e `maxQueryMs` reais; decidir (peso/breaker) → `finishResponse()` → **só então** descrever exceções, normalizar SQL e montar `requests` (com `dropped_events`), `queries` e `exceptions` (todas com o mesmo `sample_weight`), `sql_text` ≤ 2000, `sql_hash` via `SqlNormalizer::hash`, `ts` UTC do evento; gravar o lote único.
  `reset()` limpa o buffer e as exceções pendentes.

- [ ] **Step 1: Write the failing test** (`MonitorEventsTest`, com sink espião, `Config::fake(['enabled'=>true,'sample'=>['normal'=>0.0]])`, `boot('GET')`):
  - `it('records queries and normalizes them at flush')`: `queryEnd(queryStart(),"SELECT * FROM t WHERE id = 5 AND n = 'bob'",'default')`, `flush(200, 0.99)` → como a request foi mantida? **não** — esta com `sample.normal=0` e sem lenta/erro: nada gravado; use `sample.normal=1.0` para este teste → lote com 1 linha em `queries`: `sql_text==='SELECT * FROM t WHERE id = ? AND n = ?'`, `sql_hash` = `sha1` desse texto, `connection_name==='default'`, `request_id` igual, `sample_weight` igual ao do request.
  - `it('keeps a request whose query was slow even if the query was dropped')`: `max_events_per_request=1`; duas queries, a segunda simulada com 450 ms (use um seam de relógio ou chame `queryEnd` com `$start` = `hrtime(true) - 450_000_000`); `sample.normal=0` → o request É gravado (peso 1), `dropped_events===1`, uma linha em `queries`.
  - `it('keeps a request with an exception and writes it')`: `exception(new RuntimeException('boom'))`, `sample.normal=0` → gravado com 1 linha em `exceptions` (`exception_class`, `trace_hash` 40 hex, `trace` não vazio); a mesma instância passada duas vezes → 1 linha.
  - `it('records a fatal error and forces status 500')`: `useFatalProvider(fn()=>['type'=>E_ERROR,'message'=>"x\nStack trace:\n#0 y('s')",'file'=>'/f.php','line'=>3])`, `flush(200)` → linha em `requests` com `status 500`, linha em `exceptions` com `exception_class 'FatalError'`, sem `'s'` no texto; um aviso (`E_WARNING`) no provider é ignorado (status continua 200 e nada em `exceptions`).
  - `it('does not normalize or describe before the response is released')`: o finisher (seam `useFinisher`) registra a contagem de `SqlNormalizer`... **alternativa verificável:** o finisher captura `Monitor::events()` → ainda contém SQL **cru** (com `5`) no momento do finisher, e o lote gravado depois contém o SQL normalizado.
  - `it('is a no-op for queryStart/queryEnd/exception when disabled')`: `queryStart()` null; `events()===[]`.
  - `it('never throws from the new hooks')`: `queryEnd(123, "\xff\xfe")`, `exception` com exceção cujo `getMessage()` é enorme → sem erro.
  - atualizar os testes existentes que fixam `dropped_events===0` e o formato do lote conforme necessário.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** em `Monitor.php` (buffer criado sob demanda com `Config::get('max_events_per_request')`).
- [ ] **Step 4:** rodar `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): registro de queries, exceções e erros fatais`.

---

### Task 6: Esquema da fase 1 e auto_migrate cobrem as tabelas novas

**Files:**
- Modify: `tests/Integration/Monitor/AutoMigrateTest.php` (somente testes novos; código só se um teste falhar de verdade)

**Interfaces:**
- Consumes: `Monitor::flush`, `Installer`, `AutoMigrator`, `DatabaseSink::schemaInstalled()`.

- [ ] **Step 1: Write the tests** (SQLite em arquivo, fluxo real com `Monitor` + `DatabaseSink`):
  - `it('fails safely on a phase-1 schema without auto_migrate')`: instalar o esquema, `DROP` das tabelas `psf_monitor_queries` e `psf_monitor_exceptions`; request com 1 query e 1 exceção; 3 `flush()` não lançam; o breaker abre, o log tem uma linha `[PSF][monitor]` com `monitor:install`; **nada** foi gravado em `psf_monitor_requests` (a transação do lote foi desfeita).
  - `it('installs the new tables with auto_migrate in dev and rewrites the batch')`: mesmo cenário com `auto_migrate=true` (env dev) → após o flush as 5 tabelas existem e há 1 linha em cada uma das três de eventos.
- [ ] **Step 2:** rodar `./vendor/bin/pest tests/Integration/Monitor/AutoMigrateTest.php`. Se passarem de primeira, registrar no relatório; se falharem, corrigir o código mínimo (a causa mais provável é `schemaInstalled()` / `Installer::TABLES`).
- [ ] **Step 3:** `./vendor/bin/pest` → PASS.
- [ ] **Step 4: Commit** — `test(monitor): atualização do esquema da fase 1 para a fase 2`.

---

### Task 7: Ganchos de query nos shims de banco

**Files:**
- Modify: `src/database/Read.php`, `src/database/Create.php` (`exe` e `batch`), `src/database/Update.php`, `src/database/Delete.php`
- Create: `tests/Integration/Monitor/QueryHooksTest.php`

**Interfaces:**
- Consumes: `Monitor::queryStart(): ?int`, `Monitor::queryEnd(?int $start, string $sql, string $connection)`, `Monitor::exception(\Throwable)`.
- Regras:
  - Cada execução de statement é medida **em volta do `execute()`** (e do `fetchAll()` em `Read`), com o **texto SQL** (antes de ser substituído pelo `PDOStatement`; em `Read` é `$this->select`; em `Create::batch` o SQL de cada chunk; guardar a string numa variável local antes do `prepare`) e a chave do banco (`$database`).
  - `queryEnd` roda **também quando o statement falha** e **antes** de `explodeException()` (que termina com `exit`): registrar no `catch`, não só no `finally`.
  - Em `Read::execute` e `Update::exe` (que chamam `explodeException`), chamar `Monitor::exception($e)` no `catch` antes dela; em `Create`/`Delete` (que relançam o `PDOException`) **não** registrar a exceção (o `Router` a registra); o `Monitor` ignora o mesmo objeto duas vezes.
  - Comportamento original idêntico: mesma exceção, mesmo retorno, mesmo `rollBack`.
  - `Read` precisa guardar a chave do banco (propriedade privada nova) para usá-la em `execute()`.

- [ ] **Step 1: Write the failing test** (`QueryHooksTest`; SQLite `:memory:` do harness de Integration + `Config::fake(['enabled'=>true])`; ler com `Monitor::events()`; limpar com `Monitor::reset()`/`Config::reset()` no `afterEach`):
  - `it('records a read query with its connection')`: `(new Read)->exe('fake_users', 'WHERE id = :id', 'id=1')` → `events()['queries']` com 1 item cujo `sql` contém `fake_users`, `connection==='default'`, `duration_ms >= 0`.
  - `it('records create, create batch, update and delete statements')`: `Create::exe`, `Create::batch` (2 linhas), `Update::exe`, `Delete::exe` → 5 queries registradas na ordem, cada uma com o SQL do tipo certo (`INSERT`, `INSERT`, `UPDATE`, `DELETE`) — usar `str_starts_with(strtoupper(...))`.
  - `it('records a failing statement once and keeps the original behaviour')`: `Read` numa coluna inexistente → continua lançando `HttpResponseException` (PSF_TESTING) como antes; `events()['queries']` tem 1 item e `events()['exceptions']` tem 1 item (PDOException); `Create::exe` com violação (coluna NOT NULL ausente) → continua lançando `PDOException`; `events()['exceptions']` fica **vazio** (relançada; quem registra é o Router) mas a query está registrada.
  - `it('is inert when the monitor is disabled')`: com o monitor desligado nenhuma operação muda de resultado e `events()===[]`.
  - `it('does not instrument schema introspection')`: `Connect::getColunsForTable` / `listTables` não geram eventos.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** os ganchos.
- [ ] **Step 4:** `./vendor/bin/pest` (suíte inteira) → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): ganchos de query nos shims de banco`.

---

### Task 8: Gancho de exceção no Router

**Files:**
- Modify: `src/http/Router.php` (`dispatchController`), `tests/Unit/Http/RouterTest.php`

**Interfaces:**
- Consumes: `Monitor::exception(\Throwable)`.
- Regra: no `catch (\Exception $e)` de `dispatchController`, **depois** de decidir que a exceção não é 4xx explícita e **antes** de embrulhar em `new \Exception("Erro ao executar a rota.", 500, $e)`, chamar `Monitor::exception($e)` (a exceção original, com a cadeia). `HttpResponseException` e exceções com código 4xx **não** são registradas.

- [ ] **Step 1: Write the failing test** (`RouterTest`, `Config::fake(['enabled'=>true])`, `Monitor::reset()`/`Config::reset()` no `afterEach` já existente; usar `FakeController::boom` e `forbidden`/`respond` existentes):
  - `it('records the original exception when the route fails with a 5xx')`: rota `boom` → `callMethodRoute` lança `Exception` "Erro ao executar a rota."; `Monitor::events()['exceptions']` tem 1 item (tipo `RuntimeException`).
  - `it('does not record 4xx exceptions')`: `forbidden` (código 403) → nenhuma exceção registrada.
  - `it('does not record deliberate http responses')`: `respond` (`HttpResponseException`) → nenhuma exceção registrada.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** a chamada única.
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): registro de exceções no Router`.

---

### Task 9: Documentação e verificação final

**Files:**
- Modify: `docs/observabilidade.md`, `docs/melhorias.md`, `CLAUDE.md`

- [ ] **Step 1:** Atualizar `docs/observabilidade.md` (pt-BR): esquema de `psf_monitor_queries` e `psf_monitor_exceptions` como **contrato** (colunas, tipos, índices); regras de normalização do SQL (com exemplos); trace sem argumentos, cadeia `previous`, limites (500 / 16 KB / teto de 20 exceções), erro fatal (`FatalError`, status forçado a 500, só primeira linha da mensagem); `dropped_events` e o teto `max_events_per_request`; `slow_ms.query` agora efetivo (mantém o request com peso 1); remover/ajustar as notas "aceita mas sem efeito" e "após erro fatal `http_response_code()` pode ler 200"; **nota de atualização**: ao atualizar da fase 1, rodar `vendor/bin/phinx` + `monitor:install` (idempotente) — sem isso a gravação falha com o breaker aberto; com `auto_migrate` em dev as tabelas novas são criadas sozinhas; custo: normalização só no flush, depois da resposta; limitação conhecida: SQL com literais não é reconhecível por um tokenizador (dialetos exóticos) e é gravado no melhor esforço.
- [ ] **Step 2:** `docs/melhorias.md` (entrada da fase 2) e `CLAUDE.md` (seção do monitor: `Buffer`, `SqlNormalizer`, `ExceptionDescriber`, ganchos nos shims, `Monitor::events()`, `useFatalProvider`).
- [ ] **Step 3:** Rodar `./vendor/bin/pest` (todos verdes; informar a contagem) e `composer analyse`.
- [ ] **Step 4: Verificação manual** (script só no scratchpad `/private/tmp/claude-501/-Users-theodoropaim-Development-php-micro/e43280d4-db99-42b8-a94e-97bb96bb6f65/scratchpad/`): SQLite em arquivo, `monitor.enabled` + `auto_migrate` + `sample.normal=1.0`; `boot`, executar `Create::exe`/`Read::exe` reais numa tabela de teste, `Monitor::exception(new RuntimeException('x'))`, `flush(200)`; consultar as 3 tabelas e imprimir. Esperado: 1 linha em `requests` (`dropped_events=0`), ≥ 2 em `queries` com SQL normalizado e `sql_hash` de 40 hex, 1 em `exceptions` com `trace` não vazio.
- [ ] **Step 5: Commit** — `docs(monitor): queries e exceções (fase 2)`.

---

## Self-Review

- **Cobertura do spec (Fase 2):** `Buffer` (T1); `SqlNormalizer` (T2); exceções com trace completo sem argumentos e cadeia (T3); tabelas `queries`/`exceptions` e migrations (T4); ganchos em `Read/Create/Update/Delete` (T7) e em `dispatchController` (T8); captura de fatal (T5); manutenção de request por query lenta e `dropped_events` (T5); prune das novas tabelas (T4); atualização do esquema da fase 1 e `auto_migrate` (T4, T6); docs (T9). Fora desta fase: `Histogram`, `Rollup`, `Reader`, `monitor:rollup/maintain` (fase 3), chamadas externas e controller JSON (fase 4).
- **Consistência de tipos:** `Monitor::queryStart(): ?int`/`queryEnd(?int, string, string)`, `Buffer::addQuery(string, float, string, int)`, `ExceptionDescriber::describe/fatal` → mesmos campos das colunas de `psf_monitor_exceptions`; `Sampler::weight` com os parâmetros que já existiam.
- **Proporção:** o plano define interfaces e testes; corpos ficam para quem implementa.
