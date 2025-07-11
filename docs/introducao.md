# Introdução

Este micro framework PHP foi criado para facilitar o desenvolvimento de APIs e aplicações web de forma simples, rápida e organizada. Ele segue princípios de PSR-4, separação de responsabilidades e fácil extensibilidade.

## Estrutura de Pastas

- `src/` - Código-fonte principal
    - `database/` - Operações de banco de dados (CRUD, comandos)
    - `enum/` - Enums úteis para HTTP, banco, etc.
    - `helpers/` - Funções utilitárias
    - `http/` - Roteamento, requisições e respostas HTTP
    - `model/` - Models, traits e atributos
    - `utils/` - Utilitários diversos (cache, email, JWT, etc)
- `db/` - Scripts e comandos para banco de dados
- `bin/` - Scripts executáveis
- `vendor/` - Dependências Composer

## Princípios
- PSR-4 autoloading
- Código limpo e componentizado
- Foco em produtividade e clareza 