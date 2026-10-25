# Observabilidade — Fase 3 (Rollups e Reader) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar os eventos brutos em horas (`psf_monitor_rollups`) por um job idempotente (`monitor:rollup` / `monitor:maintain`), podar os agregados antigos e expor os dados por uma API de leitura estável (`Psf\Monitor\Reader`) com percentis estimados.

**Architecture:** `Rollup` recalcula uma hora inteira a partir das tabelas brutas (SQL agrupado e portável, somas ponderadas por `sample_weight`), substitui os registros da hora numa transação e só então avança o watermark (`psf_monitor_state.rollup_watermark`). `Histogram` define os buckets de latência (fonte única das bordas) e estima percentis por interpolação. `Pruner` passa a podar agregados e respeita o watermark. `Reader` lê só dos agregados (rápido, 90 dias) e das tabelas brutas apenas no detalhe por `request_id`. Os comandos `monitor:rollup` e `monitor:maintain` (rollup → prune) são o que o projeto agenda no cron.

**Tech Stack:** PHP 8.1+, PDO (MySQL/PostgreSQL/SQL Server/SQLite), Symfony Console, Pest 3, PHPStan nível 1.

**Spec:** `docs/superpowers/specs/2026-10-24-observabilidade-design.md` (esta é a "Fase 3": "Semântica dos agregados", "Histograma", "Manutenção e retenção", "Extração (Reader)"). Base: fases 1 e 2 já implementadas em `feat/monitor-observabilidade`.

## Global Constraints

