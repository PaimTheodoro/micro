# Observabilidade (Monitor) — Design

Data: 2026-10-24 · Status: aguardando aprovação

## Intenção

Os projetos que consomem o `psf/framework` estão crescendo e precisam ser **monitorados e otimizados** (requests lentos, queries lentas, erros, dependências externas lentas). Ferramentas de mercado como Laravel Pulse (self-hosted, agregado) e Laravel Nightwatch (SaaS, por evento) resolvem isso em três camadas: **coleta**, **armazenamento** e **visualização**.

Decisão do dono do projeto: o framework entrega **coleta + armazenamento + uma forma padronizada e documentada de extração**, dentro do banco do próprio projeto. **Cada projeto decide como consumir** (tela, endpoint, Grafana, alerta). O framework não terá UI.

Por que: sem infraestrutura nova, sem dependência de SaaS, com os dados no projeto. Só a coleta precisa estar no framework, porque só ele conhece os pontos de passagem (Router, shims de banco, exceções).

## Objetivos

1. **Plug and play**: `settings.monitor.enabled = true` + rodar a instalação das tabelas. Nada mais.
2. **Custo quase zero** quando desligado (um `if` em cada gancho) e **baixo** quando ligado.
3. **Banco configurável**: um banco dedicado (chave de `config['db']`) ou o `default`.
4. **Retenção automática**: eventos brutos por poucos dias, agregados por mais tempo, limpeza por comando (cron).
5. **Extração padronizada**: esquema documentado como contrato estável + API PHP de leitura (`Reader`).
6. **Monitor nunca derruba a aplicação**: qualquer falha do monitor é engolida.
7. Funcionar em **MySQL, PostgreSQL, SQL Server e SQLite**.

## Não-objetivos

- Dashboard/UI no framework (um pacote separado poderia nascer depois, em cima do `Reader`).
- Coletor central multi-projeto, `HttpSink`, adaptador OpenTelemetry (fica para depois, a interface de sink deixa a porta aberta).
- Alertas/notificações.
- Armazenar body, headers, token, valores de bindings ou **argumentos** dos frames do stack trace (o trace em si é guardado completo, só sem argumentos).
- Criar tabelas em runtime **por padrão** ou em `prd`: o `auto_migrate` é opt-in e recusado em `prd` (ver "Instalação das tabelas").
- Alterar qualquer API pública existente.

## Anti-objetivos (seria falha mesmo "funcionando")

- Adicionar latência perceptível ao request, ou I/O de banco no meio dele.
- Um banco de monitoramento fora do ar deixar a aplicação lenta ou quebrada.
- Vazar dado sensível (valores de queries, credenciais, body) para as tabelas.
- Crescimento ilimitado das tabelas.
- Quebrar projetos que não ligam o monitor (inclusive o `bin/phinx migrate` deles).

## Decisões já tomadas (pelo dono do projeto)

- Armazenar **no banco do próprio projeto**, banco configurável, com fallback para `default`.
- Usar o **Phinx** existente para criar as tabelas; **não** criar tabelas em runtime.
- Retenção: **7 dias** de eventos brutos, **90 dias** de agregados.
- **Gravar `user_id`** é permitido.
- Extração: esquema documentado + `Reader` em PHP; o projeto decide a tela.
- Entrega em fases (abaixo); a fase 1 é a primeira a ser implementada.

## Configuração

Em `config['settings']['monitor']` (ausente ou `enabled => false` = tudo desligado):

```php
'monitor' => [
    'enabled'   => true,
    'database'  => 'monitor',            // chave de config['db']; omitida = 'default'
    'sample'    => ['normal' => 0.1],    // fração de requests "normais" gravados; lentos/erros = 1.0 sempre
    'slow_ms'   => ['request' => 1000, 'query' => 200],
    'max_events_per_request' => 200,     // teto de queries/chamadas externas por request
    'retention' => ['raw_days' => 7, 'aggregate_days' => 90],
    'auto_migrate' => false,             // opt-in; cria as tabelas sozinho se faltarem. Ignorado (com aviso) em prd
    'expose_request_id' => true,         // devolve o request_id no header X-Request-Id da resposta
    'store_user'  => true,
    'user_id_property' => 'id',          // propriedade de Router::$auth usada como user_id
    'breaker'   => ['failures' => 3, 'cooldown_seconds' => 300],
],
```

