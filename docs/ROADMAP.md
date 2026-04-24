# PSF Framework — Roadmap de Melhorias

> **Como usar este documento com IA:**
> Ao iniciar uma nova sessão, diga: _"Leia o arquivo `docs/ROADMAP.md` e me ajude a continuar de onde parei."_
> A IA terá todo o contexto necessário para retomar sem precisar re-analisar o projeto do zero.

---

## Contexto do projeto

**Framework:** PSF (psf/framework) — micro-framework PHP próprio para APIs  
**Versão atual:** 0.0.10  
**PHP mínimo:** 8.0 (na prática 8.1+ recomendado)  
**Bancos suportados hoje:** MySQL, SQL Server (parcial) — PostgreSQL no enum mas sem implementação real  
**Autor:** Theodoro Paim  
**Repositório local:** `c:/laragon/www/framework`

### Estrutura de pastas
```
src/
  database/         → Camada CRUD (Connect, Create, Read, Update, Delete)
  database/Command/ → CLI commands (Phinx): ModelGenerator, TableAnalyzer, ModelAwareMigration
  model/            → ORM via PHP 8 Attributes + Query Builder
  model/attributes/ → #[Table], #[Column], #[PrimaryKey], #[ColumnCreatedDate], etc.
  http/             → Router, Request, Http, RouteCacheManager, ApiDocsGenerator
  helpers/          → UUID, Format, Valid, Text, IP, Device, etc.
  utils/            → JWT, Email, CloudStorage, Webview, APCU
  enum/             → DBDriver, HTTPMethod, HTTPBodyEncoded, Protocol
```

### Restrição crítica
O framework já está em uso em outros projetos. **Toda mudança deve ser retrocompatível.**  
Interfaces públicas não podem mudar sem deprecation. Nomes de classe não podem mudar (ou precisam de class aliases).

---

## Status geral

| Fase | Descrição | Status | Branch sugerida |
|------|-----------|--------|-----------------|
| 0 | Setup de testes (Pest) | ✅ Concluído | `feat/tests-setup` |
| 1 | Correção de bugs críticos | ✅ Concluído | `fix/critical-bugs` |
| 2 | Correção de vulnerabilidades | ✅ Concluído | `fix/security` |
| 3 | Suporte a PostgreSQL | ✅ Concluído | `feat/postgresql` |
| 4 | Correção das ferramentas de migration | ✅ Concluído | `fix/migrations` |
| 5 | Divisão de responsabilidades | ✅ Concluído | `refactor/split-classes` |
| 6 | Modernização PHP 8+ | ✅ Concluído | `refactor/php8` |

### Melhorias pontuais já aplicadas (fora das fases)

Correções realizadas durante revisão de qualidade em 2026-04-16 — sem fase dedicada, já estão no código:

| Item | Arquivo | O que foi feito |
|------|---------|----------------|
| `MetadataCache::getColumnMap()` retornava array indexado em vez de `['prop' => 'coluna']` | `src/model/MetadataCache.php` | Corrigido para delegar a `self::getColumnByProp()` que tem a implementação correta — encontrado pelos testes da Fase 0 |
| Dois `match` blocks duplicando mapeamento do enum | `phinx.php` | Unificado em um único `match` usando `DBDriver::tryFrom()` |
| `$curl = null` sem `curl_close()` | `src/http/Request.php` | Substituído por `curl_close($curl)` explícito em ambos os branches |
| String `"Erro (c\xF3digo"` com encoding corrompido | `src/http/Request.php` | Corrigido para UTF-8 correto `"Erro (código"` |
| `setPathValue` e `appendPathListValue` com ~60 linhas duplicadas | `src/model/ModelQuery.php` | Extraído `traverseNestedPath()` — as duas funções públicas delegam para o helper |
| `Model::serializeData()` chamado para toda row mesmo quando root já estava agrupada | `src/model/ModelQuery.php` | Movido para dentro do `if(!isset($grouped[$signature]))` — só executa para roots novas |
| Comentário explicava WHAT em vez de WHY no branch SQLServer | `src/model/ModelQuery.php` | Reescrito para explicar o motivo do `array_values()` |

**Legenda de status:**
- ⬜ Não iniciado
- 🔄 Em andamento
- ✅ Concluído
- ⏸️ Pausado
- ❌ Descartado (com justificativa)

---

## Fase 0 — Setup de Testes

**Objetivo:** Criar rede de segurança antes de qualquer mudança em código de produção.  
**Esforço estimado:** 2-3 dias  
**Dependências:** Nenhuma — pode ser feita antes de tudo  
**Status:** ✅ Concluído em 2026-04-17

**Nota:** Optou-se por **Pest v3** (em vez de PHPUnit puro) — sintaxe mais expressiva, mesma engine PHPUnit por baixo, mutation testing nativo.

### Tarefas

- [x] Adicionar `pestphp/pest: ^3.0` em `require-dev` no `composer.json`
- [x] Criar `phpunit.xml` na raiz do projeto
- [x] Criar `tests/bootstrap.php` com inicialização do framework (PSF::init) e config de teste
- [x] Criar `tests/Fixtures/config.php` com config de teste (JWT secret + DB stub MySQL)
- [x] Criar `tests/Fixtures/Models/FakeUserModel.php` com Attributes PSF corretos
- [x] Criar `tests/Fixtures/Models/FakeProductModel.php` com FK para FakeUser
- [ ] Criar testes unitários para `Database/` (mocks de PDO)
- [ ] Criar testes unitários para `Http/` (Router, Http, Request)
- [x] Criar testes unitários para `Model/` — `MetadataCacheTest` (28 assertions) e `ControllerBaseTest` (10 testes)
- [ ] Criar testes unitários para `Migrations/` (ModelGenerator, TableAnalyzer, ModelAwareMigration)
- [x] Criar testes unitários para `Utils/` — `JwtTest` (8 testes, cobre Vuln 2.2)
- [ ] Criar testes de integração para CRUD completo