- PHP `>=8.1`; nenhuma assinatura pública existente muda (o `monitor:prune` ganha uma linha de saída; as linhas existentes continuam iguais); nada novo no caminho quente do request: **toda** a fase roda em CLI/cron ou em leitura.
- Namespace `Psf\Monitor\` → `src/monitor/`. Comentários, mensagens de CLI e `docs/` em **português do Brasil**. Identificadores SQL via `DialectInterface::quoteIdentifier()`; valores sempre por parâmetro; nada de `LIMIT`/aspas fixos.
- Timestamps em **UTC** (`Y-m-d H:i:s`). `bucket_hour` = início da hora (`...:00:00`).
- **Semântica dos agregados (contrato):** `hits = Σ sample_weight`; `errors` = `Σ sample_weight` das linhas com **status ≥ 500** (requests) ou de todas as linhas (exceções); queries têm `errors = 0`; `sum_ms = Σ sample_weight × duration_ms` (requests e queries; exceções `0`); `max_ms` = maior `duration_ms` entre as linhas **gravadas**; `histogram` = JSON com 10 contagens ponderadas por `sample_weight`; `sample_text` = SQL normalizado (queries) ou mensagem (exceções), de **uma** linha da hora; `last_ts` = maior `ts` da hora. `metric_key`: requests `METHOD route`; queries `sql_hash`; exceções `exception_class`; valores > 191 caracteres são truncados (mb-safe).
- **Histograma:** bordas fixas em ms `[10, 25, 50, 100, 250, 500, 1000, 2500, 5000]` → 10 buckets (`[0,10)`, `[10,25)`, …, `[2500,5000)`, `[5000,∞)`); o bucket `i` contém `edges[i-1] <= d < edges[i]`; percentis por interpolação linear dentro do bucket (aproximação, documentada).
- **Rollup:** só horas **completas** e com folga de **300 s** (`Rollup::GRACE_SECONDS`) depois do fim da hora, para requests que cruzam a virada; cada hora é recalculada do zero (delete + insert numa transação junto com a atualização do watermark); o watermark (`rollup_watermark`, `Y-m-d H:i:s` UTC no início da próxima hora a processar) nunca passa de dados ainda não agregados; no primeiro uso começa na hora mais antiga que ainda existir nas tabelas brutas; máximo de **48 horas por execução** (`--max-hours`, padrão 48), para um backlog grande ser consumido em várias execuções.
- **Retenção:** `retention.aggregate_days` (padrão 90; inteiro ≥ 1, mesma validação de `raw_days`) poda `bucket_hour < now − aggregate_days`; o prune bruto continua `min(now − raw_days, watermark)`.
- Testes: `./vendor/bin/pest` (base atual: 505 passando) e `composer analyse` limpos ao fim de cada tarefa; testes de integração com SQLite (arquivo/`:memory:` do harness). Commits em português, sem linhas de co-autoria nem rodapés de geração; nunca commitar `.superpowers/`.

## Review Focus

1. Request que cruza a virada da hora e flush atrasado: nada se perde nem é contado em dobro; reexecutar a mesma hora gera exatamente os mesmos números (idempotência) (Task 3).
2. Pesos fracionários (`10.0`, `3.3333`), horas sem linhas, strings decimais vindas do driver e erros só para status ≥ 500 (Task 3 e 4).
3. Percentis: toda a massa num único bucket, bucket aberto `[5000,∞)`, histograma vazio ou JSON corrompido, `p` fora de `[0,100]` (Task 1 e 5).
4. Backlog grande e falha no meio do job: progresso por hora persistido, watermark nunca à frente do agregado, prune nunca apaga hora sem agregado; e o caso inverso — rollup parado por semanas **não** deixa as tabelas brutas crescerem sem limite (Task 2, 3 e 6).
5. `Reader` com entrada estranha: intervalo invertido, `from == to`, `limit` ≤ 0 ou enorme, `DateTime` com fuso, coluna `orderBy` fora da lista permitida (sem injeção de SQL), registros com histograma corrompido (Task 5).

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `src/monitor/Histogram.php` | bordas, índice de bucket, merge, (de)serialização JSON e percentis |
| `src/monitor/Watermark.php` | leitura/escrita do `rollup_watermark` em `psf_monitor_state` |
| `src/monitor/Rollup.php` | agrega horas completas das tabelas brutas em `psf_monitor_rollups` |
| `src/monitor/Reader.php` | API de leitura dos agregados e do detalhe de um request |
| `src/monitor/Command/MonitorRollup.php`, `MonitorMaintain.php` | comandos novos |
| Modificados: `Pruner.php`, `Command/MonitorPrune.php`, `Command/MonitorStatus.php`, `bin/phinx` | integração |
| Testes novos: `tests/Unit/Monitor/HistogramTest.php`, `tests/Integration/Monitor/{Watermark,Rollup,RollupMetrics,Reader,RollupCommands}Test.php`; atualizados `PrunerTest.php`, `CommandsTest.php` | |
| Docs: `docs/observabilidade.md`, `docs/melhorias.md`, `CLAUDE.md` | |

---

### Task 1: Histogram

**Files:**
- Create: `src/monitor/Histogram.php`, `tests/Unit/Monitor/HistogramTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\Histogram` —
  `public const EDGES = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000];`
  `static bucketCount(): int` (10); `static bucketIndex(float $ms): int` (valores negativos/NaN → 0; `>= 5000` → 9);
  `static empty(): array` (10 zeros); `static merge(array $a, array $b): array` (soma posição a posição; tolera tamanhos errados completando com 0);
  `static encode(array $counts): string` (JSON `[w0,...,w9]`, floats); `static decode(?string $json): array` (JSON inválido, tamanho errado ou valores não numéricos/negativos → `empty()`; nunca lança);
  `static percentile(array $counts, float $p, ?float $maxMs = null): float` — `$p` em `[0,100]` (fora disso é limitado); total 0 → `0.0`; interpola linearmente entre as bordas do bucket que contém o percentil; no bucket aberto `[5000,∞)` devolve `max(5000, ...)`: usa `$maxMs` como limite superior quando informado e `>= 5000`, senão `5000.0`; resultado nunca excede `$maxMs` quando este é informado.

- [ ] **Step 1: Write the failing test** (`HistogramTest`):
  - `it('maps durations to buckets on the edges')`: `0→0`, `9.99→0`, `10→1`, `24.9→1`, `25→2`, `999.9→6`, `1000→7`, `4999→8`, `5000→9`, `1e9→9`, `-3→0`, `NAN→0`.
  - `it('merges and round-trips')`: `merge` de dois vetores; `decode(encode($c)) == $c`.
  - `it('decodes garbage as empty')` (dataset): `null`, `''`, `'oops'`, `'[1,2]'`, `'{"a":1}'`, `'[1,2,3,4,5,6,7,8,9,"x"]'`, `'[-1,0,0,0,0,0,0,0,0,0]'` → `Histogram::empty()`.
  - `it('estimates percentiles by linear interpolation')`: toda a massa no bucket `[50,100)` (contagem 100): `p50 == 75.0`, `p0 == 50.0`, `p100 == 100.0`; massa metade em `[0,10)` e metade em `[100,250)`: `p25 == 5.0`, `p75 == 175.0`.
  - `it('handles the open bucket')`: toda massa no bucket 9: `percentile(..., 95) == 5000.0`; com `maxMs: 8000` → `percentile(..., 100) == 8000.0` e `p50` entre 5000 e 8000; com `maxMs: 3000` (inconsistente) → `5000.0`.
  - `it('is safe on empty or out-of-range input')`: histograma vazio → `0.0`; `p = -5` e `p = 500` não lançam e dão os extremos.
  - `it('never exceeds maxMs')`: `maxMs: 60` com massa em `[50,100)` → `p100 == 60.0`.
- [ ] **Step 2:** `./vendor/bin/pest tests/Unit/Monitor/HistogramTest.php` → FAIL.
- [ ] **Step 3: Implement** `Histogram`.
- [ ] **Step 4:** rodar o teste → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): histograma de latência e percentis estimados`.