O ambiente (`env`) gravado nas linhas vem de `Psf->config('environment')` (padrão `dev`, igual ao `RouteCacheManager`). `release` vem de `settings.monitor.release` (opcional).

## Arquitetura

Namespace `Psf\Monitor`, diretório `src/monitor/` (adicionar o mapeamento PSR-4 no `composer.json`).

```
Monitor            fachada estática: boot(), enabled(), queryStart()/queryEnd(), exception(), external(), flush(), requestId()
Buffer             eventos em memória do request, com teto (max_events_per_request)
Sampler            decide no flush o que persistir
SqlNormalizer      normaliza SQL (remove literais) e calcula o hash
Histogram          buckets fixos de latência + estimativa de percentis
Sinks/SinkInterface   write(array $batch): void
Sinks/DatabaseSink    INSERT multi-linha numa conexão PDO dedicada
CircuitBreaker     abre após N falhas, fecha após cooldown
Rollup             agrega eventos brutos em horas (idempotente)
Pruner             apaga em lotes, respeitando o watermark do rollup
Reader             API de leitura (fase 3)
Command/*          monitor:install, monitor:status, monitor:rollup, monitor:prune, monitor:maintain
migrations/        migrations Phinx das tabelas do monitor
```

### Ciclo de vida de um request

1. **`Monitor::boot()`** é chamado no construtor de `Router`. Se `enabled` for falso, retorna na hora e todos os outros ganchos viram no-op (uma checagem de booleano estático). Se ligado: define o `request_id` (o `X-Request-Id` de entrada, se válido; senão 16 bytes aleatórios em hex), registra **uma** `register_shutdown_function`, usa `$_SERVER['REQUEST_TIME_FLOAT']` como início. Se `expose_request_id` for verdadeiro e os headers ainda não tiverem sido enviados, emite `X-Request-Id: <request_id>` — logo no `boot()`, para ele sair em **qualquer** resposta, inclusive as de erro e as que terminam em `exit` via `Http::response()`. Para o header ser legível por JavaScript em navegadores, o `Cors` passa a incluir `X-Request-Id` em `Access-Control-Expose-Headers` quando o monitor e o CORS estiverem ligados.
2. **Durante o request** os ganchos só anexam ao `Buffer` (memória), sem I/O:
   - `Router`: guarda o **padrão da rota casada** (ex.: `users/{id:int}`, nunca a URL real, para manter a cardinalidade baixa), método e versão; o usuário de `Router::$auth` após a autenticação. Requests que não casam nenhuma rota (404) são registrados com `route = '(unmatched)'`; assim a cardinalidade continua limitada e varreduras de URLs inexistentes aparecem sem poluir a tabela. **Preflight CORS (`OPTIONS` respondido por `applyCors`) não é registrado.** O `boot()` fica no construtor, mas o registro do request só vale se o método não for esse preflight.
   - O `user_id` aceita `Router::$auth` como **objeto** (propriedade `user_id_property`) ou **array** (chave de mesmo nome); qualquer outra forma, ou valor não escalar, resulta em `null`.
   - `Read`, `Create`, `Update`, `Delete` (nos pontos de `execute`): `Monitor::queryStart()` devolve `null` se desligado, ou o timestamp; `Monitor::queryEnd()` registra SQL normalizado, duração e a chave de conexão. **Cada query é registrada mesmo se lançar exceção** (try/finally ou equivalente).
   - `Router::dispatchController`: no `catch` que converte em erro 500, registra a exceção original. Exceções 4xx **não** são registradas como exceção (o status do request já as mostra).
   - `Monitor::external(kind, target, duration_ms, status)`: API pública para `Email`, `CloudStorage` e `Http`/`Request` (fase 4).
3. **No shutdown**: chama `fastcgi_finish_request()` se existir (a resposta já foi entregue ao cliente), lê `http_response_code()`, captura erro fatal via `error_get_last()` (registrado como exceção, status 500), aplica a amostragem e grava via sink. Todo o corpo do shutdown fica em `try/catch (\Throwable)`.

### Amostragem (decidida no flush)

Como o request pode virar "lento" ou "erro" só no final, os eventos são acumulados na memória (com teto) e a decisão vem no shutdown:

- **Mantido com probabilidade 1** (`weight = 1`): status ≥ 500, qualquer exceção registrada, `duration ≥ slow_ms.request`, ou alguma query com `duration ≥ slow_ms.query`.
- **Demais**: mantidos com probabilidade `sample.normal`; cada linha gravada leva `sample_weight = 1 / sample.normal`.