### Estrutura de pastas a criar
```
tests/
  Unit/
    Database/
      ReadTest.php
      CreateTest.php
      UpdateTest.php
      DeleteTest.php
      ConnectTest.php
    Http/
      HttpTest.php
      RouterTest.php
      RequestTest.php
    Model/
      ModelTest.php
      ModelQueryTest.php
      MetadataCacheTest.php
      ControllerBaseTest.php
    Migrations/
      ModelGeneratorTest.php
      TableAnalyzerTest.php
      ModelAwareMigrationTest.php
  Integration/
    Database/
      CrudIntegrationTest.php
      TransactionTest.php
  Fixtures/
    Models/
      FakeUserModel.php
      FakeProductModel.php
    config.php
  bootstrap.php
phpunit.xml
```

### Detalhes técnicos importantes
- **APCu:** Desabilitar no `phpunit.xml` via `<ini name="apc.enable_cli" value="0"/>` — o `RouteCacheManager` e `MetadataCache` usam APCu e não podem depender dele em testes
- **MetadataCache:** Chamar `MetadataCache::clearCache()` no `setUp()` de cada teste que usa modelos
- **PSF singleton:** O `PSF::getConfig()` é um singleton global — o bootstrap precisa inicializá-lo antes de qualquer teste
- **Mocks de PDO:** Usar `$this->createMock(\PDO::class)` e `$this->createMock(\PDOStatement::class)` para testes unitários de `Create`, `Read`, `Update`, `Delete`

### Exemplo de `phpunit.xml`
```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <php>
        <ini name="apc.enable_cli" value="0"/>
    </php>
    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </coverage>
</phpunit>
```

### Exemplo de FakeUserModel esperado
```php
<?php
namespace Tests\Fixtures\Models;

use Psf\Model\Model;
use Psf\Model\Attributes\{Table, Column, PrimaryKey, ColumnCreatedDate, Nullable, Type};

#[Table('fake_users')]
class FakeUserModel extends Model {
    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('name')]
    #[Nullable(false)]
    public ?string $name = null;

    #[Column('email')]
    #[Nullable(false)]
    public ?string $email = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]
    public ?string $createdAt = null;
}
```

---

## Fase 1 — Correção de Bugs Críticos

**Objetivo:** Corrigir bugs funcionais confirmados que não são breaking changes.  
**Esforço estimado:** 1 dia  
**Dependências:** Fase 0 concluída (para validar com testes)  
**Status:** ✅ Concluído em 2026-04-16

### Bugs a corrigir

#### Bug 1.1 — `ControllerBase::isGet/isPost/isPut/isDelete` sempre retornam false
- **Arquivo:** `src/model/ControllerBase.php` — linhas ~42-67
- **Problema:** Os quatro métodos usam `$method` (variável local inexistente) em vez de `$this->method`
- **Impacto:** Alto — esses métodos sempre retornam `false`, são silenciosamente inutilizáveis
- **Fix:**
  ```php
  // ANTES:
  public function isGet(){
      if(strtoupper($method) === "GET"){ return true; }
      return false;
  }
  // DEPOIS:
  public function isGet(): bool {
      return strtoupper($this->method) === "GET";
  }
  ```
- [x] Aplicar fix nos 4 métodos (isGet, isPost, isPut, isDelete)
- [x] Adicionar tipo de retorno `bool` nos 4 métodos
- [ ] Escrever teste unitário

#### Bug 1.2 — HTTP Status Code sempre envia `200` no header
- **Arquivo:** `src/http/Http.php` — linha ~54
- **Problema:** `header("HTTP/1.0  " . $status . " " . 200)` — o literal `200` está hardcoded. Uma resposta 404 envia `HTTP/1.0 404 200` (inválido)
- **Impacto:** Médio — o número do status vai correto mas o reason phrase está sempre errado
- **Fix:**
  ```php
  // ANTES:
  header("HTTP/1.0  " . $status .  " " . 200);
  // DEPOIS:
  http_response_code($status);
  // ou com reason phrase:
  header("HTTP/1.1 " . $status . " " . StatusCode::getReasonPhrase($status));
  ```
- [ ] Adicionar método `getReasonPhrase(int $code): string` em `StatusCode.php` com mapa de reason phrases _(opcional — `http_response_code()` já inclui reason phrase)_
- [x] Corrigir o `header()` em `Http.php` — substituído por `http_response_code($status)`
- [x] Trocar HTTP/1.0 por HTTP/1.1 _(`http_response_code()` usa HTTP/1.1 automaticamente)_
- [ ] Escrever teste unitário

#### Bug 1.3 — `Router::getBody()` — `$content` nunca definida
- **Arquivo:** `src/http/Router.php` — linha ~247
- **Problema:** `if (!empty($content))` — `$content` nunca é atribuída; a variável correta é `$contentType` (definida na linha anterior). O bloco que lida com `application/x-www-form-urlencoded` nunca executa.
- **Impacto:** Médio — afeta o logging de requisições com body form-encoded
- **Fix:**
  ```php
  // ANTES linha ~247:
  if (!empty($content)) {
  // DEPOIS:
  if (!empty($contentType)) {
  ```