---

### Task 2: Watermark, Pruner de agregados e teto do prune bruto

**Files:**
- Create: `src/monitor/Watermark.php`, `tests/Integration/Monitor/WatermarkTest.php`
- Modify: `src/monitor/Pruner.php`, `tests/Integration/Monitor/PrunerTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\Watermark` — `__construct(\PDO $pdo, DialectInterface $dialect)`; `read(): ?string` (valor `Y-m-d H:i:s` validado por round-trip; ausente/inválido/sem tabela → `null`, nunca lança); `write(string $value): void` (insere ou atualiza a chave `rollup_watermark` de forma portável: `UPDATE` e, se `rowCount() === 0` e a chave não existir, `INSERT`; lança em falha de banco).
- `Pruner` passa a usar `Watermark` (remover o `readWatermark` privado; comportamento atual preservado) e ganha:
  `pruneAggregates(\DateTimeImmutable $now, int $aggregateDays): int` — `aggregateDays < 1` → `\InvalidArgumentException` (mensagem em português, antes de qualquer consulta); apaga `psf_monitor_rollups` com `bucket_hour < now − aggregateDays dias` (UTC) e devolve as linhas apagadas;
  **teto contra rollup parado:** `pruneRaw()` nunca deixa linhas brutas com `ts < now − (4 × rawDays) dias` (constante `Pruner::HARD_CEILING_FACTOR = 4`), **mesmo que o watermark esteja mais atrás** — o corte efetivo é `max(min(now − rawDays, watermark), now − 4×rawDays)`.