Como a probabilidade de inclusão é função da categoria do próprio request, `SUM(sample_weight)` é uma **estimativa não enviesada** da contagem real. Os agregados (rollups) usam `sample_weight` e por isso refletem 100% do tráfego, mesmo gravando uma fração.

### Gravação (DatabaseSink)

- **Conexão PDO dedicada**, criada via `DialectFactory` a partir de `config['db'][database]`, **sem** passar por `Connect::getConnection()`: não herda transações abertas da aplicação, não dispara listagem de tabelas e não é instrumentada (sem recursão). O sink recebe um resolvedor de PDO substituível (necessário para testes com SQLite `:memory:`).
- `INSERT` multi-linha por tabela por request (requests, queries, exceptions, external), numa única transação. O sink **divide em lotes** para respeitar os limites dos drivers (SQL Server: 2100 parâmetros e 1000 linhas por statement; o cálculo usa `linhas × colunas`, com folga).
- Timeout de conexão curto: `monitor.connect_timeout` (padrão **1 s**), aplicado via `PDO::ATTR_TIMEOUT` quando o driver suportar, além do que já vier em `extras` do banco.
- **CircuitBreaker**: após `failures` falhas seguidas, o monitor fica desligado por `cooldown_seconds`. Estado em **APCu** quando disponível; **sem APCu**, usa um arquivo-flag em `sys_get_temp_dir()` (nome derivado da chave do banco; leitura com `filemtime`, custo desprezível). Ao abrir, faz **um** `error_log` descrevendo a causa (ex.: tabela inexistente → "rode monitor:install").
- **Sem `fastcgi_finish_request`** (mod_php, servidor embutido do PHP), o flush roda antes de o cliente ser liberado: a latência do flush fica visível. Isso é aceito e documentado; o timeout curto e o breaker limitam o pior caso. O monitor **não** se recusa a ligar nesses ambientes.

### Privacidade e redação

- **SQL**: guardar só o SQL **normalizado** — literais de string e numéricos viram `?`, listas `IN (...)` viram `IN (?)`, espaços colapsados, truncado em 2000 caracteres. Isso é obrigatório porque `Read::exe(free: true)` e o `QueryBuilder` podem receber valores interpolados no texto; não dá para assumir que todo SQL vem com placeholders. `sql_hash` = `sha1` do SQL normalizado.
- **Exceções**: guardar classe, mensagem truncada em 500 caracteres, arquivo, linha, `trace_hash` (para agrupar) e o **stack trace completo** (decisão do dono do projeto), incluindo a **cadeia de `getPrevious()`**: o `Router` embrulha falhas em `Exception("Erro ao executar a rota.")`, então sem a cadeia o trace útil se perderia. O trace é montado a partir de `getTrace()` como `#n arquivo(linha): Classe::método()` — **sem os argumentos dos frames**, que podem conter senhas, tokens ou dados pessoais. Limite de **16 KB** por exceção (truncado com marcador `... [truncado]`). Como exceções são sempre gravadas (peso 1) e o volume acompanha a taxa de erros, o custo de armazenamento é limitado pela retenção bruta de 7 dias.
- **Nunca** gravar body, headers, cookies, token, query string ou valores de bindings.
- `user_id`: string de até 64 caracteres, lida de `Router::$auth->{user_id_property}`; só se `store_user` for verdadeiro.

## Modelo de dados

Timestamps em **UTC** (`DATETIME`, formato `Y-m-d H:i:s`). Durações em ms como `DECIMAL(10,2)`. Todas as tabelas com prefixo `psf_monitor_`. Todas as migrations usam apenas a abstração do Phinx (portabilidade entre os 4 drivers).

