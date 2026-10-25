# Observabilidade — Fase 4 (Chamadas externas, API e drivers reais) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar as chamadas externas do request (HTTP, e-mail, storage) com a mesma amostragem, agregação e leitura das queries; expor os dados por um serviço JSON opcional (`MonitorApi`) que o projeto monta com a própria autenticação; e validar o monitor inteiro contra MySQL, PostgreSQL e SQL Server reais.

**Architecture:** `Monitor::trackExternal()` envolve a chamada, mede e guarda o evento no `Buffer` (teto por request); os pontos de chamada (`Request::send`, `Http::request`, `Email::send`, `CloudStorage::*`) ficam com uma linha cada. O `target` gravado é sempre um rótulo seguro (host, host SMTP, `provedor/operação`), nunca URL completa, destinatário ou chave de objeto. `Rollup` ganha a métrica `external`; `Reader` ganha `external()` e o detalhe do request passa a trazer as chamadas. `MonitorApi` é uma classe de serviço (sem rota própria): valida os parâmetros e devolve o mesmo formato que os controllers do framework retornam. A Parte B roda a suíte do monitor em bancos reais via Docker.

**Tech Stack:** PHP 8.1+, PDO (MySQL/PostgreSQL/SQL Server/SQLite), Phinx 0.16, Pest 3, PHPStan nível 1, Docker (só Parte B).

**Spec:** `docs/superpowers/specs/2026-10-24-observabilidade-design.md` (seção "Fases", fase 4; tabela `psf_monitor_external`; "Extração (Reader)"). Base: fases 1–3 em `feat/monitor-fase3`.

## Global Constraints