- [ ] **Step 1: Write the failing test:**
  - `WatermarkTest`: `it('is null without a row')`; `it('writes then reads')` (`write('2026-01-02 03:00:00')` → `read()` igual); `it('overwrites')` (duas escritas → uma linha); `it('rejects garbage as null')` (linha com valor `'xyz'` e `'2026-13-45 00:00:00'` → `null`); `it('is null when the state table is missing')`.
  - `PrunerTest`: `it('prunes aggregates older than aggregate_days')` (linhas com `bucket_hour` −100 d, −91 d, −89 d, `aggregateDays=90` → apaga 2, mantém 1; retorno 2); `it('rejects aggregate_days below 1')` (0 e −3 → exceção, nada apagado); `it('does not let a stuck rollup grow the raw tables without bound')`, com `rawDays=7` (teto = 28 d) e linhas brutas em −40 d, −20 d, −8 d, −1 d: **(a)** watermark em −60 d → o corte normal seria −60 d, mas o teto o eleva a −28 d: apaga só a de −40 d e mantém −20 d, −8 d, −1 d; **(b)** watermark em −10 d → corte normal = min(−7 d, −10 d) = −10 d (acima do teto): apaga −40 d e −20 d e mantém −8 d e −1 d; **(c)** sem watermark → corte −7 d: apaga −40 d, −20 d e −8 d, mantém −1 d. A regra exata é `corte efetivo = max(min(now − rawDays, watermark), now − 4×rawDays)`. `it('keeps the existing watermark behaviour')` (os testes atuais do watermark continuam verdes sem alteração).
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** `Watermark`, refatorar `Pruner`, adicionar `pruneAggregates` e o teto.
- [ ] **Step 4:** `./vendor/bin/pest` (suíte inteira) → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): watermark, prune de agregados e teto contra rollup parado`.

---

### Task 3: Rollup — motor e métrica de requests

**Files:**
- Create: `src/monitor/Rollup.php`, `tests/Integration/Monitor/RollupTest.php`

**Interfaces:**
- Consumes: `Histogram`, `Watermark`, `DialectInterface` (quoting), `psf_monitor_requests`, `psf_monitor_rollups`, `psf_monitor_state`.
- Produces: `final class Psf\Monitor\Rollup` —
  `public const GRACE_SECONDS = 300;`
  `__construct(\PDO $pdo, DialectInterface $dialect)`;
  `run(\DateTimeImmutable $now, int $maxHours = 48): array` → `['hours' => int, 'rows' => int, 'watermark' => ?string]` (horas processadas, registros gravados, watermark final). `maxHours < 1` → `\InvalidArgumentException`.
  Algoritmo: `start` = watermark (se houver) senão início da hora do menor `ts` entre as tabelas brutas (sem linhas e sem watermark → nada a fazer, `watermark` `null`); processa horas `h` em ordem enquanto `h + 1h + GRACE_SECONDS <= now` e `hours < maxHours`; **cada hora numa transação**: `DELETE` dos registros da hora em `rollups`, `INSERT` dos agregados recalculados e `Watermark::write(h + 1h)`; falha numa hora → rollback dela, exceção propaga, as horas anteriores ficam gravadas.
  Métrica `request` (esta tarefa): `SELECT method, route, SUM(sample_weight) hits, SUM(CASE WHEN status >= 500 THEN sample_weight ELSE 0 END) errors, SUM(sample_weight * duration_ms) sum_ms, MAX(duration_ms) max_ms, MAX(ts) last_ts, <10 × SUM(CASE WHEN duration_ms >= lo AND duration_ms < hi THEN sample_weight ELSE 0 END)> FROM psf_monitor_requests WHERE ts >= :from AND ts < :to GROUP BY method, route` (as bordas vêm de `Histogram::EDGES`; valores decimais chegam como string — converter para float); `metric_key = "METHOD route"` truncado em 191 (mb-safe); `histogram = Histogram::encode(...)`; `sample_text = null`.

- [ ] **Step 1: Write the failing test** (`RollupTest`, schema instalado em SQLite; inserir linhas de `requests` direto por PDO com `ts`, `method`, `route`, `status`, `duration_ms`, `sample_weight`; `now` fixo):
  - `it('aggregates one complete hour weighted by sample_weight')`: 3 linhas em `10:05`–`10:40` na mesma rota (`GET users/{id:int}`): `(status 200, 40 ms, w 1.0)`, `(200, 700 ms, w 10.0)`, `(500, 3000 ms, w 1.0)`; `now = 11:06:00` → 1 registro `bucket_hour='… 10:00:00'`, `metric='request'`, `metric_key='GET users/{id:int}'`, `hits=12.0`, `errors=1.0`, `sum_ms=40+7000+3000=10040.0`, `max_ms=3000.0`, `last_ts` = o maior `ts`, histograma `[0,0,1,0,0,0,10,0,1,0]` (40 ms → bucket 2; 700 → 6; 3000 → 8: ajustar os índices exatos pelas bordas e assertar o vetor completo), retorno `hours=1`, `watermark='… 11:00:00'`.
  - `it('does not process the current hour nor the grace period')`: `now = 11:04:59` → nada processado (`hours 0`, nenhum registro, watermark inalterado); `now = 11:05:00` → processa a hora 10.
  - `it('is idempotent')`: rodar duas vezes (a 2ª com o watermark apagado manualmente) → os mesmos registros e números; inserir uma linha atrasada em `10:59:58` antes da 2ª execução com o watermark apagado → ela entra na hora 10 e nada é duplicado.
  - `it('keeps requests that cross the hour boundary in the hour of their ts')`: linhas `10:59:59` e `11:00:00` → vão para os buckets 10 e 11.
  - `it('starts at the oldest raw row when there is no watermark and advances per hour')`: linhas em 08:xx e 10:xx, `now = 11:10` → processa 08, 09 (vazia: sem registros), 10; `hours=3`; watermark `11:00`.
  - `it('caps the work per run and resumes')`: linhas em 5 horas, `maxHours=2` → `hours=2`, watermark avançado 2 horas; nova execução continua do watermark.
  - `it('persists progress when a later hour fails')`: forçar falha na 2ª hora (ex.: injetar uma linha de rollup conflitante ou um `PDO` de teste que lança no `INSERT` da 2ª hora) → a 1ª hora gravada, watermark = início da 2ª hora, exceção propagada, nada parcial da 2ª hora.
  - `it('handles an hour with no rows and an empty database')`: sem linhas → `['hours'=>0,'rows'=>0,'watermark'=>null]`.
  - `it('truncates a long metric_key')`: `method='GET'` + rota de 191 caracteres → `mb_strlen(metric_key) === 191`.
  - `it('rejects maxHours below 1')`.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** `Rollup` (métrica de requests, laço de horas, transação, watermark).
- [ ] **Step 4:** rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): rollup por hora dos requests`.