| Tabela | Colunas principais | Índices |
|---|---|---|
| `psf_monitor_requests` | `id` (PK auto), `request_id` (varchar 64), `ts`, `method`, `route` (varchar 191), `version`, `status`, `duration_ms`, `memory_kb`, `user_id` (null), `env`, `app_release` (null), `dropped_events` (int, eventos descartados pelo teto), `sample_weight` (decimal) | `ts`; `(route, ts)`; `request_id` |
| `psf_monitor_queries` | `id`, `request_id`, `ts`, `sql_hash` (char 40), `sql_text`, `duration_ms`, `connection_name`, `sample_weight` | `request_id`; `(sql_hash, ts)`; `ts` |
| `psf_monitor_exceptions` | `id`, `request_id`, `ts`, `exception_class`, `message`, `file`, `line_no`, `trace_hash` (char 40), `trace` (text, até 16 KB, cadeia completa sem argumentos), `sample_weight` | `ts`; `(trace_hash, ts)`; `request_id` |
| `psf_monitor_external` | `id`, `request_id`, `ts`, `kind` (`http`/`mail`/`storage`), `target` (varchar 191), `duration_ms`, `status` (null), `sample_weight` | `request_id`; `ts`; `(kind, target, ts)` |
| `psf_monitor_rollups` | `bucket_hour`, `metric` (`request`/`query`/`exception`/`external`), `metric_key` (varchar 191), `hits`, `errors`, `sum_ms`, `max_ms`, `histogram` (JSON em texto), `sample_text` (null; texto de exemplo: SQL normalizado ou mensagem), `last_ts` (última ocorrência na hora) | PK `(bucket_hour, metric, metric_key)` |
| `psf_monitor_state` | `name` (PK), `value` | guarda o watermark do rollup |

Nomes de colunas evitam palavras reservadas dos drivers (`key`, `count`, `class`, `release`, `connection`, `line` viraram `metric_key`, `hits`, `exception_class`, `app_release`, `connection_name`, `line_no`). Mesmo assim, **todo identificador passa pelo quoting do dialeto** no código do monitor, e os exemplos de SQL da documentação para uso direto também aparecem quotados.

`metric_key` do rollup: `METHOD route` para requests; `sql_hash` para queries; `exception_class` para exceções; `kind:target` para externas. Valores maiores que 191 são truncados.

**Semântica dos agregados (parte do contrato):**

- `hits` = `Σ sample_weight`; `errors` = `Σ sample_weight` apenas das linhas com **status ≥ 500** (requests) ou todas as linhas (exceções); 4xx não conta como erro.
- `sum_ms` = `Σ sample_weight × duration_ms`; média = `sum_ms / hits`.
- `histogram` guarda `Σ sample_weight` por bucket (ponderado).
- `max_ms` é o máximo entre as linhas **gravadas**; como lentos e erros são sempre gravados, ele é fiel na prática.
- `sample_text` e `last_ts` existem para o `Reader` conseguir mostrar texto de exemplo e "última ocorrência" mesmo depois que as linhas brutas expiraram (90 dias de agregados contra 7 de brutos).
- Quando o teto `max_events_per_request` descarta eventos, o request registra `dropped_events`; queries e chamadas externas são, portanto, **um piso**, não uma contagem exata, e a documentação deve dizer isso.

**Histograma**: buckets fixos em ms `[10, 25, 50, 100, 250, 500, 1000, 2500, 5000, +∞)`. Percentis (p50/p95/p99) são **estimados por interpolação** dentro do bucket; é aproximado e deve ser documentado assim. Evita `PERCENTILE_CONT`, que não existe nos 4 drivers.

## Instalação das tabelas (Phinx)

As migrations ficam em `src/monitor/migrations/` e **não** são adicionadas ao `paths.migrations` do `phinx.php`. Motivo: se fossem, o `./bin/phinx migrate` normal tentaria criar as tabelas do monitor no banco errado e em projetos que não usam o monitor.

Em vez disso, `monitor:install [-e <env>]` roda o `Manager` do Phinx apontando só para esse diretório, no ambiente do banco configurado em `settings.monitor.database` (ou `default`), com uma tabela de controle própria (`psf_monitor_migrations`), independente da `phinxlog` do projeto. `monitor:status` mostra se está tudo instalado e a versão do esquema.

Os comandos são registrados em `bin/phinx` do mesmo modo que os existentes (`addCommands()`).

### `auto_migrate` (opt-in)

Com `settings.monitor.auto_migrate = true`, o monitor instala as tabelas sozinho quando elas não existem. Regras:

- **Nunca em `prd`**: se `Psf->config('environment')` for `prd`, a flag é ignorada e o monitor faz **um** `error_log` ("auto_migrate ignorado em prd; rode monitor:install"). O caminho de produção continua sendo o comando explícito.
- **Só no flush, depois da resposta**: roda no shutdown (após `fastcgi_finish_request()` quando existir), então o usuário não espera a criação das tabelas.
- **Gatilho**: o `DatabaseSink` falhou **e** a verificação de esquema confirma que faltam tabelas. A verificação usa `listTablesQuery()` do dialeto na conexão dedicada; **não** se interpreta texto de erro do PDO (não portável entre os drivers). Falhas por outro motivo (rede, credencial, timeout) seguem direto para o breaker, sem tentar migrar.
- **Mesmo instalador do `monitor:install`** (mesmo `Manager` do Phinx, mesma tabela de controle `psf_monitor_migrations`): um só caminho de criação de esquema, idempotente.
- **Sem corrida**: antes de migrar, o request pega um lock (`flock` num arquivo em `sys_get_temp_dir()` derivado da chave do banco, não bloqueante). Quem não conseguir o lock **não migra e descarta o lote** daquele request; o breaker não conta isso como falha. Depois de obter o lock, confere de novo se as tabelas ainda faltam.
- **Depois de migrar**, o mesmo request tenta gravar o lote **uma** vez.
- **Phinx ausente** (ele é `require-dev` do framework; o projeto pode não ter instalado): o monitor não quebra; registra **um** `error_log` ("auto_migrate requer robmorgan/phinx") e deixa o breaker abrir.
- **Sem permissão de DDL** no usuário do banco: a migration falha, o monitor registra a causa **uma** vez e o breaker abre.

## Manutenção e retenção

- **`monitor:rollup`**: agrega as horas completas desde o watermark em `psf_monitor_state`. É **idempotente**: recalcula a hora inteira a partir das linhas brutas e substitui o registro (delete + insert numa transação), e só depois avança o watermark.
- **`monitor:prune`**: apaga em **lotes** (ex.: 5 000 linhas por `DELETE`, com pausa curta) por índice de `ts`. Brutos: `ts < now - raw_days`, e, **se já existir watermark de rollup**, nunca além dele (não perde dado ainda não agregado). Enquanto o rollup nunca rodou (fases 1 e 2, ou projeto que ainda não agendou o cron), o prune apaga só por `raw_days`: crescimento limitado vale mais do que preservar dado que ninguém agregou. Na primeira execução, o rollup começa na hora mais antiga que ainda existir nas tabelas brutas. A tabela `psf_monitor_rollups` é criada já na migration da fase 1, para o esquema nascer completo e o contrato não mudar depois. Rollups: `bucket_hour < now - aggregate_days`. Portável entre os 4 drivers (a sintaxe de `DELETE` com limite vem do `DialectInterface`).
- **`monitor:maintain`**: roda `rollup` e depois `prune`. É o comando que o projeto agenda no cron (sugestão: de hora em hora).
- Não há limpeza durante requests (sem "loteria" estilo Pulse).

## Extração (Reader)

`Psf\Monitor\Reader`, só leitura, retorna arrays simples, usa a mesma configuração de banco:

- `routes(from, to, limit)` — por rota: contagem, erros, tempo médio, máximo, p50/p95/p99 (de `rollups`).
- `slowQueries(from, to, limit)` — por `sql_hash`, com texto de exemplo (`sample_text`).
- `exceptions(from, to, limit)` — por classe, contagem e última ocorrência (`last_ts`).
- `external(from, to, limit)` — por `kind:target`.
- `throughput(from, to)` — série por hora.
- `request(request_id)` — drill-down: o request, suas queries, exceções e chamadas externas (de tabelas brutas; só existe dentro da janela de retenção bruta).

Intervalos usam `DateTimeInterface` e são convertidos para UTC. Agregados vêm de `rollups` (rápido, 90 dias); detalhes vêm das tabelas brutas.

O esquema acima é o **contrato**: quem preferir SQL direto, Grafana ou Metabase usa as tabelas diretamente. Mudanças incompatíveis exigem nova versão de esquema e migration.

Um controller JSON opcional (fase 4) expõe o `Reader`; vem **desligado** e o projeto monta a rota com a própria autenticação.

## Fases

1. **Núcleo + requests**: header `X-Request-Id` na resposta, `auto_migrate`, `Monitor`, `Buffer`, `Sampler`, `CircuitBreaker`, `DatabaseSink`, tabela de requests e `state`, ganchos no `Router`, migrations, `monitor:install`, `monitor:status`, `monitor:prune` (para requests), `docs/observabilidade.md` (esquema, config, privacidade).
2. **Queries e exceções**: ganchos em `Read`/`Create`/`Update`/`Delete` e no `dispatchController`; `SqlNormalizer`; captura de fatal; tabelas `queries` e `exceptions`.
3. **Rollups e Reader**: `Histogram`, `Rollup`, `monitor:rollup`, `monitor:maintain`, `Reader`; prune de agregados.
4. **Chamadas externas** (`Email`, `CloudStorage`, `Request`/`Http`) e controller JSON opcional.