- [x] Aplicar fix
- [ ] Escrever teste unitário

#### Bug 1.4 — `Delete.php` — `return $this` em método estático
- **Arquivo:** `src/database/Delete.php` — linha ~46 (dentro do `exe()` estático, após o if)
- **Problema:** Código morto — `return $this` em método `static` nunca é alcançado mas causa confusão em análise estática
- **Fix:** Remover a linha
- [x] Remover linha

#### Bug 1.5 — `ControllerBase::__construct()` — `return false` em construtor
- **Arquivo:** `src/model/ControllerBase.php`
- **Problema:** PHP ignora retorno de construtor silenciosamente. O `return false` quando não há `Authorization` header não faz nada
- **Fix:** Remover o `return false` — deixar `$this->token` como `null` (já é o default)
- [x] Remover o `return false`
- [x] Garantir que `$this->token` tem valor `null` por padrão

#### Bug 1.6 — PKs não-numéricas sem aspas em `getPrimarysQuery`
- **Arquivo:** `src/model/Model.php` — método `getPrimarysQuery()`
- **Problema:** Gera `WHERE tabela.id = abc` sem aspas para PKs string (UUIDs). Funciona para int, quebra para UUID
- **Impacto:** Alto para projetos que usam UUID como PK
- **Nota:** Este bug será completamente eliminado na Fase 2 (quando bindings substituírem concatenação), mas deve ser documentado aqui para awareness
- [ ] Documentar como known issue até Fase 2 resolver

---

## Fase 2 — Correção de Vulnerabilidades de Segurança

**Objetivo:** Eliminar riscos de SQL Injection e timing attack.  
**Esforço estimado:** 3-4 dias  
**Dependências:** Fase 1 concluída  
**Status:** ✅ Concluído em 2026-04-16

### Vulnerabilidades a corrigir

#### Vuln 2.1 — SQL Injection em `Update` / `Delete` via `$terms`
- **Arquivos:** `src/database/Update.php`, `src/database/Delete.php`
- **Problema:** O parâmetro `$terms` (cláusula WHERE) é concatenado diretamente na query. Valores dinâmicos no WHERE não passam por prepared statements.
- **Cadeia mais perigosa:** `Model::save()` → `getPrimarysQuery(true)` → `WHERE tabela.id = {valor_sem_binding}` → passado como `$terms` para `Update::exe()`
- **Estratégia (não-breaking):** Adicionar parâmetro opcional ao final da assinatura: `array $termsParams = []`
  ```php
  // Update::exe() — assinatura atual:
  public static function exe($table, array $data, $terms, $parseString = null, $database = 'default')
  // Nova assinatura (não-breaking — parâmetro com default no final):
  public static function exe($table, array $data, $terms, $parseString = null, $database = 'default', array $termsParams = [])
  // O execute() faz merge: $data + $places + $termsParams
  ```
- [x] Adicionar `$termsParams` em `Update::exe()`
- [x] Adicionar `$termsParams` em `Delete::exe()`
- [x] Criar método privado `Model::getPrimaryBindings(): array` que retorna `['terms' => 'WHERE tabela.id = :pk_bind_id', 'params' => ['pk_bind_id' => 5]]`
- [x] Refatorar `Model::save()` para usar `getPrimaryBindings()` em vez de `getPrimarysQuery(true)`
- [x] Refatorar `Model::delete()` idem
- [ ] Escrever testes para os novos paths

#### Vuln 2.2 — JWT timing attack
- **Arquivo:** `src/utils/JWT.php`
- **Problema:** Comparação de assinatura com `!==` permite timing attack. Também não verifica `exp`.
- **Fix:**
  ```php
  // ANTES:
  if(hash_hmac(...) !== $signature){
  // DEPOIS:
  if(!hash_equals(hash_hmac(...), $signature)){
  
  // Adicionar verificação de expiração:
  $payload = json_decode(base64_decode($parts[1]), true);
  if(isset($payload['exp']) && $payload['exp'] < time()){
      return false; // token expirado
  }
  ```
- [x] Trocar `!==` por `!hash_equals()`
- [x] Adicionar verificação de `exp`
- [x] Adicionar verificação de `nbf` (not before) se existir
- [x] Adicionar validação de estrutura do token (`count($parts) !== 3` → `return false`)
- [ ] Escrever testes unitários para JWT

#### Vuln 2.3 — Free query em `Read.php`
- **Arquivo:** `src/database/Read.php`
- **Problema:** Quando `$free = true`, a query inteira vem de `$string` sem garantia de binding. O `Connect::Command()` usa isso sem parâmetros.
- **Nota:** É um recurso intencional para queries administrativas/DDL. A solução não é remover, mas guardar.
- **Fix:**
  ```php
  // Em ambiente dev, emitir aviso se free query sem bindings:
  if($free === true && empty($parseString) && ($_ENV['APP_ENV'] ?? 'dev') !== 'production'){
      trigger_error("Free query sem bindings detectada. Certifique-se de que não há dados de usuário na query.", E_USER_NOTICE);
  }
  ```
