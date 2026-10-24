# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). O histórico anterior (v0.0.10 → v0.4.0) está em [docs/melhorias.md](docs/melhorias.md).

## [Não lançado]

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
- **PHP mínimo é 8.1** (o código já usava enums). `robmorgan/phinx` fica em `^0.16` (estável) e `minimum-stability` volta a `stable`.

- **Referências a entidades** (ver [docs/query-builder.md](docs/query-builder.md#referências-a-entidades-e-colunas)): todos os pontos da query (where em array/associativo/string, ON de join, `orderBy`, `groupBy`, `fields`, expressões) passam pelo mesmo `ReferenceResolver`. Na prática: `tabela.propriedade`, o nome solto da propriedade e `alias.propriedade` agora são traduzidos para a coluna (antes só `Classe::class.'.prop'` era); a referência pela classe de uma entidade com alias passa a usar o alias; e nomes de classe colados em `(`, `=`, `,` ou dentro de `SUM(...)` agora resolvem (antes geravam SQL quebrado).
- **Palavras soltas em strings cruas**: só as propriedades da entidade raiz com coluna de nome diferente são traduzidas (e qualificadas com a tabela/alias). Antes, a tradução de palavras soltas dependia do último nome de classe da string (estado que vazava entre tokens).
- **`andWhere([coluna => null])`** vira `IS NULL` (antes comparava com `= NULL`, que nunca casa). Um valor array vira `IN (...)`.
- **Aliases de join** são validados (`^[A-Za-z_]\w*$`); antes entravam crus no SQL.
- `ModelQuery::query()` também traduz as referências do trecho livre.

### Adicionado
- `Modelo::col('prop', ?alias)` (referência validada), `ModelQuery::alias()`, `ModelQuery::strict()`, join com alias em três argumentos (`innerJoin(Produto::class, 'p', 'p.userId = o.id')`) e where associativo com várias chaves.
- Modo estrito opcional (`settings.strictQueries`) para referências que não resolvem.
- `SQLiteDialect` e `DBDriver::SQLite` em `DialectFactory`; `DialectFactory` aceita o valor inteiro do enum.
- Middleware CORS (`settings.cors`) com resposta de preflight — `Psf\Http\Cors`.
- Operadores `>=`, `<=`, `!=`, `NOT LIKE`, `BETWEEN`/`NOT BETWEEN` nas condições em array; `IS NULL` com três elementos.
- `RouteCacheManager::clear()`; chave do cache de rotas com hash do app.
- `Connect::listTables($db, $refresh)` e `Connect::clearColumnsCache()`.
- `tests/Integration` (SQLite `:memory:`), CI no GitHub Actions (PHP 8.2–8.4) e PHPStan nível 1.
- Aliases de config `host`/`user`/`pass` também no `Connect` e nos dialetos.

### Corrigido
- Rotas dinâmicas nunca casavam com path iniciado por `/`; regex inline com `/` ou `{n}` quebrava o split do path; versão com mais de um dígito.
- Controller instanciado duas vezes por requisição; `apache_request_headers()` fatal em php-fpm; `$_GET['_url']` ausente.
- `Read::getRowCount()` usava `rowCount()` em SELECT (0 no SQLite, -1 no SQL Server), fazendo `->one()`/`->all()` não retornarem linhas.
- `Model::getIdentityColumn()` (variáveis inexistentes, fatal no SQL Server); `ModelQuery::execute()` não idempotente; `ModelQuery::query()` sem `SELECT` e com propriedades inexistentes; `COUNT` sem chave primária.
- `Update`/`Delete`/`Read` ignoravam o dialeto (tabela sem quoting, crases de MySQL fixas); placeholders do `Update` dependiam do nome da coluna.
- `getPrimarysQuery(true)` concatenava o valor cru (agora `PDO::quote`).
- `quoteTable`/`addColumnSql`/`modifyColumnSql` sem escape do identificador; `LEFT JOIN` sem quoting.
- `phinx.php`: porta e charset padrão por driver; ambientes SQLite eram descartados.
- Comandos do Phinx (`make:migration-from-model`, `make:model-from-table`, `analyze:table-vs-model`) nunca eram registrados (o Phinx não aceita comandos pela config) e, uma vez registrados, falhavam por falta de `bootstrap()`/`parent::configure()`, por obter o adapter do lugar errado e por gerar migration com nome de classe inválido. `bin/phinx` agora os registra; `bin/phinx` também avisa quando `robmorgan/phinx` não está instalado.
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
