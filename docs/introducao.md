# Introdução

Este micro framework PHP foi criado para facilitar o desenvolvimento de APIs e aplicações web de forma simples, rápida e organizada. Ele segue princípios de PSR-4, separação de responsabilidades e fácil extensibilidade.

## Estrutura de Pastas

- `src/` - Código-fonte principal
    - `database/` - Operações de banco de dados (CRUD, comandos)
    - `database/Command/` - CLI Phinx: ModelGenerator, TableAnalyzer, ModelAwareMigration
    - `database/Dialect/` - Dialetos SQL por banco (MySQL, PostgreSQL, SQL Server, SQLite)
    - `enum/` - Enums: DBDriver, HTTPMethod, HttpStatusCode, etc.
    - `helpers/` - Funções utilitárias
    - `http/` - Roteamento, requisições e respostas HTTP, CORS
    - `model/` - Models, traits, atributos, QueryBuilder, hidratação
    - `monitor/` - Monitor de observabilidade (requests, queries, exceções e chamadas externas, com rótulos seguros em `ExternalTarget`; rollups e `Reader`)
        - `monitor/Api/` - `MonitorApi`, serviço JSON opcional de leitura (desligado por padrão)
        - `monitor/Sinks/` - Gravação dos lotes no banco (`DatabaseSink`)
        - `monitor/Command/` - Comandos `monitor:install`, `status`, `rollup`, `prune` e `maintain`
        - `monitor/migrations/` - Migrations das tabelas `psf_monitor_*` (instaladas pelo `monitor:install`, fora do `migrate` do projeto)
    - `utils/` - Utilitários diversos (cache, email, JWT, etc)
- `bin/psf` (e `bin/phinx`, mesmo wrapper) - Wrapper do Phinx: inicia o PSF e registra os comandos de migration e `monitor:*`
- `phinx.php` - Configuração do Phinx gerada a partir de `config['db']`
- `tests/` - Suite de testes (Pest v3)
    - `Unit/` - Testes unitários (`Database`, `Http`, `Model`, `Monitor`, `Utils`)
    - `Integration/` - Testes em SQLite `:memory:` (CRUD, transações, `Monitor`)
    - `Fixtures/` - Configs de teste, models, controllers e helpers do monitor
- `docs/` - Guias de uso (ver abaixo)
- `vendor/` - Dependências Composer

## Princípios
- PSR-4 autoloading
- Código limpo e componentizado
- Foco em produtividade e clareza 
## Guias
- [`docs/guia.md`](guia.md) - **Guia prático** — instalação, config, models, rotas, migrations, testes e monitor
- [`docs/rotas.md`](rotas.md) - Rotas, parâmetros, erros nos controllers, CORS e cache de rotas
- [`docs/models.md`](models.md) - Models e atributos
- [`docs/database.md`](database.md) - Conexão, drivers, CRUD, transações e comandos do Phinx
- [`docs/query-builder.md`](query-builder.md) - Guia completo de consultas com o ModelQuery
- [`docs/helpers.md`](helpers.md) - Helpers utilitários
- [`docs/exemplos.md`](exemplos.md) - Exemplos de uso real
- [`docs/observabilidade.md`](observabilidade.md) - Monitor de observabilidade: configuração, tabelas, `Reader` e comandos `monitor:*`
- [`docs/melhorias.md`](melhorias.md) - Documentação de todas as melhorias aplicadas (v0.0.10 → v0.4.0 e o monitor)
- [`docs/ROADMAP.md`](ROADMAP.md) - Roadmap e status das fases
- [`CHANGELOG.md`](../CHANGELOG.md) - Mudanças ainda não lançadas