Cada fase entrega valor sozinha e termina com testes passando, `composer analyse` (PHPStan nível 1) limpo e docs atualizados.

## Restrições

- PHP 8.1+; APCu opcional (o monitor funciona sem ele).
- Compatível com versões anteriores: nenhuma assinatura pública existente muda; os ganchos são no-op quando desligado.
- `Http::response()` chama `exit`; o flush por `register_shutdown_function` garante a gravação mesmo assim.
- Código novo segue o estilo do repositório; comentários e `docs/` em português do Brasil.
- Não adicionar caminhos paralelos de CRUD: as escritas do monitor são a **única** exceção (conexão PDO dedicada por desenho, para não se instrumentar nem herdar transações da aplicação) e isso deve estar comentado no código.

## Testes

- **Unit** (Pest): `Sampler` (categorias e pesos), `SqlNormalizer` (literais, `IN`, truncamento, hash estável), `Histogram` (buckets e percentis), `CircuitBreaker`, `Buffer` (teto), `Monitor` desligado (nenhuma chamada ao sink).
- **Integration** (SQLite `:memory:`): sink com PDO injetado, fluxo completo boot → eventos → flush → linhas gravadas; `Rollup` idempotente; `Pruner` respeitando o watermark; `Reader`.
- **Anti-deriva do esquema**: um teste roda as **migrations reais** do monitor num SQLite e confere colunas/índices esperados, para o contrato documentado não divergir da migration.
- **`auto_migrate`** (SQLite em arquivo temporário): banco vazio + flag ligada → tabelas criadas e o lote do request gravado; flag ligada em `prd` → nada é criado e há aviso; lock já tomado → lote descartado sem abrir o breaker; segunda execução → idempotente (nada a migrar); Phinx indisponível ou DDL negado → breaker abre sem exceção para o request.
- Falha injetada no sink/PDO: o request termina normalmente e o breaker abre.

## Fica a cargo de quem implementa

- Nomes internos de classes/métodos além dos listados; formato exato do `error_log`.
- Como o `Manager` do Phinx é montado em `monitor:install` (config em memória apontando para `src/monitor/migrations`).
- Detalhes de cada sintaxe de `DELETE` em lotes por driver, via `DialectInterface`.
- Se `PDO::ATTR_TIMEOUT` é aplicado por driver ou só quando suportado.
- Tamanho do lote do prune e pausa entre lotes (valores sugeridos acima).

## Escolhas assumidas (marcadas como minhas, não do dono do projeto)

- `sample.normal` padrão = `0.1`; `max_events_per_request` = 200; breaker = 3 falhas / 300 s; `connect_timeout` = 1 s.
- Semântica dos agregados (ponderada por `sample_weight`; erro = status ≥ 500), colunas extras `sample_text`/`last_ts`/`dropped_events`, nomes de colunas sem palavras reservadas.
- Prune sem watermark apaga só por `raw_days`; `psf_monitor_rollups` nasce na fase 1.
- Fallback do breaker sem APCu por arquivo-flag; sem FPM o monitor liga mesmo assim, com a latência documentada.
- 404 registrado como `(unmatched)`; preflight CORS ignorado; `user_id` aceita objeto ou array.
- Estimador de amostragem por peso (`sample_weight`) em vez de gravar tudo.
- Migrations fora do `paths.migrations` padrão, com tabela de controle própria.
- `auto_migrate` entra na v1 (a pedido do dono do projeto), mas com as salvaguardas acima: opt-in, recusado em `prd`, só após a resposta, detecção de tabela ausente pelo dialeto, lock de arquivo contra corrida.
- `request_id` aceita um `X-Request-Id` de entrada se for válido (`/^[A-Za-z0-9-]{8,64}$/`), para correlacionar com proxy/gateway; senão gera um. O limite de 64 caracteres e o conjunto restrito evitam injeção em logs.
- Trace completo (limite 16 KB, sem argumentos, com cadeia `getPrevious()`), a pedido do dono do projeto.
- Os comandos `monitor:*` ficam em `bin/phinx`, por consistência com os comandos atuais.