---

### Task 4: Rollup — métricas de queries e exceções

**Files:**
- Modify: `src/monitor/Rollup.php`
- Create: `tests/Integration/Monitor/RollupMetricsTest.php`

**Interfaces:**
- Consumes: as tabelas `psf_monitor_queries` (`sql_hash`, `sql_text`, `duration_ms`, `sample_weight`, `ts`, `id`) e `psf_monitor_exceptions` (`exception_class`, `message`, `sample_weight`, `ts`, `id`).
- Produz, na mesma transação da hora: metric `query` (`metric_key = sql_hash`; `hits`, `errors = 0`, `sum_ms`, `max_ms`, `histogram`, `last_ts` como em requests; `sample_text` = `sql_text` da linha de **menor `id`** do hash na hora) e metric `exception` (`metric_key = exception_class`; `hits = Σ sample_weight`, `errors = Σ sample_weight`, `sum_ms = 0`, `max_ms = 0`, `histogram = Histogram::encode(Histogram::empty())`, `last_ts`; `sample_text` = `message` da linha de **menor `id`** da classe na hora, mb-cortada em 255 se a coluna `text` do rollup exigir — não exige: manter inteira). O `sample_text` é obtido sem `MAX()` sobre colunas `text`: primeiro `SELECT key, MIN(id) … GROUP BY key`, depois `SELECT id, <coluna> FROM … WHERE id IN (...)` em blocos de até 500 ids.

