# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). O histórico anterior (v0.0.10 → v0.4.0) está em [docs/melhorias.md](docs/melhorias.md).

## [Não lançado]

## [0.1.1] - 2026-10-26

### Novidades

- **Notificações do monitor** (`settings.monitor.notifiers`): avisos de 500, exceção, erro fatal, request/query/chamada externa lenta e falha em chamada externa, por Slack, Teams, Discord, e-mail, webhook (com assinatura HMAC opcional), log, classe própria (`NotifierInterface`) ou callback. Regras `when` com filtros por rota, método e ambiente, e throttle por *fingerprint* (`ThrottleInterface` substituível). Roda depois de liberar a resposta, independe de amostragem, do breaker e do banco, e nunca lança. Ver [docs/observabilidade.md](docs/observabilidade.md#notificações).
- **`Request::timeout($segundos, $conexao = null)`** define timeouts por requisição (padrões 30 s e 5 s, em `Request::DEFAULT_TIMEOUT` e `DEFAULT_CONNECT_TIMEOUT`). `Http::request()` (depreciado) ganhou os parâmetros opcionais `$timeout` e `$connectTimeout`; antes não tinha limite algum.

### ⚠️ Mudanças de comportamento (leia antes de atualizar)

- **`JWT::verify()` / `decode($token, true)` recusam token sem `exp`.** Passe a emitir `exp` ou defina `'jwt' => ['require_exp' => false]` para aceitar tokens legados.
- **Saídas do `Reader` e da `MonitorApi` em camelCase** (sem alias): `errorRate`, `avgMs`, `maxMs`, `p50Ms`, `p95Ms`, `p99Ms`, `sqlHash`, `exceptionClass`, `lastTs`; as linhas de `Reader::request()` também (`requestId`, `durationMs`, `sqlText`...). Quem lê as chaves antigas (`error_rate`, `p95_ms`...) precisa migrar. O parâmetro `orderBy` aceita `sumMs|hits|errors|maxMs|avgMs`; `order_by` e `sum_ms`, `avg_ms`... seguem valendo como alias. A mensagem de erro passa a ser `Parâmetro orderBy inválido`.
- **A `description` das rotas é escapada** na página de documentação da API (HTML nela aparece como texto).

### Padrão camelCase na configuração

- As chaves de `settings.monitor` e `settings.cors` são camelCase (`rawDays`, `aggregateDays`, `slowMs`, `maxEventsPerRequest`, `cooldownSeconds`, `connectTimeout`, `exposeRequestId`, `storeUser`, `userIdProperty`, `autoMigrate`, `storeExceptionMessage`, `maxAge`, `exposeHeaders`). **As grafias snake_case continuam aceitas como alias** (`Psf\Helper\ConfigKeys`); se as duas aparecerem, a camelCase vence. Colunas e tabelas do banco seguem em snake_case. Mensagens de erro e docs usam a nova grafia (ex.: `rawDays inválido: ...`).

## [0.1.0] - 2026-10-25

- Licença declarada como proprietária (`proprietary` no `composer.json` e arquivo `LICENSE`); antes o `composer.json` dizia MIT.

### ⚠️ Mudanças de comportamento (leia antes de atualizar)

- **Erros de banco** (`explodeException`): a resposta 500 não traz mais mensagem/arquivo/linha. Defina `settings.debug = true` para voltar a expor. O erro é sempre registrado com `error_log`.
- **Condição WHERE inválida** lança `InvalidArgumentException`. Antes emitia um warning e a query rodava **sem o filtro**.
- **`orWhere`**: agora o resultado é `(wheres) OR orWheres` e o filtro de soft delete é aplicado por fora, com AND — um `orWhere` não traz mais registros deletados de volta. Um `orWhere` sozinho agora filtra (antes a cláusula WHERE inteira era descartada).
- **Expressões SQL cruas** em `fields`/`groupBy`/`orderBy`: só são aceitas funções da allow-list seguidas de `(` (ou `CASE ... END`), sem `;`, comentários, palavras-chave de outros statements ou parênteses desbalanceados. Strings iniciadas por `(` são rejeitadas — use `['subquery', 'SELECT ...']`. Um nome de coluna que apenas começa com o nome de uma função (ex.: `count_total`) agora é tratado como coluna.
- **`orderBy`**: direção diferente de ASC/DESC lança exceção (aceita minúsculas); sem direção, só entram colunas ou expressões seguras.
- **Fragmentos crus de WHERE/JOIN** não podem conter `;` nem comentários (fora de literais entre aspas).
- **`JWT::decode($token, $valid = true)`**: `$valid` passa a ter padrão `true`; `exp`/`nbf` não numéricos invalidam o token; payload que não é objeto JSON é rejeitado; `jwt.secret` ausente ou com menos de 16 caracteres lança `RuntimeException`. Novo `JWT::verify()`.
- **`RequestParser`**: o body tem precedência sobre a query string; `application/json; charset=...` é reconhecido; Bearer só é aceito com o esquema `Bearer` (case-insensitive) e o header é lido sem diferenciar maiúsculas.
- **Roteamento**: rotas dinâmicas com path iniciado por `/` (formato dos docs) agora casam; expressões inline sem delimitador são ancoradas; exceções com código 4xx dos controllers e `HttpResponseException` não são mais convertidas em 500.
- **`Update::exe`** passa a aplicar o `$parseString` (era ignorado). **`Delete::getResult()`** retorna o resultado (`true`), não o statement.
- **Log de requisições** recebe os headers com `Authorization`, `Proxy-Authorization` e `Cookie` mascarados.
- **`Connect::commitTransaction/rollBackTransaction`** não descartam mais a conexão em cache.
- **PostgreSQL, `quoteTable()`**: usado por `Read::exe()` e pelo QueryBuilder, não emite mais `"banco"."tabela"` (o PostgreSQL lia como `schema.tabela` e falhava com `relation ... does not exist`); o nome do banco é ignorado e a tabela é resolvida pelo `search_path` da conexão (MySQL e SQL Server continuam prefixando o banco). Projetos cujas tabelas ficam num schema que NÃO está no `search_path` precisam incluí-lo (ex.: `ALTER ROLE usuario SET search_path TO meu_schema, public;` ou `SET search_path` na sessão); o framework não tem chave de config para schema.
- **PHP mínimo é 8.1** (o código já usava enums). `robmorgan/phinx` fica em `^0.16` (estável) e `minimum-stability` volta a `stable`.

- **Referências a entidades** (ver [docs/query-builder.md](docs/query-builder.md#referências-a-entidades-e-colunas)): todos os pontos da query (where em array/associativo/string, ON de join, `orderBy`, `groupBy`, `fields`, expressões) passam pelo mesmo `ReferenceResolver`. Na prática: `tabela.propriedade`, o nome solto da propriedade e `alias.propriedade` agora são traduzidos para a coluna (antes só `Classe::class.'.prop'` era); a referência pela classe de uma entidade com alias passa a usar o alias; e nomes de classe colados em `(`, `=`, `,` ou dentro de `SUM(...)` agora resolvem (antes geravam SQL quebrado).
- **Palavras soltas em strings cruas**: só as propriedades da entidade raiz com coluna de nome diferente são traduzidas (e qualificadas com a tabela/alias). Antes, a tradução de palavras soltas dependia do último nome de classe da string (estado que vazava entre tokens).
- **`andWhere([coluna => null])`** vira `IS NULL` (antes comparava com `= NULL`, que nunca casa). Um valor array vira `IN (...)`.
- **Aliases de join** são validados (`^[A-Za-z_]\w*$`); antes entravam crus no SQL.
- `ModelQuery::query()` também traduz as referências do trecho livre.

### Adicionado
- **`vendor/bin/psf`**: novo executável do Composer (`bin/psf`) que delega ao wrapper `bin/phinx` (mesma implementação, mesmos argumentos e código de saída). O `robmorgan/phinx` também declara um binário `phinx` e o Composer liga `vendor/bin/phinx` ao pacote instalado primeiro: num projeto que já tinha o Phinx, ele continuava sendo o Phinx puro, sem os comandos `make:*`, `analyze:*` e `monitor:*` e sem a inicialização do PSF. O nome `psf` não conflita; `bin/phinx` continua declarado por compatibilidade. Pelo proxy do Composer o wrapper usa `$_composer_autoload_path`, o que também vale para pacotes ligados por symlink (repositório `path`). Os docs passam a usar `vendor/bin/psf`.
- `Modelo::col('prop', ?alias)` (referência validada), `ModelQuery::alias()`, `ModelQuery::strict()`, join com alias em três argumentos (`innerJoin(Produto::class, 'p', 'p.userId = o.id')`) e where associativo com várias chaves.
- Modo estrito opcional (`settings.strictQueries`) para referências que não resolvem.
- `SQLiteDialect` e `DBDriver::SQLite` em `DialectFactory`; `DialectFactory` aceita o valor inteiro do enum.
- Middleware CORS (`settings.cors`) com resposta de preflight — `Psf\Http\Cors`.
- Operadores `>=`, `<=`, `!=`, `NOT LIKE`, `BETWEEN`/`NOT BETWEEN` nas condições em array; `IS NULL` com três elementos.
- `RouteCacheManager::clear()`; chave do cache de rotas com hash do app.
- `Connect::listTables($db, $refresh)` e `Connect::clearColumnsCache()`.
- `tests/Integration` (SQLite `:memory:`), CI no GitHub Actions (PHP 8.2–8.4) e PHPStan nível 1.
- Aliases de config `host`/`user`/`pass` também no `Connect` e nos dialetos.
- **Monitor de observabilidade** (`Psf\Monitor`, opt-in via `settings.monitor`; guia em [docs/observabilidade.md](docs/observabilidade.md)), entregue em fases:
  - **Requests:** `X-Request-Id` (aceita o de entrada ou gera um), padrão da rota, status, duração, memória e `user_id`; amostragem por peso (`sample_weight`), conexão PDO dedicada, gravação em lote no shutdown após `fastcgi_finish_request()`, circuit breaker (APCu ou arquivo) e `auto_migrate` opt-in (recusado em `prd`).
  - **Queries e exceções:** SQL dos shims normalizado (sem literais) com `sql_hash`; exceções com trace sem argumentos, `trace_hash`, cadeia `getPrevious()`, limpeza de valores nas mensagens de `PDOException` e de erros fatais (`FatalError`); flag `store_exception_message`.
  - **Agregados e leitura:** rollups por hora em `psf_monitor_rollups` (ponderados, idempotentes, com reagregação das 2 últimas horas), histograma de latência com percentis estimados (`Histogram`) e API de leitura `Reader` (`routes`, `slowQueries`, `exceptions`, `throughput`, `request`).
  - **Tabelas:** `psf_monitor_requests`, `psf_monitor_queries`, `psf_monitor_exceptions`, `psf_monitor_rollups`, `psf_monitor_state` e `psf_monitor_external` (migrations próprias em `src/monitor/migrations/`, controle em `psf_monitor_migrations`, fora do `migrate` do projeto).
  - **Comandos** no `bin/psf`: `monitor:install`, `monitor:status`, `monitor:rollup`, `monitor:prune` e `monitor:maintain` (rollup + limpeza, para agendar de hora em hora; limpeza com teto de 4 × `raw_days`).
  - **Chamadas externas (fase 4):** `Request::send`, `Http::request`, `Email::send` e as operações do `CloudStorage` registram `kind` (`http`/`mail`/`storage`), `target` seguro (só host ou `provedor/operação`), duração e `status` em `psf_monitor_external` (6ª tabela); métrica `external` nos rollups, `Reader::external()` e a chave `external` em `Reader::request()`. `Monitor::trackExternal()`, `external()`, `externalStart()` e `externalEnd()` para chamadas próprias. Novas chaves: `slow_ms.external` (padrão 1000) e `api.enabled` (padrão `false`). `max_events_per_request` passa a valer por tipo de evento.
  - **`MonitorApi` (opcional):** `Psf\Monitor\Api\MonitorApi::handle($endpoint, $params)` valida parâmetros e devolve `[mensagem, dados, status]` sobre o `Reader` (`routes`, `queries`, `exceptions`, `external`, `throughput`, `request`). Sem rotas próprias e desligada por padrão; o projeto monta o controller com `middlewares: ['authentication']`.
- **Suíte de drivers do monitor (dev):** `docker-compose.drivers.yml` (MySQL 8.4 e PostgreSQL 16 descartáveis; serviço SQL Server documentado e fora do padrão), testsuite `Drivers` em `tests/Drivers` (só roda com `PSF_DRIVERS=1`; um banco novo por teste; variáveis `PSF_MYSQL_*`/`PSF_PGSQL_*`) e o script `composer test:drivers`. Validado em MySQL 8.4.13 e PostgreSQL 16.15; **SQL Server pendente** (ver [Validação em bancos reais](docs/observabilidade.md#validação-em-bancos-reais)).
- `Cors`: chave `expose_headers` (emite `Access-Control-Expose-Headers`) e `Cors::withExposedHeader()`.
- `Psf\Database\PhinxEnvironments::fromDbConfigs()`: tradução de `config['db']` em ambientes do Phinx, extraída do `phinx.php` (reutilizada pelo instalador do monitor).

### Alterado
- Chamadas externas: `Request`, `Http`, `Email` e `CloudStorage` passam a reportar ao monitor quando ele está ligado (no-op com ele desligado; retorno e exceções inalterados). `Sampler::weight` ganhou um último parâmetro opcional (`$maxExternalMs`).
- Ganchos do monitor (no-op com o monitor desligado; nenhuma assinatura pública mudou): `Router` (boot no construtor, rota casada ou `(unmatched)`, usuário autenticado, registro das exceções 5xx e dos `\Error`/`TypeError` do controller, que continuam sendo relançados sem alteração, e `X-Request-Id` no CORS); shims `Read`, `Create` (`exe`/`batch`), `Update` e `Delete` (duração de cada statement e `PDOException`).
- `phinx.php` passa a usar `PhinxEnvironments` (mesmo resultado).
- `composer.json`: autoload PSR-4 `Psf\Monitor\` → `src/monitor/`.

### Atualização (monitor)
- As migrations do monitor declaram `NOT NULL` explicitamente (o Phinx 0.16 cria colunas anuláveis por padrão). No MySQL a instalação passa a funcionar; instalações existentes em SQLite/PostgreSQL mantêm as colunas anuláveis (inofensivo; para alinhar, recrie as tabelas do monitor).
- Quem instalou o monitor antes da fase 4 deve rodar `vendor/bin/psf monitor:install` (idempotente) para criar `psf_monitor_external`. Até lá, os lotes de requests com chamadas externas falham de forma segura (breaker e uma linha de log) e `monitor:rollup`/`monitor:prune`/`monitor:maintain` falham com "no such table"; `auto_migrate` fora de `prd` cria a tabela sozinho.

### Corrigido
- **Monitor no MySQL:** `monitor:install` abortava (`All parts of a PRIMARY KEY must be NOT NULL`): o Phinx 0.16 cria colunas `NULL` por padrão. As migrations declaram `'null' => false` em tudo o que o contrato não marca como anulável (em todos os drivers as colunas ficavam anuláveis).
- **PostgreSQL:** `PostgreSQLDialect::quoteTable()` prefixava o banco (`"banco"."tabela"`), lido como `schema.tabela`; `Read::exe()` e o `QueryBuilder` falhavam com `relation ... does not exist`. O banco passa a ser ignorado (a tabela segue o `search_path`).
- **Monitor (rollup):** ao fundir chaves que diferem só por caixa/espaços à direita, a grafia gravada dependia da ordem do `GROUP BY` do driver; agora é sempre a menor em ordem binária.
- **Monitor no PostgreSQL:** `Watermark::write()` dentro de uma transação abortava a transação de quem chamou ao perder a corrida do INSERT (`25P02`); o INSERT agora roda num `SAVEPOINT` (exceto SQL Server).
- Rotas dinâmicas nunca casavam com path iniciado por `/`; regex inline com `/` ou `{n}` quebrava o split do path; versão com mais de um dígito.
- Controller instanciado duas vezes por requisição; `apache_request_headers()` fatal em php-fpm; `$_GET['_url']` ausente.
- **Log de requisições** (`settings.logrequest`, middleware `loggin`): o mascaramento de `Authorization`, `Proxy-Authorization` e `Cookie` estava documentado, mas o callback ainda recebia os headers crus de `apache_request_headers()` — que, além disso, não existe em todo SAPI (ex.: CLI) e derrubava a requisição. Agora recebe os headers mascarados, lidos por `getallheaders()`.
- `Read::getRowCount()` usava `rowCount()` em SELECT (0 no SQLite, -1 no SQL Server), fazendo `->one()`/`->all()` não retornarem linhas.
- `Model::getIdentityColumn()` (variáveis inexistentes, fatal no SQL Server); `ModelQuery::execute()` não idempotente; `ModelQuery::query()` sem `SELECT` e com propriedades inexistentes; `COUNT` sem chave primária.
- `Update`/`Delete`/`Read` ignoravam o dialeto (tabela sem quoting, crases de MySQL fixas); placeholders do `Update` dependiam do nome da coluna.
- `getPrimarysQuery(true)` concatenava o valor cru (agora `PDO::quote`).
- `quoteTable`/`addColumnSql`/`modifyColumnSql` sem escape do identificador; `LEFT JOIN` sem quoting.
- `phinx.php`: porta e charset padrão por driver; ambientes SQLite eram descartados.
- Comandos do Phinx (`make:migration-from-model`, `make:model-from-table`, `analyze:table-vs-model`) nunca eram registrados (o Phinx não aceita comandos pela config) e, uma vez registrados, falhavam por falta de `bootstrap()`/`parent::configure()`, por obter o adapter do lugar errado e por gerar migration com nome de classe inválido. `bin/phinx` agora os registra; `bin/phinx` também avisa quando `robmorgan/phinx` não está instalado.
- **Comandos do Phinx em projetos consumidores** (`vendor/bin/psf`): o `phinx.php` do framework usava `ROOT`, que no CLI aponta para `vendor/psf/framework` (o `DOCUMENT_ROOT` é vazio), então `create`/`migrate`/`status`, os seeds e o `make:migration-from-model` usavam `vendor/psf/framework/db/migrations` (e `make:model-from-table` gravava o model dentro do `vendor`). O `bin/phinx` agora define `PSF_PROJECT_ROOT` (a raiz do projeto) e os caminhos passam por `Psf\Database\PhinxCli::projectRoot()` (`PSF_PROJECT_ROOT`, senão `ROOT`); o `ROOT` continua igual para o código web. Além disso, o Phinx 0.16 ignora a constante `PHINX_CONFIG_FILE` e só procura `phinx.*` no diretório atual: num projeto sem `phinx.php` próprio, todo comando do Phinx falhava com `The file "phinx.yml" does not exist`. Sem config do Phinx no diretório atual, o wrapper passa a usar a do framework como padrão de `--configuration` (`-c` e um `phinx.php` próprio continuam valendo). Crie `db/migrations` (e `db/seeds`) na raiz do projeto: o Phinx não cria a pasta.
- `ModelAwareMigration` chamava `Util::camelcaseToUnderscore()`, que não existe no Phinx (agora `toSnakeCase`).
- `CloudStorage`: `catch` sem `\`, `mime_content_type` com conteúdo, retorno indefinido fora de R2, `echo` de erro, handle de arquivo não fechado.
- `RouteCacheManager`: `environment` ausente gerava `TypeError`; parse de classe por tokens.
- `Http::response`: status inválido, falha de `json_encode`, CR/LF em headers; `requireBasicAuth` com `hash_equals`.
- `ControllerBase::runServiceTransaction` deixava a transação aberta se a ação lançasse.
- Variáveis indefinidas em `Connect`, `TimeZone`, `UUID`; namespace antigo em `Miscellaneous`.

### Desempenho
- `serializeData`/`ModelHydrator` usam um plano de propriedades em cache (sem Reflection por linha; modo array deixou de ser O(colunas × propriedades)).
- `MetadataCache` cacheia resultados `null`/`false` e a chave do APCu inclui o mtime do arquivo da classe (deploy invalida).
- `Connect` lê a lista de tabelas uma vez por conexão (antes: um `SHOW TABLES` por operação) e memoriza as colunas por requisição.

### Removido
- `db/commands/` (cópia obsoleta de `src/database/Command/`) e `.claude/settings.local.json` do versionamento; `version` fixo e `Psf\Api\` (diretório inexistente) do `composer.json`.
