# Introdução

Este micro framework PHP foi criado para facilitar o desenvolvimento de APIs e aplicações web de forma simples, rápida e organizada. Ele segue princípios de PSR-4, separação de responsabilidades e fácil extensibilidade.

## Estrutura de Pastas

- `src/` - Código-fonte principal
    - `database/` - Operações de banco de dados (CRUD, comandos)
    - `database/Command/` - CLI Phinx: ModelGenerator, TableAnalyzer, ModelAwareMigration
    - `database/Dialect/` - Dialetos SQL por banco (MySQL, PostgreSQL, SQL Server)
    - `enum/` - Enums: DBDriver, HTTPMethod, HttpStatusCode, etc.
    - `helpers/` - Funções utilitárias
    - `http/` - Roteamento, requisições e respostas HTTP
    - `model/` - Models, traits, atributos, QueryBuilder, hidratação
    - `utils/` - Utilitários diversos (cache, email, JWT, etc)
- `tests/` - Suite de testes (Pest v3)
- `bin/` - Scripts executáveis
- `vendor/` - Dependências Composer

## Princípios
- PSR-4 autoloading
- Código limpo e componentizado
- Foco em produtividade e clareza 
## Guias
- `docs/guia.md` - **Guia prático** — instalação, config, models, rotas, migrations e testes
- `docs/query-builder.md` - Guia completo de consultas com o ModelQuery
- `docs/melhorias.md` - Documentação de todas as melhorias aplicadas (v0.0.10 → v0.4.0)