- [ ] **Step 1: Write the failing test** (`RollupMetricsTest`, mesma harness):
  - `it('aggregates queries by sql_hash with a sample text')`: duas linhas do hash `A` (ids 1 e 2, `sql_text` diferentes só para provar a escolha pelo menor id, pesos `1.0` e `4.0`, `20 ms` e `300 ms`) e uma do hash `B` → registros `metric='query'` para `A` (`hits=5.0`, `errors=0.0`, `sum_ms=20+1200=1220.0`, `max_ms=300.0`, `sample_text` = texto do id 1) e `B`.
  - `it('aggregates exceptions by class')`: 3 linhas de `RuntimeException` (pesos `1`, `1`, `8`) e 1 de `PDOException` → `hits=10.0`/`errors=10.0` e `1.0`; `sum_ms 0.0`, `max_ms 0.0`, histograma de zeros, `sample_text` = mensagem do menor id.
  - `it('writes the three metrics for the same hour and replaces all of them on re-run')`: requests + queries + exceptions na hora 10 → 3 tipos de metric; reprocessar (watermark apagado) → mesma contagem de registros e mesmos números.
  - `it('chunks the sample text lookup')`: 1200 hashes distintos na hora → 1200 registros `query`, todos com `sample_text` não nulo.
  - `it('ignores metric rows of other hours')`: linhas fora da hora não entram.
  - `it('keeps decimals exact enough')`: pesos `3.3333` e `6.6667` → `hits == 10.0` (comparação com tolerância `1e-4`).
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** as duas métricas em `Rollup`.
- [ ] **Step 4:** `./vendor/bin/pest` → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): rollup de queries e exceções`.

---

### Task 5: Reader

**Files:**
- Create: `src/monitor/Reader.php`, `tests/Integration/Monitor/ReaderTest.php`

**Interfaces:**
- Consumes: `Connection::open`, `Config::database()`, `Histogram`, tabelas de rollup e brutas.
- Produces: `final class Psf\Monitor\Reader` — somente leitura, retorna arrays simples; `__construct(?string $database = null, ?\PDO $pdo = null)` (a conexão é aberta sob demanda; `$pdo` serve a testes). Intervalos são `DateTimeInterface`, convertidos para **UTC**; um registro de rollup entra quando `bucket_hour >= hora(from)` e `bucket_hour < to`; `from > to` e `from == to` devolvem lista vazia; `$limit` é limitado a `[1, 1000]`.
  `routes(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20, string $orderBy = 'sum_ms'): array` — por `metric_key` de `request`: `['key','method','route','hits','errors','error_rate','avg_ms','max_ms','p50_ms','p95_ms','p99_ms']`; `orderBy` ∈ `sum_ms|hits|errors|max_ms|avg_ms` (qualquer outro → `sum_ms`), sempre decrescente; somas em SQL (`SUM(hits)`, `SUM(errors)`, `SUM(sum_ms)`, `MAX(max_ms)`), histogramas lidos **só dos top-N** e fundidos em PHP; `avg_ms = sum_ms / hits` (0 se `hits` 0); `error_rate = errors / hits`; `method`/`route` separados no primeiro espaço de `key`.
  `slowQueries(from, to, int $limit = 20): array` — `['sql_hash','sql','hits','avg_ms','max_ms','p95_ms']` (ordem por `sum_ms` decrescente; `sql` = `sample_text` da hora mais recente do hash).
  `exceptions(from, to, int $limit = 20): array` — `['exception_class','hits','last_ts','message']` (ordem por `hits` decrescente; `last_ts` = maior; `message` = `sample_text` do registro de `last_ts` mais recente).
  `throughput(from, to): array` — lista por hora (ordem crescente) `['hour','hits','errors','avg_ms']` da metric `request`.
  `request(string $requestId): ?array` — `['request' => linha, 'queries' => [...ordem id], 'exceptions' => [...ordem id]]` das tabelas **brutas**; `null` se não existir (inclusive depois da retenção bruta).
  Tolerância: histograma corrompido → `Histogram::decode` → vazio (percentis `0.0`), nunca lança; falha de banco propaga como `\PDOException`.

- [ ] **Step 1: Write the failing test** (`ReaderTest`; popular `psf_monitor_rollups` por PDO com 3 horas × 3 rotas e uma tabela de queries/exceções; usar `Rollup` real em um teste de ponta a ponta):
  - `it('ranks routes by total time with merged percentiles')`: 2 horas da mesma rota com histogramas conhecidos → `hits`/`errors`/`avg_ms`/`max_ms`/`p95_ms` esperados (calcular com `Histogram::percentile` do vetor somado); `limit=1` devolve só a de maior `sum_ms`.
  - `it('orders by the whitelisted column only')`: `orderBy='hits'` reordena; `orderBy="sum_ms; DROP TABLE x"` cai em `sum_ms` e a tabela continua existindo.
  - `it('respects the half-open hour range')`: `from` no meio da hora 10 inclui o bucket 10; `to = 12:00:00` exclui o bucket 12; `to` no fim.
  - `it('returns empty for reversed or empty ranges and clamps limit')`: `from > to`, `from == to` → `[]`; `limit` `0`, `-5`, `1_000_000` não lançam e respeitam `[1,1000]`.
  - `it('converts time zones to UTC')`: `DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('America/Sao_Paulo'))` equivale a `11:00 UTC`.
  - `it('lists slow queries with their sample sql')`; `it('lists exceptions by class with last occurrence and message')`; `it('builds the hourly throughput series in ascending order')`.
  - `it('survives a corrupted histogram')`: registro com `histogram='oops'` → `p95_ms === 0.0`, sem exceção.
  - `it('returns the raw detail of one request')`: request + 2 queries + 1 exceção com o mesmo `request_id` → as 3 partes (queries por `id`); id inexistente → `null`.
  - `it('works end to end after a rollup run')`: `Rollup::run` sobre dados brutos e depois `Reader::routes/slowQueries/exceptions/throughput` coerentes com os números brutos.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** `Reader`.
- [ ] **Step 4:** rodar → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): Reader dos agregados e do detalhe do request`.