- [ ] Adicionar docblock `@param` e `@throws` claros em `Read::exe()`
- [ ] Adicionar warning em dev para free query sem bindings
- [ ] Adicionar docblock em `Connect::Command()` explicitando uso apenas para DDL/admin
- **Nota:** Risco baixo — `free=true` é intencional para DDL/admin, não há input de usuário no uso atual

#### Vuln 2.4 — `Connect::getColunsForTable()` SQL Server — interpolação de nome de tabela
- **Arquivo:** `src/database/Connect.php` — método `getColunsForTable()`
- **Problema:** `WHERE TABLE_NAME = '{$table}'` — interpolação direta. Risco baixo porque `$table` vem de `verifyTableExist()`, mas má prática.
- **Fix:** Usar query parametrizada
- [x] Substituir interpolação por binding no SQL Server — usa `:tbl1` e `:tbl2` (dois params porque PDO em modo não-emulado não suporta reutilização do mesmo nome)
- **Nota MySQL:** `SHOW COLUMNS FROM` não suporta parâmetros PDO (DDL statement) — risco aceitável pois `$table` vem de `#[Table]` attribute, não de input de usuário

---

## Fase 3 — Suporte a PostgreSQL

**Objetivo:** Adicionar suporte real a PostgreSQL em toda a stack (conexão, CRUD, query builder, migrations).  
**Esforço estimado:** 1 semana  
**Dependências:** Fase 2 concluída (bindings corretos facilitam o trabalho)  
**Status:** ✅ Concluído em 2026-04-17

### Contexto
- `DBDriver::PostgreSQL = 3` já existe no enum
- `phinx.php` já mapeia para adapter `pgsql`
- **Mas:** conexão PDO, quoting de identificadores, queries de schema e migrations são MySQL-only

### Tarefas

#### 3.1 — Criar `DatabaseDialect` — centralizador de SQL por driver ⭐ (tarefa mais importante)

Esta é a tarefa central que resolve a duplicação de `if($driver == MySQL) ... if($driver == SQLServer)` espalhada em 5+ arquivos.

**Estrutura a criar:**
```
src/database/Dialect/
  DialectInterface.php
  MySQLDialect.php
  SQLServerDialect.php
  PostgreSQLDialect.php   ← NOVO
  DialectFactory.php
```

**Interface:**
```php
interface DialectInterface {
    public function quoteIdentifier(string $identifier): string;
    public function quoteTable(string $table, ?string $database = null): string;
    public function listTablesQuery(): string;
    public function columnsQuery(string $table): string;
    public function buildDsn(array $config): string;
    public function limitOffset(?int $limit, ?int $offset): string;
    public function autoIncrement(): string;         // AUTO_INCREMENT vs SERIAL vs IDENTITY
    public function modifyColumnSql(string $table, string $column, string $definition): string;
}
```

**`DialectFactory`:**
```php
class DialectFactory {
    public static function make(DBDriver $driver): DialectInterface {
        return match($driver) {
            DBDriver::MySQL      => new MySQLDialect(),
            DBDriver::SQLServer  => new SQLServerDialect(),
            DBDriver::PostgreSQL => new PostgreSQLDialect(),
            DBDriver::SQLite     => new SQLiteDialect(), // futuro
        };
    }
}
```

- [x] Criar `DialectInterface.php`
- [x] Criar `MySQLDialect.php` (extraindo lógica existente do MySQL)
- [x] Criar `SQLServerDialect.php` (extraindo lógica existente do SQL Server)
- [x] Criar `PostgreSQLDialect.php` (NOVO — implementar tudo do zero)
- [x] Criar `DialectFactory.php`
- [x] Escrever testes unitários para cada dialeto — `DialectTest.php` (26 testes, 54 assertions total na suite)

#### 3.2 — `Connect.php` — conexão e schema para PostgreSQL

**DSN:**
```php
// PostgreSQL:
"pgsql:host={$hostname};port={$port};dbname={$base}"
```

**`listTables()` para PostgreSQL:**
```sql
SELECT tablename FROM pg_tables WHERE schemaname = 'public'
```

**`getColunsForTable()` para PostgreSQL:**
```sql
SELECT
    c.column_name AS "Field",
    c.data_type   AS "Type",
    c.is_nullable AS "Null",
    CASE WHEN pk.column_name IS NOT NULL THEN 'PRI' ELSE '' END AS "Key"
FROM information_schema.columns c
LEFT JOIN (
    SELECT ku.column_name
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage ku
      ON tc.constraint_name = ku.constraint_name
     AND tc.table_name = ku.table_name
    WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = :table
) pk ON c.column_name = pk.column_name
WHERE c.table_name = :table
ORDER BY c.ordinal_position
```

- [x] Refatorar `doConnect()` para usar `DialectFactory::fromConfig()->buildDsn()` — PostgreSQL funciona automaticamente
- [x] Refatorar `listTables()` para usar `DialectFactory::fromConfig()->listTablesQuery()`
- [x] Refatorar `getColunsForTable()` para usar `columnsQuery()` + `columnsQueryParams()` — suporte a PostgreSQL incluído
- [ ] Testar conexão com banco PostgreSQL real

#### 3.3 — `Create.php` e `Update.php` — quoting para PostgreSQL

PostgreSQL usa aspas duplas para identificadores: `"coluna"`, `"tabela"`

- [x] Refatorar `Create.php` para usar `DialectFactory::fromConfig()->quoteIdentifier()` em campos e tabela
- [x] Refatorar `Update.php` para usar `DialectFactory::fromConfig()->quoteIdentifier()` em campos e tabela
- [ ] Escrever testes com mock de conexão PostgreSQL

