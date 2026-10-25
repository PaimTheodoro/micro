# PSF Framework — Melhorias v0.0.10 → v0.4.0

Documentação das melhorias aplicadas ao framework entre abril de 2026.  
Todas as mudanças são **retrocompatíveis** — nenhuma interface pública foi quebrada.

---

## Índice

- [Resumo das mudanças](#resumo-das-mudanças)
- [Fase 0 — Setup de Testes](#fase-0--setup-de-testes)
- [Fase 1 — Correção de Bugs Críticos](#fase-1--correção-de-bugs-críticos)
- [Fase 2 — Correção de Vulnerabilidades](#fase-2--correção-de-vulnerabilidades)
- [Fase 3 — Suporte a PostgreSQL](#fase-3--suporte-a-postgresql)
- [Fase 4 — Ferramentas de Migration](#fase-4--ferramentas-de-migration)
- [Fase 5 — Divisão de Responsabilidades](#fase-5--divisão-de-responsabilidades)
- [Fase 6 — Modernização PHP 8+](#fase-6--modernização-php-8)
- [Fase 7 — Monitor (observabilidade)](#fase-7--monitor-observabilidade)
- [Arquivos criados](#arquivos-criados)
- [O que ainda está pendente](#o-que-ainda-está-pendente)

---

## Resumo das mudanças

| Fase | Descrição | Arquivos-chave |
|------|-----------|----------------|
| 0 | Pest v3 + testes unitários (44+ testes) | `tests/`, `phpunit.xml` |
| 1 | 5 bugs críticos corrigidos | `ControllerBase`, `Http`, `Router`, `Delete`, `Model` |
| 2 | SQL Injection + JWT timing attack | `Update`, `Delete`, `JWT`, `Model` |
| 3 | PostgreSQL completo + DialectFactory | `src/database/Dialect/` |
| 4 | ModelGenerator e TableAnalyzer reescritos | `Command/ModelGenerator`, `Command/TableAnalyzer` |
| 5 | Extração de ModelSerializer, Hydrator, QueryBuilder, RequestParser | 5 novas classes |
| 6 | PHP 8+: match, enums, tipagem, APCu L2 | `MetadataCache`, `HttpStatusCode`, `StatusCode` |
| 7 | Monitor de observabilidade (fases 1-3: requests, queries, exceções, rollups e `Reader`) | `src/monitor/`, `Router`, `Cors`, `Read/Create/Update/Delete` |

---

## Fase 0 — Setup de Testes

**Objetivo:** Criar rede de segurança antes de qualquer mudança em produção.

### O que foi criado

**Framework de testes:** Pest v3 (sintaxe expressiva, engine PHPUnit, mutation testing nativo)

```
tests/
  bootstrap.php              ← Inicializa PSF singleton + config de teste
  Fixtures/
    config.php               ← JWT secret + DB stub (MySQL, sem conexão real)
    Models/
      FakeUserModel.php      ← Model com todos os Attributes PSF
      FakeProductModel.php   ← Model com FK para FakeUser
  Unit/
    Database/
      DialectTest.php        ← 26 testes, 54 assertions (MySQL, PostgreSQL, SQLServer)
    Model/
      MetadataCacheTest.php  ← 28 assertions
      ControllerBaseTest.php ← 10 testes
    Utils/
      JwtTest.php            ← 8 testes (cobre segurança JWT)
    Migrations/
      ModelGeneratorTest.php ← 17 testes
      TableAnalyzerTest.php  ← 6 testes
phpunit.xml
```

### Como rodar os testes

```bash
composer test
# ou
./vendor/bin/pest
./vendor/bin/pest --testsuite=Unit
```

### Notas técnicas

- APCu desabilitado em testes via `phpunit.xml`: `<ini name="apc.enable_cli" value="0"/>`
- Cada teste que usa models chama `MetadataCache::clearCache()` no `setUp()`
- Testes unitários de Model usam mocks de PDO — nenhum banco real é necessário

---

## Fase 1 — Correção de Bugs Críticos

### Bug 1.1 — `isGet/isPost/isPut/isDelete` sempre retornavam `false`

**Arquivo:** `src/model/ControllerBase.php`

Os quatro métodos usavam `$method` (variável local inexistente) em vez de `$this->method`.

```php
// ANTES — sempre false:
public function isGet(){
    if(strtoupper($method) === "GET"){ return true; }
    return false;
}

// DEPOIS — correto + tipado:
public function isGet(): bool {
    return strtoupper($this->method) === "GET";
}
```

### Bug 1.2 — HTTP Status Code sempre enviava `200` no reason phrase

**Arquivo:** `src/http/Http.php`

```php
// ANTES — enviava "HTTP/1.0 404 200" (inválido):
header("HTTP/1.0  " . $status .  " " . 200);

// DEPOIS:
http_response_code($status); // reason phrase correto automaticamente
```

### Bug 1.3 — `Router::getBody()` — variável `$content` nunca definida

**Arquivo:** `src/http/Router.php`

O bloco que processava `application/x-www-form-urlencoded` nunca executava porque checava `$content` (undefined) em vez de `$contentType`.

### Bug 1.4 — `Delete::exe()` — `return $this` em método estático

**Arquivo:** `src/database/Delete.php` — linha morta removida.

### Bug 1.5 — `ControllerBase::__construct()` — `return false` em construtor

PHP ignora silenciosamente retorno de construtor. O `return false` era código morto; removido.

---

## Fase 2 — Correção de Vulnerabilidades

### Vuln 2.1 — SQL Injection em `Update` / `Delete` via `$terms`

**Arquivos:** `src/database/Update.php`, `src/database/Delete.php`, `src/model/Model.php`

A cláusula WHERE era concatenada diretamente na query. Corrigido com:

1. Novo parâmetro `array $termsParams = []` adicionado ao final das assinaturas (não-breaking):

```php
// Update::exe() — nova assinatura retrocompatível:
public static function exe(
    $table, array $data, $terms,
    $parseString = null,
    $database = 'default',
    array $termsParams = []   // ← NOVO
)
```

2. Novo método privado em `Model`:

```php
// Retorna WHERE com binding seguro:
private function getPrimaryBindings(): array
// Exemplo de retorno:
// ['terms' => 'WHERE tabela.`id` = :pk_bind_id', 'params' => ['pk_bind_id' => 5]]
```

3. `Model::save()` e `Model::delete()` passaram a usar `getPrimaryBindings()` em vez de concatenação.

### Vuln 2.2 — JWT timing attack + falta de verificação de `exp`

**Arquivo:** `src/utils/JWT.php`

```php
// ANTES — vulnerável a timing attack:
if(hash_hmac(...) !== $signature){ ... }

// DEPOIS — comparação em tempo constante:
if(!hash_equals(hash_hmac(...), $signature)){ ... }
```

Também adicionados:
- Verificação de `exp` (expiração)
- Verificação de `nbf` (not before)
- Validação de estrutura (`count($parts) !== 3` → `return false`)

### Vuln 2.3 — `Connect::getColunsForTable()` — interpolação SQL Server

**Arquivo:** `src/database/Connect.php`

```php
// ANTES:
WHERE TABLE_NAME = '{$table}'

// DEPOIS — binding parametrizado (PDO não reutiliza mesmo nome, usa :tbl1/:tbl2):
WHERE TABLE_NAME = :tbl1 AND TABLE_SCHEMA = :tbl2
```

---

## Fase 3 — Suporte a PostgreSQL

### DialectFactory — sistema de dialetos SQL

A principal entrega da Fase 3. Centraliza toda diferença SQL por banco em uma hierarquia de classes, eliminando `if($driver == MySQL) ... if($driver == SQLServer)` espalhado em 5+ arquivos.

**Localização:** `src/database/Dialect/`

```
DialectInterface.php   ← Contrato que todo dialeto implementa
MySQLDialect.php       ← Backticks, AUTO_INCREMENT, ENGINE=InnoDB
SQLServerDialect.php   ← [brackets], IDENTITY(1,1), TOP/OFFSET FETCH
PostgreSQLDialect.php  ← "aspas duplas", SERIAL, LIMIT/OFFSET
DialectFactory.php     ← Cria o dialeto correto pelo driver
```

#### Interface `DialectInterface`

```php
interface DialectInterface {
    public function quoteIdentifier(string $identifier): string;
    public function quoteTable(string $table, ?string $database = null): string;
    public function limitOffsetClause(?int $limit, ?int $offset): string;
    public function topClause(?int $limit, ?int $offset): string;
    public function listTablesQuery(): string;
    public function columnsQuery(string $table): string;
    public function columnsQueryParams(string $table): array;
    public function buildDsn(array $config): string;
    public function autoIncrement(): string;
    public function tableOptions(): string;
    public function addColumnSql(string $column, string $definition): string;
    public function modifyColumnSql(string $column, string $definition): string;
}
```

#### Usando o factory

```php
use Psf\Database\Dialect\DialectFactory;
use Psf\Enumerators\DBDriver;

// Pelo enum:
$dialect = DialectFactory::make(DBDriver::PostgreSQL);

// Pela config do PSF:
$dialect = DialectFactory::fromConfig(PSF::getConfig()->db['default']);

// Exemplo:
$dialect->quoteIdentifier('nome');   // MySQL: `nome`, PG: "nome", MSSQL: [nome]
$dialect->autoIncrement();           // MySQL: AUTO_INCREMENT, PG: SERIAL
$dialect->limitOffsetClause(10, 20); // "LIMIT 10 OFFSET 20"
```

#### Quoting por banco

| Banco | Identificador | Tabela com DB |
|-------|--------------|---------------|
| MySQL | `` `col` `` | `` `db`.`tabela` `` |
| PostgreSQL | `"col"` | `"db"."tabela"` |
| SQL Server | `[col]` | `[db].[dbo].[tabela]` |

#### Paginação por banco

| Banco | Sintaxe |
|-------|---------|
| MySQL | `LIMIT 10 OFFSET 20` |
| PostgreSQL | `LIMIT 10 OFFSET 20` |
| SQL Server (limit only) | `SELECT TOP 10 ...` |
| SQL Server (limit + offset) | `ORDER BY ... OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY` |

### Configuração de conexão PostgreSQL

No arquivo de config PSF (`config.php`):

```php
'db' => [
    'default' => [
        'driver'   => DBDriver::PostgreSQL,
        'host'     => 'localhost',
        'port'     => 5432,
        'base'     => 'meu_banco',
        'user'     => 'postgres',
        'password' => 'senha',
    ]
]
```

O DSN gerado: `pgsql:host=localhost;port=5432;dbname=meu_banco`

---

## Fase 4 — Ferramentas de Migration

### `ModelGenerator` — geração de modelos PSF com Attributes

**Arquivo:** `src/database/Command/ModelGenerator.php`

Reescrito do zero. Antes gerava código estilo Laravel (`$table`, `$fillable`). Agora gera modelos com Attributes PSF corretos.

**Exemplo de modelo gerado:**

```php
<?php
namespace App\Models;

use Psf\Model\Model;
use Psf\Model\Attributes\{Table, Column, PrimaryKey, Type, Nullable, ColumnCreatedDate, ColumnUpdatedDate};

#[Table('usuarios')]
class Usuario extends Model {

    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('nome')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $nome = null;

    #[Column('email')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $email = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]
    #[Type('DATETIME')]
    public ?string $createdAt = null;

    #[Column('updated_at')]
    #[ColumnUpdatedDate]
    #[Type('DATETIME')]
    public ?string $updatedAt = null;
}
```

**Mapeamento de tipos banco → PHP:**

| Tipo banco | Tipo PHP |
|-----------|---------|
| INT, BIGINT | `int` |
| TINYINT(1) | `bool` |
| VARCHAR, TEXT, CHAR | `string` |
| DECIMAL, FLOAT, DOUBLE | `float` |
| DATE, DATETIME, TIMESTAMP | `string` |
| JSON | `string` |

**Detecção automática de attributes especiais:**
- PK + auto-increment → `#[PrimaryKey]`
- `DEFAULT CURRENT_TIMESTAMP` + NOT NULL → `#[ColumnCreatedDate]`
- `ON UPDATE CURRENT_TIMESTAMP` → `#[ColumnUpdatedDate]`
- UUID como PK → `#[PrimaryKey]` + `#[Standard('UUIDV4')]`
- Coluna nullable → `#[Nullable(true)]`
- Coluna NOT NULL → `#[Nullable(false)]`

### `TableAnalyzer` — análise de sincronismo modelo ↔ banco

**Arquivo:** `src/database/Command/TableAnalyzer.php`

Antes: lia propriedades públicas da classe (ignorava `#[Column]` attributes).  
Depois: usa `MetadataCache::getColumnMap()` para comparar nomes de colunas reais.

```bash
# Uso via Phinx:
./vendor/bin/phinx check-model -- --model=App\\Models\\Usuario
```

Output mostra:
- Colunas no banco mas faltando no modelo
- Propriedades no modelo sem coluna correspondente no banco
- Mapeamento `propriedade ↔ coluna`

### `ModelAwareMigration` — migrations driver-aware

**Arquivo:** `src/database/Command/ModelAwareMigration.php`

Usa `DialectFactory` para gerar SQL correto por banco. Diferenças tratadas:

| Operação | MySQL | SQL Server | PostgreSQL |
|---------|-------|-----------|-----------|
| Auto-increment | `AUTO_INCREMENT` | `IDENTITY(1,1)` | `SERIAL` |
| Quoting | `` `col` `` | `[col]` | `"col"` |
| CREATE TABLE extras | `ENGINE=InnoDB...` | — | — |
| Modificar coluna | `MODIFY COLUMN col DEF` | `ALTER COLUMN col DEF` | `ALTER COLUMN col TYPE def` |
| Adicionar coluna | `ADD COLUMN col DEF` | `ADD col DEF` | `ADD COLUMN col DEF` |

---

## Fase 5 — Divisão de Responsabilidades

### `ModelSerializer` — serialização objeto → banco

**Arquivo:** `src/model/ModelSerializer.php`

Extraído de `Model.php`. Converte propriedades de um objeto model em array de dados para INSERT/UPDATE.

```php
// Uso (geralmente interno via Model):
$data = ModelSerializer::serializeFields($usuario);
// ['nome' => 'João', 'email' => 'joao@ex.com', 'created_at' => '2026-04-17 10:00:00']

// Remove PKs (para INSERT):
$data = ModelSerializer::serializeFields($usuario, removePrimarys: true);
```

**O que o serializer faz:**
- Mapeia propriedades PHP → nomes de coluna via `#[Column]`
- Aplica `#[Standard('UUIDV4')]` → gera UUID automaticamente
- Aplica `#[ColumnCreatedDate]` → injeta `NOW()` em INSERT
- Aplica `#[ColumnUpdatedDate]` → injeta `NOW()` em INSERT e UPDATE
- Respeita `#[Nullable(false)]` — ignora `null` values em campos NOT NULL
- Converte enums PHP para seu valor escalar

### `ModelHydrator` — mapeamento banco → objeto

**Arquivo:** `src/model/ModelHydrator.php`

Responsável por descobrir mapeamentos entre propriedades PHP e colunas do banco via Reflection.

```php
// Obtém nome da propriedade pelo nome da coluna:
ModelHydrator::getPropByColumn(Usuario::class, 'created_at');
// → 'createdAt'

// Obtém nome da coluna pela propriedade:
ModelHydrator::getColumnByProp(Usuario::class, 'createdAt');
// → 'created_at'

// Verifica se propriedade é enum:
ModelHydrator::propIsEnum(Usuario::class, 'status');
// → 'App\Enums\StatusEnum' ou false
```

### `QueryBuilder` — construção de queries SQL

**Arquivo:** `src/model/QueryBuilder.php`

Extraído de `ModelQuery.php`. Possui toda lógica de geração SQL, deixando `ModelQuery` apenas como API fluent.

**Estado interno:**

```php
$builder = new QueryBuilder(Usuario::class);

$builder->addAndWhere(['ativo' => true]);
$builder->setLimit(10);
$builder->setOffset(20);
$builder->addOrderBy('nome', 'ASC');
$builder->addLeftJoin(Perfil::class, 'perfil.usuario_id = usuario.id');

$sql = $builder->writeQuery();
// SELECT `usuario`.`id`, `usuario`.`nome` FROM `usuarios` `usuario`
// LEFT JOIN `perfis` `perfil` ON perfil.usuario_id = usuario.id
// WHERE `usuario`.`ativo` = :p0
// ORDER BY `usuario`.`nome` ASC
// LIMIT 10 OFFSET 20
```

**Recursos:**
- Quoting automático via `DialectFactory` (MySQL, PostgreSQL, SQL Server)
- Filtro automático de `status != -1` (registros ativos)
- Filtro automático de `deleted IS NULL` (soft delete)
- Suporte a funções SQL whitelistadas: `SUM`, `COUNT`, `AVG`, `MIN`, `MAX`, `CONCAT`, `COALESCE`, `IFNULL`, `IF`, `CASE`
- JOIN com seleção de campos para hidratação de coleções aninhadas

### `QueryHydrator` — hidratação de resultados

**Arquivo:** `src/model/QueryHydrator.php`

Converte rows do banco em objetos model, incluindo agrupamento de JOIN collections.

**Exemplo — query com relacionamento:**

```php
// Usuário com lista de pedidos:
Usuario::find()
    ->leftJoinAndSelect(Pedido::class, 'pedidos', 'pedidos.usuario_id = usuario.id')
    ->get();

// Retorna:
// [
//   Usuario { id: 1, nome: 'João', pedidos: [Pedido{...}, Pedido{...}] },
//   Usuario { id: 2, nome: 'Maria', pedidos: [] },
// ]
```

O hydrator:
- Agrupa rows pela assinatura do root entity (MD5 das colunas raiz)
- Deduplica relações repetidas pelo JOIN
- Suporta estruturas aninhadas via dot notation nos atributos de join

### `RequestParser` — parsing de corpo HTTP

**Arquivo:** `src/http/RequestParser.php`

Elimina duplicação entre `Router::getBody()` e `ControllerBase::__construct()`.

```php
use Psf\Http\RequestParser;

$parser = new RequestParser();

// Parseia o body (JSON, form, multipart):
$data = $parser->parseBody();
// ['nome' => 'João', 'email' => 'joao@ex.com']

// Extrai token Bearer:
$token = $parser->extractBearerToken();
// 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...'
```

**Suporte a content types:**
- `application/json` → `json_decode(php://input)`
- `application/x-www-form-urlencoded` → `$_POST`
- `multipart/form-data` → `$_POST`
- Sempre mescla com `$_GET`

---

## Fase 6 — Modernização PHP 8+

### `HttpStatusCode` — enum nativo PHP 8.1

**Arquivo:** `src/enum/HttpStatusCode.php`  
**Namespace:** `Psf\Enumerators`

Substitui a classe `StatusCode` (mantida com `@deprecated` para retrocompatibilidade).

```php
use Psf\Enumerators\HttpStatusCode;

// Antes (ainda funciona via StatusCode::OK):
Http::response([], StatusCode::OK);

// Agora (preferido):
Http::response([], HttpStatusCode::OK->value);

// Ou usando o enum diretamente:
$code = HttpStatusCode::NOT_FOUND; // 404
$code->value;                       // 404
$code->name;                        // 'NOT_FOUND'
```

**Casos disponíveis:**

| Enum | Valor |
|------|-------|
| `OK` | 200 |
| `CREATED` | 201 |
| `NO_CONTENT` | 204 |
| `MOVED_PERMANENTLY` | 301 |
| `PERMANENT_REDIRECT` | 308 |
| `BAD_REQUEST` | 400 |
| `UNAUTHORIZED` | 401 |
| `FORBIDDEN` | 403 |
| `NOT_FOUND` | 404 |
| `METHOD_NOT_ALLOWED` | 405 |
| `REQUEST_TIMEOUT` | 408 |
| `INTERNAL_SERVER_ERROR` | 500 |
| `NOT_IMPLEMENTED` | 501 |
| `BAD_GATEWAY` | 502 |
| `SERVICE_UNAVAILABLE` | 503 |
| `GATEWAY_TIMEOUT` | 504 |
| `HTTP_VERSION_NOT_SUPPORTED` | 505 |

### `MetadataCache` — cache L1 + L2 APCu

**Arquivo:** `src/model/MetadataCache.php`

Antes: cache apenas em memória da requisição (array estático, perdido a cada request).  
Agora: dois níveis de cache.

```
L1: Array estático (memória da requisição — instantâneo)
    ↓ miss
L2: APCu (cross-request — TTL 1 hora, quando APCu disponível)
    ↓ miss
Reflection API (lê os Attributes da classe)
```

**Impacto:** Em APIs com múltiplas requisições ao mesmo endpoint, o custo de Reflection é pago apenas na primeira requisição após deploy/reinício do PHP-FPM.

**`clearCache()` limpa por chave específica** (evita flush global do APCu).

### Outras melhorias de modernização

| Item | Antes | Depois |
|------|-------|--------|
| Comparação de driver | `if($driver == MySQL) ... if($driver == SQLServer)` | `match($driver)` |
| Propriedades sem tipo em `ControllerBase` | `public $method` | `public ?string $method = null` |
| `strpos(...) === false` | múltiplos arquivos | `!str_contains()` |
| `strpos(...) === 0` | múltiplos arquivos | `str_starts_with()` |
| `getParses()` retornava string | `"k1=v1&k2=v2"` re-parseada em `Read::exe()` | Retorna `array` diretamente |
| Encoding corrompido | `"Erro (c\xF3digo"` | `"Erro (código"` UTF-8 correto |

---

## Fase 7 — Monitor (observabilidade)

Guia completo em [observabilidade.md](observabilidade.md). Opt-in (`settings.monitor.enabled`); desligado, cada gancho é uma checagem de booleano. Nenhuma assinatura pública existente mudou.

- **Coleta:** `Monitor::boot()` no construtor do `Router`, padrão da rota casada (`(unmatched)` para 404), `user_id` após a autenticação e flush no shutdown (após `fastcgi_finish_request()` quando existir). Preflight CORS não é registrado.
- **Amostragem por peso:** erros (status >= 500) e requests lentos sempre gravados; demais com probabilidade `sample.normal` e `sample_weight = 1/p`, para agregados não enviesados.
- **Armazenamento:** conexão PDO dedicada (`Psf\Monitor\Connection`) e `DatabaseSink` com INSERT multi-linha em transação; `CircuitBreaker` (APCu ou arquivo-flag) impede que um banco de monitoramento ruim afete a API.
- **Esquema:** `psf_monitor_requests`, `psf_monitor_state` e `psf_monitor_rollups` (esta última preenchida pelo `Rollup` a partir da fase 3), como contrato documentado; migrations em `src/monitor/migrations/`, fora do `paths.migrations` do Phinx.
- **Comandos:** `monitor:install`, `monitor:status` e `monitor:prune` (lotes, respeitando watermark, `raw_days` validado) em `bin/phinx`; `auto_migrate` opt-in, recusado em `prd`.
- **`X-Request-Id`:** aceita o de entrada (`[A-Za-z0-9-]{8,64}`) ou gera um; devolvido na resposta e exposto via `Access-Control-Expose-Headers` (`Cors` ganhou a chave `expose_headers`).
- **Fases seguintes:** rollups e `Reader` (fase 3) e chamadas externas e `MonitorApi` (fase 4, Parte A), todos entregues abaixo.

### Fase 2 — queries e exceções

- **Queries:** ganchos nos shims `Read`, `Create::exe/batch`, `Update` e `Delete` (um evento por statement executado). Durante o request só o SQL cru é guardado em `Buffer`; `SqlNormalizer` (tokenizador de passada única) normaliza no flush: literais viram `?`, comentários saem, `IN (...)` e `VALUES (...),(...)` colapsam, limite de 2000 caracteres, hash `sha1`.
- **Exceções:** `ExceptionDescriber` monta o trace sem argumentos de frames, com a cadeia `getPrevious()`. O `Router::dispatchController` registra a exceção original nas falhas 5xx (4xx e `HttpResponseException` ficam de fora); `PDOException` nos shims também. Erro fatal vira `FatalError` e força o status a 500. Teto de 20 exceções por request (o fatal é sempre mantido).
- **Amostragem:** passa a manter com peso 1 também requests com exceção ou com query >= `slow_ms.query` (mesmo além do teto); `max_events_per_request` e `dropped_events` ficam efetivos. Linhas filhas herdam o `sample_weight` do request.
- **Esquema:** `psf_monitor_queries` e `psf_monitor_exceptions` (2 novas migrations, 5 tabelas no total); `monitor:prune` poda as duas também.
- **Atualização:** projetos da fase 1 precisam rodar `vendor/bin/psf monitor:install` (idempotente); sem isso o lote falha e o breaker abre. Com `auto_migrate` fora de `prd` as tabelas são criadas sozinhas.
- **Seams de teste:** `Monitor::events()` e `Monitor::useFatalProvider()`.

### Fase 3 — rollups, `Reader` e rotina de manutenção

- **Rollups:** `Rollup` agrega as horas completas (com folga de 300 s) de requests, queries e exceções em `psf_monitor_rollups`, ponderando por `sample_weight`. Cada hora é recalculada inteira (DELETE + INSERT + avanço do watermark na mesma transação), portanto é idempotente; no máximo 48 horas por execução; `metric_key` truncada em 191 com fusão das chaves que colidem (`mergeByKey`).
- **Histograma:** `Histogram` com 10 buckets fixos (bordas 10, 25, 50, 100, 250, 500, 1000, 2500, 5000 ms), `encode`/`decode`/`merge` e percentis estimados por interpolação (bucket aberto usa `max_ms` como teto). `Watermark` guarda `rollup_watermark` em `psf_monitor_state`.
- **`Reader`:** `routes` (com `orderBy` em whitelist), `slowQueries`, `exceptions`, `throughput` e `request(id)`; intervalo semiaberto em UTC, `limit` em [1, 1000], floats sem arredondar. Agregados vêm dos rollups; só `request()` lê as tabelas brutas.
- **Comandos:** `monitor:rollup` e `monitor:maintain` (rollup + prune), `--max-hours` (1..10000), `retention.*` validados (1..36500) antes de qualquer escrita; `monitor:status` mostra `Rollup até`; `monitor:prune` apaga também agregados (`retention.aggregate_days` passa a ter efeito) e imprime `Agregados apagados`.
- **Robustez do rollup:** a cada execução as 2 horas anteriores ao watermark são reagregadas (linhas atrasadas até 2 h convergem; não contam em `--max-hours`; resultado ganha `reaggregated`); chaves que diferem só em caixa ou espaços à direita são fundidas; hora nova com violação de integridade (SQLSTATE 23xxx) é pulada (`skipped`, linha `Horas puladas`) em vez de travar, perdendo os agregados dela; erro ao ler o watermark (fora tabela ausente) propaga; no SQL Server as datas ligadas vão em ISO 8601 (`SqlDate`). O `monitor:maintain` limpa mesmo se o rollup falhar (exit code 1).
- **Teto do prune:** o corte dos brutos é `max(min(agora - raw_days, watermark), agora - 4 x raw_days)`. Com o rollup parado por mais de `4 x raw_days`, os brutos são apagados sem agregar e essas horas se perdem (decisão para evitar crescimento ilimitado).
- **Esquema:** sem migrations novas (as tabelas `psf_monitor_rollups` e `psf_monitor_state` já existiam). Projetos devem agendar `vendor/bin/psf monitor:maintain` de hora em hora.
- **Fora desta fase:** chamadas externas e controller JSON opcional (entregues na fase 4, abaixo).

### Fase 4, Parte A — chamadas externas e `MonitorApi`

- **Chamadas externas:** `Request::send`, `Http::request` (depreciado), `Email::send` e as quatro operações do `CloudStorage` (`putObject`, `getObject`, `listObjects`, `deleteObject`) passam a reportar ao monitor quando ele está ligado, sem mudar retorno nem exceção (a chamada que falha é registrada uma vez, com `status` nulo). `kind` é `http`, `mail` ou `storage`; o `target` é um rótulo seguro gerado por `ExternalTarget` (só o host, o host SMTP ou `provedor/operação`; nunca URL, query, userinfo, destinatário, bucket nem chave de objeto). Erro = `status IS NULL OR status >= 500` (4xx não é erro).
- **API para chamadas próprias:** `Monitor::trackExternal()`, `external()`, `externalStart()` e `externalEnd()` (nunca lançam; omitir o status em `external()` conta como erro).
- **Teto e amostragem:** `max_events_per_request` vale por tipo de evento (queries e chamadas externas têm baterias separadas; o excedente soma em `dropped_events`); chamada com duração >= `slow_ms.external` (novo, padrão 1000) mantém o request com peso 1, mesmo que tenha sido descartada. `Sampler::weight` ganhou um último parâmetro opcional (`$maxExternalMs`).
- **Esquema:** `psf_monitor_external` (migration `20261026000001_psf_monitor_create_external.php`; 6 tabelas no total); `monitor:prune` e o rollup passam a considerar as quatro tabelas brutas.
- **Rollup e leitura:** métrica `external` (`metric_key = kind:target`); `Reader::external()` e a chave adicional `external` em `Reader::request()`.
- **`MonitorApi`:** serviço JSON opcional, **sem rotas** e desligado por padrão (`settings.monitor.api.enabled`); o projeto monta o controller com `middlewares: ['authentication']` e delega a `handle($endpoint, $params)`, que devolve `[mensagem, dados, status]` (200/404/422/500, sem vazar erro de banco). Endpoints: `routes`, `queries`, `exceptions`, `external`, `throughput`, `request`.
- **Classes existentes alteradas (sem mudança de comportamento):** `Request`, `Http`, `Email` e `CloudStorage` (ganchos), `Sampler::weight` (parâmetro opcional no fim), `Config` (chaves novas `slow_ms.external` e `api.enabled`), `Buffer`, `Installer`, `Pruner`, `Rollup`, `Reader` e `DatabaseSink`.
- **Atualização:** projetos da fase 3 precisam rodar `vendor/bin/psf monitor:install` (idempotente). Até lá, os lotes de requests com chamadas externas falham de forma segura (breaker e uma linha de log) e `monitor:rollup`/`monitor:prune`/`monitor:maintain` falham com "no such table"; com `auto_migrate` fora de `prd` a tabela é criada sozinha.
- **Seams de teste:** `Monitor::events()` também devolve `external`.
- **Limites:** a Parte B (abaixo) validou o monitor em MySQL e PostgreSQL reais; só a validação em SQL Server real segue pendente; os ganchos de `Email` e `CloudStorage` foram verificados com classes de apoio porque PHPMailer e AWS SDK não estão no ambiente de desenvolvimento; só os clientes do framework são medidos (nada de Guzzle, cURL avulso ou PDO).

### Fase 4, Parte B — validação em bancos reais

- **Suíte de drivers:** `tests/Drivers` roda o mesmo roteiro do monitor contra MySQL 8.4 e PostgreSQL 16 reais, com `docker-compose.drivers.yml` e `composer test:drivers`; só executa com `PSF_DRIVERS=1` (sem a variável, os testes são pulados e a suíte padrão continua sem Docker). Validado em MySQL 8.4.13 e PostgreSQL 16.15; **SQL Server segue pendente** (driver ODBC da Microsoft indisponível no ambiente).
- **Quatro defeitos encontrados e corrigidos** (cada um com teste): (1) as migrations declaram `NOT NULL` explícito, porque o Phinx 0.16 cria colunas anuláveis e o MySQL recusava a chave primária anulável; (2) `PostgreSQLDialect::quoteTable()` não prefixa mais o nome do banco (`"banco"."tabela"` era lido como `schema.tabela`), o que quebrava `Read::exe()` e o `QueryBuilder` no PostgreSQL; (3) a grafia da chave fundida no rollup é determinística (menor em ordem binária), em vez de depender da ordem do `GROUP BY`; (4) `Watermark::write()` faz o INSERT num `SAVEPOINT` quando está na transação de quem chama (PostgreSQL), evitando `25P02`.
- **Mudança de comportamento:** `quoteTable()` no PostgreSQL (ver CHANGELOG).
- **Operação:** `monitor:rollup`, `monitor:prune` e `monitor:maintain` sugerem `monitor:install` quando faltam tabelas; a `MonitorApi` registra no `error_log` só a classe da exceção no 500.

---

## Arquivos criados

| Arquivo | Tamanho | O que faz |
|---------|---------|-----------|
| `src/database/Dialect/DialectInterface.php` | 80 linhas | Contrato para dialetos SQL |
| `src/database/Dialect/MySQLDialect.php` | 82 linhas | Implementação MySQL |
| `src/database/Dialect/SQLServerDialect.php` | 92 linhas | Implementação SQL Server |
| `src/database/Dialect/PostgreSQLDialect.php` | 98 linhas | Implementação PostgreSQL |
| `src/database/Dialect/DialectFactory.php` | 27 linhas | Factory por driver |
| `src/model/ModelSerializer.php` | 247 linhas | Objeto → dados do banco |
| `src/model/ModelHydrator.php` | 97 linhas | Mapeamento prop ↔ coluna |
| `src/model/QueryBuilder.php` | 553 linhas | Geração de SQL |
| `src/model/QueryHydrator.php` | 223 linhas | Hidratação de resultados |
| `src/http/RequestParser.php` | 52 linhas | Parsing de body HTTP |
| `src/enum/HttpStatusCode.php` | 24 linhas | Enum de status HTTP |
| `tests/bootstrap.php` | — | Inicialização de testes |
| `tests/Fixtures/config.php` | — | Config de teste |
| `tests/Fixtures/Models/FakeUserModel.php` | — | Model fixture |
| `tests/Fixtures/Models/FakeProductModel.php` | — | Model fixture com FK |
| `tests/Unit/Database/DialectTest.php` | — | 26 testes de dialetos |
| `tests/Unit/Model/MetadataCacheTest.php` | — | 28 assertions |
| `tests/Unit/Model/ControllerBaseTest.php` | — | 10 testes |
| `tests/Unit/Utils/JwtTest.php` | — | 8 testes JWT |
| `tests/Unit/Migrations/ModelGeneratorTest.php` | — | 17 testes |
| `tests/Unit/Migrations/TableAnalyzerTest.php` | — | 6 testes |
| `phpunit.xml` | — | Configuração Pest/PHPUnit |

---

## O que ainda está pendente

Itens marcados como `[ ]` no ROADMAP que ainda não foram implementados:

### Testes faltantes (Fase 0)

- `tests/Unit/Database/` — ReadTest, CreateTest, UpdateTest, DeleteTest, ConnectTest (mocks de PDO)
- `tests/Unit/Http/` — HttpTest, RouterTest, RequestTest
- `tests/Integration/` — CrudIntegrationTest, TransactionTest

### Outros

- ~~**Fase 3:** Testar conexão real com banco PostgreSQL~~ — ✅ feito: a suíte `tests/Drivers` (`composer test:drivers`) conecta em MySQL 8.4 e PostgreSQL 16 reais e valida o monitor neles (migrations, gravações, rollup, prune, `Reader`, `MonitorApi` e comandos); a validação corrigiu o `Read::exe()` no PostgreSQL. SQL Server segue pendente.
- **Fase 3:** Verificar paginator (`COUNT() OVER ()`) com MySQL < 8.0 e adicionar fallback se necessário
- **Fase 5:** `Http::request()` como proxy para `Request` internamente (opcional)
- **Vuln 2.3:** Adicionar warning em dev para free query sem bindings em `Read.php`
- **Bug 1.6:** UUID como PK — documentar como known issue (resolvido por `getPrimaryBindings()`, mas pode precisar de validação extra)

---

*Documento criado em: 2026-04-22*  
*Reflete o estado do branch `codex` após Fases 0–6 concluídas — 82+ testes passando*