- PHP `>=8.1`; nenhuma assinatura pública existente muda (parâmetros novos só no fim e opcionais); todos os ganchos são no-op (um booleano) com o monitor desligado e **nunca lançam**, **nunca alteram o retorno nem a exceção** da chamada original.
- Namespace `Psf\Monitor\` → `src/monitor/`. Comentários, mensagens e docs em **português do Brasil**. Identificadores SQL via `quoteIdentifier()`, valores bound, datas pelo `SqlDate`.
- **Privacidade do `target` (≤ 191 caracteres, minúsculas):** HTTP = **somente o host** (sem esquema, usuário:senha, porta, caminho, query, fragmento; IPv6 sem colchetes; URL inválida → `(invalido)`); e-mail = host do servidor SMTP (nunca usuário, remetente, destinatário, assunto); storage = `provedor/operação` (ex.: `r2/putObject`; nunca bucket, chave do objeto nem conta). Nada de header, body, token.
- `kind` ∈ `http`, `mail`, `storage`. `status`: HTTP = código recebido, `null` em falha de transporte (erro do cURL); storage = código HTTP da resposta do SDK (`200` quando ok sem código), `null` sem código em falha; e-mail = `200` em sucesso e `null` em falha. **Erro** (para `errors` no rollup) = `status IS NULL OR status >= 500`; 4xx não é erro.
- Teto: `max_events_per_request` (padrão 200) **por tipo de evento** (queries e externas têm baterias separadas); o excedente é descartado e somado em `dropped_events`; o maior tempo de chamada externa é acompanhado mesmo das descartadas.
- Manutenção do request com peso 1 também quando alguma chamada externa tem `duration ≥ slow_ms.external` (novo, padrão `1000`).
- API: `api.enabled` (novo, padrão `false`) — desligada, `MonitorApi` responde 404 sem consultar o banco.
- Esquema `psf_monitor_external` (migration `20261026000001_psf_monitor_create_external.php`, classe `PsfMonitorCreateExternal`, mesmo padrão de `id` das outras): `id`, `request_id` string(64), `ts` datetime, `kind` string(16), `target` string(191), `duration_ms` decimal(10,2), `status` integer **null**, `sample_weight` decimal(12,4); índices `idx_psf_monitor_external_request_id (request_id)`, `idx_psf_monitor_external_ts (ts)`, `idx_psf_monitor_external_kind_target_ts (kind, target, ts)`.
- Rollup `metric='external'`: `metric_key = "<kind>:<target>"` (truncado em 191), `hits = Σ sample_weight`, `errors = Σ sample_weight` das linhas com erro (regra acima), `sum_ms`, `max_ms`, histograma como nas outras métricas, `last_ts`, `sample_text = null`.
- Testes: `./vendor/bin/pest` (base atual: 642 passando) e `composer analyse` limpos ao fim de cada tarefa da Parte A. **Commits em português, sem linhas de co-autoria nem rodapés de geração** (instrução explícita do usuário); nunca commitar `.superpowers/`.

## Review Focus

1. Vazamento de segredo no `target`: URL com credenciais/token/query/caminho, IPv6, porta, maiúsculas, IDN, string vazia ou lixo, chave de objeto S3, usuário/destinatário SMTP — só o rótulo seguro é gravado (Task 1 e 3).
2. Chamada que falha (erro do cURL, exceção, SDK lançando): registrada **uma vez**, `status null`, e o retorno/exceção originais chegam intactos ao chamador; o wrapper nunca lança (Task 2 e 3).
3. Loop com 5 000 chamadas externas: memória limitada pelo teto, `dropped_events` correto, request mantido por chamada lenta mesmo descartada (Task 2).
4. Atualização a partir de uma instalação da fase 3 (sem a tabela nova): falha segura (breaker, uma linha de log com `monitor:install`) e `auto_migrate` instalando só a migration nova; `Installer::TABLES` com 6 tabelas (Task 2).
5. `MonitorApi` com parâmetros hostis (`from`/`to` lixo ou invertidos, `limit` enorme, `orderBy` com SQL, `id` estranho, flag desligada): sempre uma resposta 4xx estruturada, nunca exceção, nunca SQL injetado (Task 5).

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `src/monitor/ExternalTarget.php` | rótulos seguros (`fromUrl`, `smtp`, `storage`) |
| `src/monitor/migrations/20261026000001_psf_monitor_create_external.php` | tabela nova |
| `src/monitor/Api/MonitorApi.php` | serviço JSON opcional (validação + chamada ao `Reader`) |
| Modificados: `Config.php`, `Sampler.php`, `Buffer.php`, `Monitor.php`, `Installer.php`, `Sinks/DatabaseSink.php`, `Pruner.php`, `Rollup.php`, `Reader.php`, `src/http/Request.php`, `src/http/Http.php`, `src/utils/Email.php`, `src/utils/CloudStorage.php` | integração |
| Testes novos: `tests/Unit/Monitor/{ExternalTarget,MonitorExternal}Test.php`, `tests/Integration/Monitor/{ExternalSchema,ExternalHooks,RollupExternal,ReaderExternal,MonitorApi}Test.php`; Parte B: `tests/Drivers/*` | |
| Docs: `docs/observabilidade.md`, `docs/melhorias.md`, `CHANGELOG.md`, `README.md`, `CLAUDE.md` | |

---

## Parte A — Chamadas externas e API

### Task 0: Branch

- [ ] **Step 1 (controlador):** só depois que a auditoria de docs e a revisão do README estiverem commitadas: `git checkout -b feat/monitor-fase4` a partir de `feat/monitor-fase3` e commitar este plano.

### Task 1: ExternalTarget

**Files:** Create `src/monitor/ExternalTarget.php`, `tests/Unit/Monitor/ExternalTargetTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\ExternalTarget` — `static fromUrl(string $url): string`; `static smtp(string $host): string`; `static storage(string $provider, string $operation): string`. Todas **nunca lançam**, devolvem minúsculas ≤ 191 caracteres (corte mb-safe), aplicam as regras de privacidade acima; entrada vazia/inválida → `(invalido)`.

- [ ] **Step 1: Write the failing test** (dataset entrada → saída exata):
  - `fromUrl`: `https://api.exemplo.com/v1/users?token=abc` → `api.exemplo.com`; `https://user:senha@Api.Exemplo.COM:8443/x#f` → `api.exemplo.com`; `http://[2001:db8::1]:80/a` → `2001:db8::1`; `http://127.0.0.1:1` → `127.0.0.1`; `//cdn.exemplo.com/a.js` → `cdn.exemplo.com`; `HTTPS://EXEMPLO.COM` → `exemplo.com`; `api.exemplo.com/v1` (sem esquema) → `(invalido)`; `''`, `'not a url'`, `'http://'`, `"http://a\r\nb.com"` → `(invalido)`; host de 300 caracteres → ≤ 191; `https://xn--exemplo-9ua.com` (punycode) mantido; `file:///etc/passwd` → `(invalido)`.
  - `smtp('SMTP.Exemplo.com')` → `smtp.exemplo.com`; `smtp('')` → `(invalido)`; `smtp("a b")` → `(invalido)`.
  - `storage('R2', 'putObject')` → `r2/putobject`; `storage('r2','listObjects')` → `r2/listobjects`; `storage('', 'x')` → `(invalido)/x`; operação com caracteres fora de `[A-Za-z0-9_]` → `(invalido)` na parte da operação.
  - nenhuma saída contém `senha`, `token`, `abc`, `user`.
- [ ] **Step 2:** rodar o teste → FAIL.
- [ ] **Step 3: Implement** (usar `parse_url` com validações defensivas; sem regex catastrófica).
- [ ] **Step 4:** rodar → PASS.
- [ ] **Step 5: Commit** — `feat(monitor): rótulos seguros para chamadas externas`.

### Task 2: Config, Sampler, Buffer, Monitor e esquema

**Files:**
- Create: `src/monitor/migrations/20261026000001_psf_monitor_create_external.php`, `tests/Unit/Monitor/MonitorExternalTest.php`, `tests/Integration/Monitor/ExternalSchemaTest.php`
- Modify: `src/monitor/Config.php` (defaults `slow_ms.external=1000`, `api.enabled=false`), `Sampler.php`, `Buffer.php`, `Monitor.php`, `Installer.php`, `Sinks/DatabaseSink.php`, `Pruner.php`, e os testes existentes que contam tabelas/migrations (5 → 6, arquivos de migration 5 → 6)

**Interfaces:**
- Produces:
  - `Sampler::weight(int $status, float $durationMs, bool $hasException = false, float $maxQueryMs = 0.0, ?float $rand = null, float $maxExternalMs = 0.0): ?float` (parâmetro novo **no fim**; mantém peso 1 também quando `maxExternalMs >= slow_ms.external`).
  - `Buffer::addExternal(string $kind, string $target, float $durationMs, ?int $status, int $ts): void` (guarda até `$maxEvents`; sempre atualiza o maior tempo; o que não coube soma em `droppedEvents`); `Buffer::externals(): array` (itens `['kind','target','duration_ms','status','ts']`); `Buffer::maxExternalMs(): float`.
  - `Monitor::external(string $kind, string $target, float $durationMs, ?int $status = null): void` (API pública da spec); `Monitor::externalStart(): ?int`; `Monitor::externalEnd(?int $start, string $kind, string $target, ?int $status = null): void`; `Monitor::trackExternal(string $kind, string $target, \Closure $call, ?\Closure $statusFrom = null): mixed` — executa `$call`, devolve **exatamente** o que ele devolve, relança **exatamente** a exceção que ele lançar, e registra o evento (também na falha, com `status null`); `$statusFrom(mixed $result): ?int` extrai o status do retorno (qualquer exceção dentro dele é engolida → `null`). `Monitor::events()` ganha a chave `external`.
  - `flush()`: depois de `finishResponse()`, monta `external` rows (`request_id, ts, kind, target, duration_ms, status, sample_weight`; mesmo peso do request) e passa `maxExternalMs` ao `Sampler`.
  - `Installer::TABLES` com 6 tabelas; `DatabaseSink` chave de lote `external` → `psf_monitor_external`; `Pruner::RAW_TABLES` inclui `psf_monitor_external`.

- [ ] **Step 1: Write the failing tests:**
  - `MonitorExternalTest` (sink espião, `Config::fake`, `boot('GET')`): `it('records an external call with the safe target')` (`external('http','api.exemplo.com',120.5,200)` → no flush linha em `external` com os campos e `sample_weight` do request); `it('keeps a request with a slow external call even if dropped')` (`max_events_per_request=1`, duas chamadas, a 2ª com 1500 ms, `sample.normal=0` → request gravado com peso 1 e `dropped_events===1`); `it('trackExternal returns the original result and records it')` (retorno `'ok'`, status via `statusFrom`); `it('trackExternal rethrows the original exception exactly once recorded with null status')` (mesma instância; 1 evento; `status null`); `it('trackExternal swallows a throwing statusFrom')`; `it('is a no-op when disabled and trackExternal still runs the call')` (call executado, `events()===[]`); `it('bounds memory for a 5000 call loop')` (teto 200, `dropped_events===4800`); `it('never throws from the hooks')` (kind/target inválidos, `-1` de duração); `Sampler` (peso 1 com `maxExternalMs=1000`; `slow_ms.external` fake maior não mantém).
  - `ExternalSchemaTest`: instala em SQLite e confere colunas/índices do contrato, `status` nulável; `it('upgrades a phase-3 install')` (instala, `DROP TABLE psf_monitor_external` e apaga a versão `20261026000001` de `psf_monitor_migrations` → `isInstalled()` false → `install()` → true, as outras 5 tabelas e suas linhas intactas); `it('fails safely on a phase-3 schema and auto_migrate installs only the new table')` (nos moldes dos testes de `AutoMigrateTest`: breaker abre com uma linha de log mencionando `monitor:install`; com `auto_migrate` em dev as 6 tabelas existem e o lote é regravado); `DatabaseSink`: lote com `external` na mesma transação (linha inválida desfaz as outras tabelas); `Pruner`: apaga `external` antigo e mantém o novo.
- [ ] **Step 2:** rodar `./vendor/bin/pest` nos arquivos novos → FAIL.
- [ ] **Step 3: Implement** os itens acima; atualizar as contagens dos testes existentes.
- [ ] **Step 4:** `./vendor/bin/pest` (suíte inteira) → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): registro de chamadas externas e tabela psf_monitor_external`.

### Task 3: Ganchos nos pontos de chamada

**Files:** Modify `src/http/Request.php` (`send`), `src/http/Http.php` (`request`), `src/utils/Email.php` (`send`), `src/utils/CloudStorage.php` (`putObject`, `getObject`, `listObjects`, `deleteObject`); Create `tests/Integration/Monitor/ExternalHooksTest.php`

**Interfaces:**
- Consumes: `Monitor::trackExternal`, `ExternalTarget`.
- Regras: cada gancho é **uma única chamada** a `Monitor::trackExternal(kind, target, fn () => <código original sem alteração>, statusFrom)`, preservando retorno, exceções, efeitos colaterais e ordem. `Request::send`: `kind http`, `target = ExternalTarget::fromUrl($this->settings['url'])`, status = `$response->code` ou `null` quando `send()` devolve `false`. `Http::request` (deprecated): idem com `$url`, status `$arrReturn['code']` (0 → `null`). `Email::send`: `kind mail`, `target = ExternalTarget::smtp($this->server['host'] ?? '')`, status `200` quando devolve `true`, `null` quando devolve string/`false`. `CloudStorage`: `kind storage`, `target = ExternalTarget::storage($configAws['provider'], '<operação>')`; status do `@metadata.statusCode` do resultado quando existir, `200` em sucesso sem código, `null` em falha (retorno `false`). Quando `Email`/`CloudStorage` não puderem ser executados no ambiente de teste (PHPMailer/AWS SDK não instalados), os testes cobrem o gancho por reflexão do contrato (`trackExternal` já testado) e, **se as classes existirem**, por teste real; senão `->skip(...)` com motivo explícito.

- [ ] **Step 1: Write the failing test:**
  - `it('records a failed http call without changing the result')`: `Request::get()->url('http://127.0.0.1:1/x?token=segredo')->send()` → continua devolvendo `false`; `Monitor::events()['external']` tem 1 item `kind http`, `target 127.0.0.1`, `status null`; o texto `segredo` não aparece em nada gravado.
  - `it('records a successful http call')`: servidor embutido do PHP (`php -S 127.0.0.1:<porta livre>` via `proc_open` num `beforeAll`, encerrado no `afterAll`; se não puder subir, `skip`): resposta 200 e outra 503 → eventos com status `200`/`503`; `duration_ms >= 0`; retorno do `send()` idêntico ao de antes (objeto com `code`, `body`).
  - `it('does not instrument anything when the monitor is disabled')`: `Request::send` igual e `events()===[]`.
  - `it('Http::request records the host only')` (caminho deprecated, mesma porta/erro).
  - `Email`/`CloudStorage`: testes condicionais descritos acima; mais um teste que lê o código-fonte dos dois arquivos e garante que cada método público listado chama `Monitor::trackExternal` (contrato estrutural, para não regredir).
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement** os ganchos (alterações mínimas).
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): ganchos de chamadas externas em Request, Http, Email e CloudStorage`.

### Task 4: Rollup e Reader das chamadas externas

**Files:** Modify `src/monitor/Rollup.php`, `src/monitor/Reader.php`; Create `tests/Integration/Monitor/RollupExternalTest.php`, `tests/Integration/Monitor/ReaderExternalTest.php`

**Interfaces:**
- Produces: agregador `external` em `Rollup::aggregators()` (semântica nas Global Constraints; `mergeByKey` já cobre colisões de chave); `Reader::external(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20, string $orderBy = 'sum_ms'): array` com o mesmo formato de `routes()` trocando `method`/`route` por `kind`/`target` (`['key','kind','target','hits','errors','error_rate','avg_ms','max_ms','p50_ms','p95_ms','p99_ms']`; `kind`/`target` separados no **primeiro `:`** de `key`); `Reader::request($id)` passa a devolver também `'external' => [...]` (linhas brutas ordenadas por `id`) — chave **adicional**, as existentes não mudam. Reaproveitar o código de `routes()` (extrair o miolo comum; não duplicar).

- [ ] **Step 1: Write the failing test:**
  - `RollupExternalTest`: uma hora com chamadas `http:api.exemplo.com` (pesos `1`, `4`; status `200`, `null`, `503`, `404`) e `mail:smtp.exemplo.com` → registros `metric='external'` com `hits`, `errors` (só `NULL`/`≥ 500`; 404 e 200 não contam), `sum_ms`, `max_ms`, histograma e `last_ts` esperados; `sample_text null`; reprocessar é idempotente; as três métricas antigas continuam; chave maior que 191 é truncada e colisões fundidas.
  - `ReaderExternalTest`: `external()` ordena por tempo total, respeita faixa/limite/`orderBy` em lista fixa, percentis do histograma fundido, `from >= to` → `[]`; `request($id)` traz `external`; id inexistente → `null`; teste ponta a ponta `Rollup::run` → `Reader::external`.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): rollup e leitura das chamadas externas`.

### Task 5: MonitorApi

**Files:** Create `src/monitor/Api/MonitorApi.php`, `tests/Integration/Monitor/MonitorApiTest.php`

**Interfaces:**
- Produces: `final class Psf\Monitor\Api\MonitorApi` — `__construct(?Reader $reader = null)`; `handle(string $endpoint, array $params): array` devolve o formato dos controllers do framework `array{0: string, 1: array, 2: int}` (mensagem, dados, status HTTP). `endpoint` ∈ `routes`, `queries`, `exceptions`, `external`, `throughput`, `request`; outro → `['Endpoint desconhecido', [], 404]`. Com `Config::get('api.enabled')` falso → `['API do monitor desabilitada', [], 404]` **antes de qualquer consulta ao banco**. Parâmetros: `from`/`to` (ISO-8601 com ou sem fuso, ou `Y-m-d H:i:s` UTC; padrão `to = agora`, `from = to − 24 h`; inválidos → `422`; `from >= to` → `422`), `limit` (inteiro 1..1000, padrão 20; inválido → `422`), `order_by` (só para `routes` e `external`, lista do `Reader`; outro valor → `422`), `id` (obrigatório em `request`: string `^[A-Za-z0-9-]{8,64}$`, senão `422`; desconhecido → `404`). Sucesso → `['', <retorno do Reader>, 200]` (em `request`, o array do detalhe). Falha de banco (`\PDOException`) → `['Erro ao consultar o monitor', [], 500]` **sem** vazar a mensagem. Nunca lança.

- [ ] **Step 1: Write the failing test** (SQLite + `Rollup` real nos dados):
  - `it('is disabled by default')` (404, e um PDO espião prova que nada foi consultado); `it('serves every endpoint with the controller response shape')`; `it('defaults the range to the last 24 hours')`; `it('rejects hostile parameters with 422')` (dataset: `from='lixo'`, `to='2026-13-45'`, `from>=to`, `limit='abc'`, `limit=0`, `limit=1001`, `order_by="sum_ms; DROP TABLE x"`, `id=''`, `id="a'b"`, `id` com 100 caracteres); `it('returns 404 for an unknown request id and an unknown endpoint')`; `it('does not leak database errors')` (PDO que lança com DSN na mensagem → 500 sem o texto); `it('converts time zones to UTC')`; `it('never throws')` (params de tipos errados: arrays, null, objetos).
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** `./vendor/bin/pest` → PASS; `composer analyse` limpo.
- [ ] **Step 5: Commit** — `feat(monitor): MonitorApi, serviço JSON opcional desligado por padrão`.

### Task 6: Documentação da Parte A

**Files:** Modify `docs/observabilidade.md`, `docs/melhorias.md`, `CHANGELOG.md`, `README.md` (apenas as menções ao monitor), `CLAUDE.md`

- [ ] **Step 1:** Atualizar a documentação: seção de chamadas externas (o que é medido, `kind`/`target`/`status`, regra de privacidade do `target`, `slow_ms.external`, teto), a nova tabela como contrato (6 tabelas), a métrica `external` no rollup e o `Reader::external`/detalhe do request, a **seção `MonitorApi`** com a tabela de endpoints/parâmetros/respostas e um exemplo completo de controller do projeto (rotas montadas pelo projeto com `middlewares: ['authentication']`, chamando `MonitorApi::handle`), a nota de atualização (rodar `monitor:install`), remover "fase 4 planejada" onde virou realidade (a Parte B continua listada como pendente se ainda não executada), `CHANGELOG.md`, `README.md` (linha das chamadas externas e da API), `CLAUDE.md`.
- [ ] **Step 2:** `./vendor/bin/pest` e `composer analyse` verdes.
- [ ] **Step 3: Commit** — `docs(monitor): chamadas externas e MonitorApi`.

---

## Parte B — Validação em bancos reais (precisa do Docker Desktop em execução)

**Pré-condição:** o daemon do Docker responde (`docker info`). Se não responder, **parar e pedir ao usuário** para abrir o Docker Desktop (não iniciar o aplicativo por conta própria).

### Task 7: Suíte de drivers

**Files:** Create `docker-compose.drivers.yml` (MySQL 8, PostgreSQL 16, SQL Server 2022 ou Azure SQL Edge no Apple Silicon; portas locais altas, volumes descartáveis, senhas só de teste), `tests/Drivers/DriversTest.php` (+ helpers), script `composer test:drivers`; Modify `phpunit.xml` (suíte `Drivers`, **fora** da execução padrão) e `tests/Pest.php` se preciso

**Interfaces:**
- A suíte só roda com `PSF_DRIVERS=1` (e as variáveis `PSF_MYSQL_*`, `PSF_PGSQL_*`, `PSF_SQLSRV_*` com host/porta/usuário/senha/banco); sem isso, `skip` com motivo. Para **cada driver disponível**, executa o mesmo roteiro e as mesmas asserções da SQLite: `Installer::install()` (migrations reais), `DatabaseSink::write` de requests/queries/exceções/externas com `ts`, `Rollup::run` de horas passadas (incluindo reagregação e `mergeByKey`), `Reader` (todos os métodos), `Pruner` (lotes, watermark, teto), comandos `monitor:*`, `Watermark` (upsert), `SqlDate` (SQL Server: sessão com `SET LANGUAGE Português`/`us_english`; datas ISO), colunas `text` (SQL de 8000 caracteres, trace de 16 KB, mensagem multibyte), `decimal` com pesos fracionários, chaves de 191 caracteres, colisão de `metric_key` por collation, `TOP`/`LIMIT`, `IN` com 500 ids, insert em chunks acima de 2100 parâmetros (SQL Server). **Defeitos encontrados nos drivers são corrigidos no código com TDD** (teste que reproduz primeiro, de preferência também na SQLite quando o defeito for de lógica) e listados no relatório.

- [ ] **Step 1:** subir os bancos (`docker compose -f docker-compose.drivers.yml up -d`, aguardar `healthy`), escrever o roteiro como teste e rodar por driver (`PSF_DRIVERS=1 ./vendor/bin/pest --testsuite=Drivers`); registrar cada falha como achado.
- [ ] **Step 2:** corrigir os achados (um commit por correção, com teste), repetir até verde nos três drivers (ou documentar o que o driver realmente não suporta).
- [ ] **Step 3:** documentar em `docs/observabilidade.md` o que foi validado em cada driver (versões das imagens), os achados corrigidos e como rodar a suíte (`docker compose ... up`, variáveis, `composer test:drivers`); atualizar "Limites" (remover "só SQLite exercitada" onde deixar de ser verdade); `CHANGELOG.md`.
- [ ] **Step 4:** `docker compose ... down -v` ao final; `./vendor/bin/pest` (suíte padrão, sem drivers) e `composer analyse` verdes.
- [ ] **Step 5: Commit(s)** — `test(monitor): suíte contra MySQL, PostgreSQL e SQL Server reais` e `fix(monitor): <achado>` por correção.

---

## Self-Review

- **Cobertura do spec (Fase 4):** `Monitor::external(kind, target, duration_ms, status)` (T2); tabela `psf_monitor_external` com colunas/índices da spec (T2); ganchos em `Email`, `CloudStorage`, `Request`/`Http` (T3); `Reader::external` e rollup de `kind:target` (T4); controller JSON opcional desligado por padrão com a rota montada pelo projeto (T5, como serviço — decisão abaixo); docs (T6). Parte B atende à pendência "validar em drivers reais".
- **Decisões minhas a confirmar:** `slow_ms.external=1000` mantém o request (a spec não pede); tetos separados por tipo de evento; `target` só com host/`provedor/operação` e `status` de e-mail `200`/`null`; `MonitorApi` como **classe de serviço** (o projeto cria as rotas e a autenticação) em vez de um controller com rotas fixas — as rotas do framework vêm de atributos estáticos e não permitem prefixo/autenticação configuráveis; chave adicional `external` em `Reader::request()`; a Parte B só roda com Docker ligado pelo usuário.
- **Consistência de tipos:** `Sampler::weight(..., ?float $rand, float $maxExternalMs)`; `Buffer::addExternal/externals/maxExternalMs`; `Monitor::external/externalStart/externalEnd/trackExternal`; `ExternalTarget::fromUrl/smtp/storage`; `MonitorApi::handle(string, array): array{0:string,1:array,2:int}`.
- **Proporção:** o plano define interfaces, regras e testes; corpos ficam para quem implementa.