#### 3.4 — `ModelQuery.php` — quoting e JOINs

**`generateField()` — quoting incompleto:**
```php
// ANTES (PostgreSQL cai em sem quoting):
$quote = function(string $identifier) use ($driver): string {
    if ($driver === DBDriver::MySQL) return '`' . $identifier . '`';
    if ($driver === DBDriver::SQLServer) return '[' . $identifier . ']';
    return $identifier; // ← PostgreSQL sem quotes
};
// DEPOIS:
$dialect = DialectFactory::make($driver);
$quote = fn($id) => $dialect->quoteIdentifier($id);
```

**JOINs com backtick hardcoded:**
```php
// ANTES:
$stringQuery .= " INNER JOIN `" . $tableName . "` ON ...";
// DEPOIS:
$stringQuery .= " INNER JOIN " . $dialect->quoteIdentifier($tableName) . " ON ...";
```

**LIMIT/OFFSET para PostgreSQL** (mesma sintaxe do MySQL — só falta o case):
```php
// ANTES: só MySQL tinha LIMIT/OFFSET
if($driver == DBDriver::MySQL){ ... }
// DEPOIS:
if(in_array($driver, [DBDriver::MySQL, DBDriver::PostgreSQL])){ ... }
```

**`paginator()` — window function `COUNT() OVER ()`:**
- PostgreSQL ✅ suporta (8.4+)
- SQL Server ✅ suporta
- MySQL ✅ suporta (8.0+)
- MySQL < 8.0 ❌ — adicionar fallback com 2 queries separadas se necessário
- [ ] Verificar versão do MySQL em uso nos projetos existentes
- [ ] Adicionar fallback caso necessário

- [x] Refatorar `generateField()` para usar `DialectFactory::fromConfig()->quoteIdentifier()`
- [x] Refatorar `handleTableName()` e `getHandleTableName()` para usar `quoteTable()`
- [x] Refatorar INNER JOIN backtick hardcoded para usar `quoteIdentifier()`
- [x] Refatorar LIMIT/OFFSET para usar `limitOffsetClause()` — PostgreSQL incluído automaticamente
- [x] Refatorar TOP clause para usar `topClause()` (SQLServer)
- [x] Refatorar `getAllFields()` para usar `quoteIdentifier()`
- [ ] Resolver `paginator()` para MySQL < 8.0 (se aplicável)
- [ ] Escrever testes para `ModelQuery` com PostgreSQL

---

## Fase 4 — Correção das Ferramentas de Migration

**Objetivo:** Corrigir dois bugs graves nas ferramentas de CLI (ModelGenerator e TableAnalyzer) e tornar a `ModelAwareMigration` driver-aware.  
**Esforço estimado:** 1 semana  
**Dependências:** Fase 3.1 (`DialectFactory`) para a `ModelAwareMigration`  
**Status:** ✅ Concluído em 2026-04-17

### Contexto crítico

Existem dois bugs graves **não relacionados ao PostgreSQL** que precisam ser corrigidos primeiro:

1. `ModelGenerator` gera modelos com `$table`, `$fillable`, `$casts` — estilo Laravel. O PSF usa PHP 8 Attributes. Os modelos gerados **não funcionam** com o framework.
2. `TableAnalyzer` compara propriedades públicas da classe sem ler `#[Column]` attributes — ignora o mapeamento ORM completamente.

### Tarefas

#### 4.1 — `ModelGenerator.php` — reescrever geração de modelos PSF

**Problema:** O gerador atual produz código Laravel-style:
```php
class User extends Model {
    protected $table = 'users';
    protected $fillable = ['name', 'email'];
    public string $name;
}
```

**Deve produzir código PSF com Attributes:**
```php
#[Table('users')]
class User extends Model {
    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('name')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $name = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]
    #[Type('DATETIME')]
    public ?string $createdAt = null;
}
```

**Mapeamento de colunas para atributos especiais:**
- PK com `AUTO_INCREMENT` / `SERIAL` / `IDENTITY` → `#[PrimaryKey]`
- `DEFAULT CURRENT_TIMESTAMP` + NOT NULL + sem update → `#[ColumnCreatedDate]`
- `ON UPDATE CURRENT_TIMESTAMP` → `#[ColumnUpdatedDate]`
- Coluna nullable → `#[Nullable(true)]` (ou omitir, pois é default)
- Coluna NOT NULL → `#[Nullable(false)]`
- UUID como PK → `#[PrimaryKey]` + `#[Standard('UUIDV4')]`

**Mapeamento de tipos do banco para PHP:**
| Tipo banco | Tipo PHP |
|-----------|---------|
| INT, BIGINT, TINYINT(1 sem bool) | `int` |
| TINYINT(1) bool | `bool` |
| VARCHAR, TEXT, CHAR | `string` |
| DECIMAL, FLOAT, DOUBLE | `float` |
| DATE, DATETIME, TIMESTAMP | `string` |
| JSON | `string` (com note) |

- [x] Reescrever método `generateModelCode()` para usar Attributes PSF
- [x] Implementar lógica de detecção de `#[ColumnCreatedDate]` / `#[ColumnUpdatedDate]`
- [x] Implementar detecção de PK auto-increment vs UUID
- [x] Gerar propriedade com camelCase (ex: `created_at` → `$createdAt`) com `#[Column('created_at')]`
- [x] Escrever testes que validam o código PHP gerado — `ModelGeneratorTest.php` (17 testes)