---

### Task 6: Comandos `monitor:rollup`, `monitor:maintain`, prune e status

**Files:**
- Create: `src/monitor/Command/MonitorRollup.php`, `src/monitor/Command/MonitorMaintain.php`, `tests/Integration/Monitor/RollupCommandsTest.php`
- Modify: `src/monitor/Command/MonitorPrune.php`, `src/monitor/Command/MonitorStatus.php`, `bin/phinx`, `tests/Integration/Monitor/CommandsTest.php`

**Interfaces:**
- Consumes: `Rollup::run`, `Pruner::pruneRaw/pruneAggregates`, `Watermark::read`, `Config::get('retention.raw_days'|'retention.aggregate_days')`, `Installer`.
- Produces (todos com `-e|--environment <chave de config['db']>`, padrão `Config::database()`; falhas → mensagem em português em `<error>` e exit 1, sem stack trace; sucesso → exit 0):
  - `monitor:rollup [--max-hours=48]` → saída `Horas agregadas: <n>`, `Registros gravados: <n>`, `Rollup até: <watermark|nunca>`; `--max-hours` inválido (não inteiro ≥ 1) → erro e exit 1 sem tocar no banco.
  - `monitor:maintain [--max-hours=48]` → roda o rollup e **depois** os dois prunes; saída: as 3 linhas do rollup + `Linhas apagadas: <n>` + `Agregados apagados: <n>`; se `retention.raw_days` ou `retention.aggregate_days` forem inválidos, **falha antes de qualquer escrita** (nem o rollup roda).
  - `monitor:prune` ganha `Agregados apagados: <n>` **depois** da linha `Linhas apagadas: <n>` existente (que continua só com os brutos) e valida `retention.aggregate_days` (inteiro ou string de dígitos ≥ 1) antes de apagar qualquer coisa.
  - `monitor:status` ganha a linha `Rollup até: <watermark|nunca>` (depois de `Monitor ligado`); falha de leitura do watermark → `nunca`.
  - `bin/phinx` registra `MonitorRollup` e `MonitorMaintain` ao lado dos existentes.

