# Banco de Dados

- Conexão via `src/database/Connect.php`
- Configuração em `settings.php` ou `.env`
- Comandos de migration (`ModelAwareMigration`, `ModelGenerator`, `TableAnalyzer`) em `src/database/Command/`; as migrations são geradas no `db/migrations` do projeto consumidor. O wrapper `bin/psf` (`bin/phinx`) registra esses comandos e também os do monitor (`monitor:install`, `status`, `rollup`, `prune`, `maintain`; ver [observabilidade.md](observabilidade.md#comandos-cli)). A tradução de `config['db']` em ambientes do Phinx fica em `Psf\Database\PhinxEnvironments` (usada pelo `phinx.php` e pelo instalador do monitor)
- Operações CRUD via classes `Create`, `Read`, `Update`, `Delete` ou pelos métodos dos Models

## Drivers

| Driver | `DBDriver` | Observações |
|--------|-----------|-------------|
| MySQL | `DBDriver::MySQL` | padrão quando `driver` não é informado |
| SQL Server | `DBDriver::SQLServer` | schema `dbo` fixo em `quoteTable`, prefixado pelo banco |
| PostgreSQL | `DBDriver::PostgreSQL` | schema `public` na listagem de tabelas; `quoteTable` ignora o nome do banco e a tabela é resolvida pelo `search_path` da conexão |
| SQLite | `DBDriver::SQLite` | `database` é o caminho do arquivo ou `:memory:`; sem `ALTER COLUMN` |

Configs legadas podem passar o valor inteiro do enum (`'driver' => 1`).

## Chaves de configuração

`hostname`/`username`/`password` e os apelidos `host`/`user`/`pass` são aceitos tanto pelo `Connect` quanto pelo Phinx. SQLite não usa host nem credenciais.

## Erros

Falhas de SQL em `Read`/`Update`/`Delete`/`Connect` respondem 500 com mensagem genérica. A mensagem, o arquivo e a linha só vão no corpo da resposta com `settings.debug = true`; em qualquer caso o erro é registrado com `error_log`.

## Conexão e transações

- A lista de tabelas é lida uma vez por conexão e relida automaticamente quando uma tabela não é encontrada (ex.: criada por migration). `Connect::listTables($db, true)` força a releitura.
- `Connect::getColunsForTable()` memoriza as colunas na requisição; use `Connect::clearColumnsCache()` depois de alterar o schema no mesmo processo.
- `commitTransaction`/`rollBackTransaction` mantêm a conexão em cache e são seguros sem transação ativa.
- `Create`, `Update` e `Delete` fazem rollback da transação ativa quando a query falha.
- `ControllerBase::runServiceTransaction()` faz rollback e relança a exceção se a ação lançar.

## Monitor (observabilidade)

Com `settings.monitor.enabled`, os shims `Read`, `Create::exe`/`Create::batch`, `Update` e `Delete` medem cada statement executado (um evento por `execute()`; em `Read`, junto com o `fetchAll()`) e registram as `PDOException` de `Read`/`Update`/`Delete`. Durante o request só o SQL cru fica em memória; a normalização (sem literais) e a gravação acontecem no shutdown, por uma conexão PDO dedicada que não passa pelo `Connect` (não herda transações da aplicação). Acessos que não passam pelos shims (PDO direto, introspecção de esquema) não são medidos. Detalhes em [observabilidade.md](observabilidade.md#queries).