#### 4.2 — `TableAnalyzer.php` — usar MetadataCache para comparação

**Problema:** O analyzer atual:
```php
// Lê propriedades públicas — ignora #[Column] attributes
$properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
```

**Deve usar:**
```php
// Lê o mapeamento real do ORM via MetadataCache
$modelColumns = MetadataCache::getColumnMap($modelClass);
// retorna: ['propertyName' => 'column_name_no_banco']
```

A comparação deve ser entre:
- Nomes de **colunas no banco** (via adapter Phinx → `$adapter->getColumns($tableName)`)
- Nomes de **colunas do mapeamento ORM** (via `MetadataCache::getColumnMap($modelClass)`)

- [x] Refatorar `getModelColumns()` para usar `MetadataCache::getColumnMap()`
- [x] Refatorar comparação para usar nomes de coluna reais (não nomes de propriedade PHP)
- [x] Comparar também tipos: PHP Reflection type vs tipo do banco
- [x] Output mostra mapeamento propriedade ↔ coluna
- [x] Escrever testes — `TableAnalyzerTest.php` (6 testes)

#### 4.3 — `ModelAwareMigration.php` — driver-aware

**Problema:** A geração de SQL é completamente MySQL-cêntrica:
- Usa backticks em todo lugar
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
- `AUTO_INCREMENT` na definição de coluna
- `MODIFY COLUMN` (sintaxe MySQL) — SQL Server usa `ALTER COLUMN`, PostgreSQL usa `ALTER COLUMN ... TYPE`

**Estratégia:** Usar `DialectFactory` da Fase 3.1 para tudo que é driver-específico.

**Diferenças por driver no CREATE TABLE:**
| Item | MySQL | SQL Server | PostgreSQL |
|------|-------|-----------|-----------|
| Quoting | `` `col` `` | `[col]` | `"col"` |
| Auto-increment | `AUTO_INCREMENT` | `IDENTITY(1,1)` | `SERIAL` ou `GENERATED ALWAYS AS IDENTITY` |
| Encoding | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | N/A | N/A |
| PK syntax | `PRIMARY KEY (col)` | `PRIMARY KEY (col)` | `PRIMARY KEY (col)` |

**Diferenças por driver no ALTER TABLE:**
| Operação | MySQL | SQL Server | PostgreSQL |
|---------|-------|-----------|-----------|
| Modificar coluna | `MODIFY COLUMN col DEF` | `ALTER COLUMN col DEF` | `ALTER COLUMN col TYPE def` |
| Adicionar coluna | `ADD COLUMN col DEF` | `ADD col DEF` | `ADD COLUMN col DEF` |
| Remover coluna | `DROP COLUMN col` | `DROP COLUMN col` | `DROP COLUMN col` |

- [x] Adicionar `getDialect(AdapterInterface $adapter): DialectInterface` — detecta driver do Phinx
- [x] Refatorar `generateCreateTableSql()` para ser driver-aware via DialectFactory (`quoteIdentifier`, `tableOptions`)
- [x] Refatorar `generateAlterTableSql()` para usar `addColumnSql()` e `modifyColumnSql()` do dialect

---

## Fase 5 — Divisão de Responsabilidades

**Objetivo:** Reduzir complexidade de classes grandes extraindo responsabilidades em classes menores, mantendo a API pública intacta via facade/delegation pattern.  
**Esforço estimado:** 1 semana  
**Dependências:** Fase 3 concluída (para que a extração já use DialectFactory)  
**Status:** ✅ Concluído em 2026-04-17

### 5.1 — `Model.php` (677 linhas) → extrair para classes especializadas

**Responsabilidades atuais e destino:**
| Responsabilidade | Classe atual | Nova classe |
|-----------------|-------------|------------|
| Serialização de campos para insert/update | `Model` | `ModelSerializer` |
| Hidratação de dados do banco para objeto | `Model` | `ModelHydrator` |
| CRUD (create, save, delete) | `Model` | `Model` (mantém) |
| Utilitários de metadados (getTable, etc.) | `Model` | `MetadataCache` (já existe, expandir) |

**Estratégia:** `Model.php` continua com a mesma API pública mas delega internamente:
```php
// Model.php após refatoração — API pública idêntica:
public static function serializeFields($object, $removePrimarys = FALSE): array {
    return ModelSerializer::serializeFields($object, $removePrimarys); // delega
}
```

- [x] Criar `src/model/ModelSerializer.php` com `serializeFields()` e `serializeData()`
- [x] Criar `src/model/ModelHydrator.php` com `getPropByColumn()`, `getColumnByProp()`, `propIsEnum()`
- [x] Refatorar `Model.php` para delegar para as novas classes (724 → ~270 linhas)
- [x] Garantir que todos os métodos públicos de `Model` continuam existindo e funcionando
- [x] Rodar suite de testes completa para confirmar retrocompatibilidade

### 5.2 — `ModelQuery.php` (~1000 linhas) → extrair para classes especializadas

**Responsabilidades atuais e destino:**
| Responsabilidade | Nova classe |
|-----------------|------------|
| Construção de query SQL (writeQuery, generateField) | `QueryBuilder` |
| Condições WHERE (buildConditionClause) | `QueryBuilder` |
| Hidratação de resultados com JOIN collections | `QueryHydrator` |
| Paginação | `ModelQuery` (mantém) |