- [ ] **Step 1: Write the failing test** (`CommandTester`, SQLite em arquivo via `monitorUseConfig(['enabled'=>true])`, schema instalado, dados brutos de horas passadas — `now` real, então usar `ts` de 3 horas atrás e de 30 dias atrás):
  - `it('runs the rollup and reports progress')`: `monitor:rollup` → exit 0, `Horas agregadas: 1+`, `Registros gravados: 1+`, `Rollup até:` com data.
  - `it('rejects an invalid --max-hours')` (`0`, `abc`) → exit 1, nada alterado.
  - `it('maintain runs rollup then prune')`: linhas brutas de 30 dias atrás (já agregadas pelo rollup do mesmo comando) e agregados de 100 dias atrás → depois do `monitor:maintain`: agregado novo existe, brutos antigos apagados (`Linhas apagadas: ≥ 1`), agregado de 100 dias apagado (`Agregados apagados: 1`).
  - `it('maintain refuses invalid retention before writing anything')`: `retention.aggregate_days = 0` → exit 1 e `psf_monitor_rollups` continua vazia (o rollup não rodou).
  - `it('prune keeps its existing output and adds the aggregates line')`: as asserções atuais de `Linhas apagadas: 1` continuam e aparece `Agregados apagados: 0`; `retention.aggregate_days = 'abc'` → exit 1 sem apagar.
  - `it('status shows the rollup watermark')`: antes `Rollup até: nunca`; depois do rollup, a data.
  - `it('registers the new commands')` (Application com os 5 comandos `monitor:*` + `has()`).
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** os comandos, as alterações e o registro em `bin/phinx` (`php -l bin/phinx`).
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): comandos monitor:rollup e monitor:maintain`.

---

### Task 7: Documentação e verificação final

**Files:**
- Modify: `docs/observabilidade.md`, `docs/melhorias.md`, `CLAUDE.md`

- [ ] **Step 1:** Atualizar `docs/observabilidade.md` (pt-BR): remover os avisos "chega em fases seguintes" para rollups/`Reader`/comandos; `retention.aggregate_days` passa a ter efeito; seção **Agregados** (contrato da semântica: `hits`, `errors`, `sum_ms`, `max_ms`, `histogram`, `sample_text`, `last_ts`, `metric_key` por métrica; só horas completas + folga de 300 s, portanto o dado agregado atrasa até ~1 h 5 min; recálculo idempotente por hora; limite de 48 h por execução); **Histograma e percentis** (bordas, interpolação, p95 etc. são estimativas; bucket aberto); **Reader** (assinaturas, formatos de retorno, intervalo semiaberto em UTC, `limit` ∈ [1,1000], `orderBy` permitido, `request()` só dentro da retenção bruta, exemplo de uso e de endpoint JSON do projeto); **Rotina de manutenção** (`vendor/bin/phinx monitor:maintain` de hora em hora no cron; `monitor:rollup`/`monitor:prune` isolados; saída dos comandos; o teto de 4× `raw_days` contra rollup parado — dados brutos mais velhos que isso são apagados mesmo sem agregar, com a consequência de perder essas horas); `monitor:status` com `Rollup até`; limites conhecidos (agregados são estimativas ponderadas; `max_ms` só das linhas gravadas; queries/exceções descartadas pelo teto não entram; SQL Server/PostgreSQL/MySQL não exercitados fora da SQLite).
- [ ] **Step 2:** `docs/melhorias.md` (entrada da fase 3) e `CLAUDE.md` (seção do monitor: `Histogram`, `Watermark`, `Rollup`, `Reader`, comandos novos, teto do prune).
- [ ] **Step 3:** `./vendor/bin/pest` (todos verdes; informar a contagem) e `composer analyse`.
- [ ] **Step 4: Verificação manual** (script só no scratchpad `/private/tmp/claude-501/-Users-theodoropaim-Development-php-micro/e43280d4-db99-42b8-a94e-97bb96bb6f65/scratchpad/`): SQLite em arquivo, `monitor.enabled` + `auto_migrate` + `sample.normal=1.0`; gravar requests/queries/exceções reais com `ts` retroativos (inserir direto nas tabelas brutas para horas passadas), rodar `Rollup::run`, depois `Reader::routes/slowQueries/exceptions/throughput/request` e `monitor:maintain` por `CommandTester`; imprimir os resultados. Esperado: registros de rollup para as 3 métricas, percentis coerentes, `Reader::request` devolvendo o detalhe.
- [ ] **Step 5: Commit** — `docs(monitor): rollups, Reader e rotina de manutenção (fase 3)`.

---

## Self-Review

- **Cobertura do spec (Fase 3):** `Histogram` (T1); `Rollup` idempotente com watermark (T3, T4); `monitor:rollup`/`monitor:maintain` (T6); prune de agregados e respeito ao watermark (T2, T6); `Reader` com `routes/slowQueries/exceptions/throughput/request` (T5); semântica dos agregados como contrato (Global Constraints, T3, T4, T7); docs (T7). Fora desta fase: chamadas externas (`metric 'external'`) e controller JSON opcional (fase 4).
- **Decisões minhas a confirmar (marcadas como minhas):** folga de 300 s e limite de 48 h por execução; teto de `4 × raw_days` contra rollup parado (contraria a frase do spec "nunca além do watermark" apenas quando o watermark está mais de `4 × raw_days` atrás de agora; sem esse teto, um cron de rollup parado faria as tabelas brutas crescerem sem limite); `Reader::routes` ordenado por tempo total com `orderBy` permitido; `sample_text` da linha de menor `id` da hora; `monitor:prune` acrescenta `Agregados apagados`; queries com `errors = 0`.
- **Consistência de tipos:** `Histogram::EDGES/empty/encode/decode/merge/percentile` usados do mesmo modo em `Rollup` e `Reader`; `Watermark::read/write` em `Pruner`, `Rollup` e `monitor:status`; `Pruner::pruneAggregates(\DateTimeImmutable, int): int`.
- **Proporção:** o plano define interfaces e testes; corpos ficam para quem implementa.