**Estratégia:** `ModelQuery` usa composição:
```php
class ModelQuery {
    private QueryBuilder $builder;
    private QueryHydrator $hydrator;

    public function __construct($class, $startWith = NULL){
        $this->builder  = new QueryBuilder($class, $startWith);
        $this->hydrator = new QueryHydrator($class);
        // ...
    }
    // Todos os métodos públicos (andWhere, leftJoin, etc.) permanecem idênticos
}
```

- [x] Criar `src/model/QueryBuilder.php` — owns query state + SQL generation
- [x] Criar `src/model/QueryHydrator.php` — hydration with JOIN collections
- [x] Refatorar `ModelQuery.php` para usar composição (~960 → ~230 linhas)
- [x] Garantir que a interface fluent pública permanece idêntica
- [x] Rodar suite de testes

### 5.3 — `RequestParser` — eliminar código duplicado

O parsing de body HTTP está duplicado em `Router::getBody()` e `ControllerBase::__construct()`:

- [x] Criar `src/http/RequestParser.php` com `parseBody(): array` e `extractBearerToken(): ?string`
- [x] Refatorar `Router::getBody()` para usar `RequestParser::parseBody()`
- [x] Refatorar `ControllerBase::__construct()` para usar `RequestParser`

### 5.4 — Deprecar `Http::request()`

`Http.php` tem dois métodos com responsabilidades opostas:
- `request()` — cliente HTTP outbound (legacy)
- `response()` — resposta HTTP inbound (manter)

A classe `Request.php` com fluent builder já existe e é a forma correta. O `Http::request()` é legacy.

- [x] Adicionar `@deprecated` em `Http::request()` — "Use `\Psf\Http\Request::get/post/put/delete()` instead"
- [ ] Fazer `Http::request()` ser um proxy para `Request` internamente _(opcional — nenhum teste existente depende disso)_
- [ ] **NÃO remover** — manter para retrocompatibilidade

---

## Fase 6 — Modernização PHP 8+

**Objetivo:** Substituir padrões PHP 7 residuais por equivalentes modernos.  
**Esforço estimado:** 3-4 dias  
**Dependências:** Fase 5 concluída (classes menores são mais fáceis de modernizar)  
**Status:** ✅ Concluído em 2026-04-17

### Tarefas

#### 6.1 — `if/else if` de driver → `match`
- **Arquivos:** `Model.php`, `ModelQuery.php`, `Connect.php` (muito simplificado após Fase 3 com DialectFactory)
- [x] Substituir chains de `if($driver == X)` por `match($driver)` em `Model.php`
- [x] `getPrimaryBindings()` refatorado para usar `DialectFactory` — elimina if/else completamente
- [x] `getPrimarysQuery()` usa `match` + `DialectFactory::quoteIdentifier()`
- [x] `getIdentityColumn()` usa `match` com `default => null`

#### 6.2 — Propriedades tipadas
- [x] `ControllerBase.php`: `public $method/data/token` → `public ?string/array/string = null`
- [x] `QueryBuilder.php`: `private $obj` → `private object $obj`

#### 6.3 — `getParses()` retornar array em vez de string
- **Arquivo:** `src/model/ModelQuery.php` — `getParses()`
- **Problema atual:** Retorna string `"key1=val1&key2=val2"` que é re-parseada em `Read::exe()`. É um anti-pattern — dados estruturados como string têm bug latente para valores com `=` dentro.
- **Fix (não-breaking):**
  ```php
  // getParses() passa a retornar array
  public function getParses(): array|false { ... }
  // Read::exe() aceita ambos:
  public function exe($table, $string = null, array|string|null $parseString = null, ...)
  ```
- [x] `QueryBuilder::getParses()` retorna `array|false` (elimina serialização desnecessária)
- [x] `Read::exe()` aceita `array|string|null` — array path direto, string path de retrocompatibilidade
- [x] `explode('=', $item, 2)` com limite para suportar valores que contêm `=`

#### 6.4 — `StatusCode` → criar enum `HttpStatusCode`
- **Estratégia de dois passos para não quebrar:**
  1. Criar `HttpStatusCode: int` enum com os mesmos valores
  2. Usar o enum internamente no framework
  3. Adicionar `@deprecated` na classe `StatusCode`
  4. Em versão futura (0.2.0+), remover a classe
- [x] Criar `src/enum/HttpStatusCode.php` como enum backed int (`Psf\Enumerators\HttpStatusCode`)
- [x] Usar `HttpStatusCode` internamente em `Router.php` (todos os StatusCode:: substituídos)
- [x] Adicionar `@deprecated` em `StatusCode.php`

#### 6.5 — `MetadataCache` com L2 APCu cross-request
- **Atualmente:** Cache apenas em memória da requisição (array estático)
- **Melhoria:** Usar APCu como L2 quando disponível (metadados de classe não mudam entre requisições)
  ```php
  private static function fetch(string $class, string $key, callable $producer): mixed {
      // L1: memória da requisição (instantâneo)
      if (isset(self::$cache[$class][$key])) return self::$cache[$class][$key];

      // L2: APCu cross-request (se disponível)
      if (extension_loaded('apcu') && apcu_enabled()) {
          $cacheKey = "psf_meta_{$class}_{$key}";
          $value = apcu_fetch($cacheKey, $success);
          if ($success) return self::$cache[$class][$key] = $value;
      }

      $value = $producer();
      self::$cache[$class][$key] = $value;
      if (extension_loaded('apcu') && apcu_enabled()) {
          apcu_store("psf_meta_{$class}_{$key}", $value, 3600);
      }
      return $value;
  }
  ```
- [x] Implementar L2 APCu no `MetadataCache::fetch()` — L1 memória, L2 APCu (TTL 1h) quando disponível
- [x] `clearCache()` limpa APCu por chave específica (evita flush global)

#### 6.6 — Substituir `strpos()` por funções PHP 8
- [x] `TableAnalyzer.php` — 5 ocorrências → `str_contains()`
- [x] `APCU.php` — `strpos(...) === 0` → `str_starts_with()`
- [x] `Request.php` — `strpos(...) === 0` → `str_starts_with()`
- [x] `Router.php` — `strpos(...) === false` → `!str_contains()`

#### 6.7 — Encoding UTF-8
- ~~`src/http/Request.php:74` tem `"Erro (c\xF3digo"` — caractere corrompido~~ ✅ corrigido
- [x] `src/http/Request.php` corrigido anteriormente (Fase 1) — encoding UTF-8 correto

---

## Issues e observações adicionais

Itens identificados que não se encaixam em nenhuma fase mas devem ser endereçados:

### ORM / Model
- `Model::serializeFields()` com ~120 linhas e múltiplas responsabilidades — parcialmente resolvido na Fase 5
- `MetadataCache::isAttributeType()` e `Model::isAttributeType()` são idênticos — duplicação; unificar na Fase 5

### Query Builder
- `ModelQuery::dump()` usa `var_dump` + `die` — debug code. Avaliar se deve ser removido ou melhorado para ambientes de desenvolvimento
- `ModelQuery::getRowQuery()` hardcoda campos `status` e `deletado` com fallback por nome — acoplamento implícito que pode surpreender

### ModelTrait (`src/model/ModelTrait.php`) ✅ corrigido em 2026-04-16
- ~~`findById(int $id)` assume que a PK sempre se chama `id`~~ → corrigido para usar `Model::getPrimaryKey(self::class, 'prop')` + aceita `mixed $id` para suportar UUIDs
- ~~`__call()` perde camelCase e tem fallback inseguro em funções globais~~ → corrigido para usar `lcfirst(substr($function, 3))` e lança `BadMethodCallException` para métodos desconhecidos
- ~~`hasError()` com if aninhado desnecessário~~ → simplificado para retornar a expressão diretamente com `: bool`

### Migrations
- `ModelAwareMigration::createMigrationFile()` usa `file_put_contents` manual para criar arquivo — pode usar a API interna do Phinx para melhor integração
- Migrations geradas usam `$this->execute("SQL")` com SQL raw — considerar usar a API de Phinx (`$this->table()`, `$this->addColumn()`, etc.) para migrations mais portáveis entre bancos

### Segurança adicional
- Implementação manual de JWT em `src/utils/JWT.php` — considerar migrar para `firebase/php-jwt` ou `lcobucher/jwt` em versão futura (Fase 6 ou posterior)
- `Http::response()` não define `Access-Control-Allow-Origin` — depende da configuração do servidor, mas vale documentar

### Performance
- `RouteCacheManager` em modo dev calcula hash baseado em `mtime` de todos os arquivos de controllers a cada requisição — pode ser caro em projetos com muitos controllers
- `Connect::listTables()` é chamado a cada `getConnection()` — verificar se está sendo cacheado adequadamente

---

## Decisões de arquitetura registradas

| Data | Decisão | Motivo |
|------|---------|--------|
| 2026-04-16 | Usar facade pattern para divisão de classes (Model, ModelQuery) | Retrocompatibilidade total sem breaking changes |
| 2026-04-16 | Criar `DialectFactory` antes de adicionar PostgreSQL | Evita adicionar mais um `if($driver == PostgreSQL)` espalhado; centraliza lógica |
| 2026-04-16 | Manter `StatusCode` como classe + criar `HttpStatusCode` enum | Migração gradual sem breaking — projetos existentes usam `StatusCode::OK` como constante |
| 2026-04-16 | `Http::request()` deprecado mas não removido | Retrocompatibilidade — projetos existentes podem estar usando |
| 2026-04-17 | Usar Pest v3 em vez de PHPUnit puro | Sintaxe mais expressiva (closures estilo Jest), mutation testing nativo, mesma engine por baixo |
| 2026-04-17 | Testes unitários usam MySQL como stub de config; DB não é conectado | SQLite não é suportado no Connect.php; unidades que precisam de DB mocam PDO diretamente |

---

## Versioning sugerido

| Versão | Conteúdo |
|--------|---------|
| 0.1.0 | Fases 1 + 2 (bug fixes + segurança) |
| 0.2.0 | Fase 3 (PostgreSQL) + Fase 4 (migrations corrigidas) |
| 0.3.0 | Fase 5 (divisão de classes) |
| 0.4.0 | Fase 6 (modernização PHP 8+) |

---

*Documento criado em: 2026-04-16*  
*Última atualização: 2026-04-17 — Fases 3–6 concluídas; DialectFactory, PostgreSQL, split de classes (ModelSerializer, ModelHydrator, QueryBuilder, QueryHydrator, RequestParser), HttpStatusCode enum, MetadataCache L2 APCu, modernização PHP 8+; 82 testes passando*
