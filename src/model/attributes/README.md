# Atributos do Model Psf

Este diretório contém todos os atributos disponíveis para uso nos models do framework Psf.

## Atributos de Classe

### Table
Define o nome da tabela no banco de dados.

```php
#[Table('nome_da_tabela')]
class MinhaClasse extends Model {
    // ...
}
```

### Database
Define qual banco de dados usar para a classe.

```php
#[Table('minha_tabela'), Database('meu_banco')]
class MinhaClasse extends Model {
    // ...
}
```

## Atributos de Propriedade

### Column
Mapeia uma propriedade a uma coluna do banco de dados.

```php
#[Column('nome_da_coluna')]
public $minhaPropriedade;
```

### PrimaryKey
Marca uma propriedade como chave primária.

```php
#[Column('id'), PrimaryKey]
public $id;
```

### Type
Define o tipo de dados da coluna.

```php
#[Column('data_criacao'), Type('timestamp')]
public $dataCriacao;
```

### Standard
Define valores padrão para propriedades.

```php
#[Column('status'), Standard(StatusEnum::Ativo)]
public $status;
```

### Enum
Define a classe enum associada a uma propriedade.

```php
#[Column('status'), Enum(StatusEnum::class)]
public $status;
```

### Nullable
Define se uma propriedade pode ser nula.

```php
#[Column('descricao'), Nullable(true)]
public $descricao;
```

### ColumnCreatedDate
Marca propriedades de data de criação (automático).

```php
#[Column('created'), ColumnCreatedDate, Type('timestamp')]
public $created;
```

### ColumnUpdatedDate
Marca propriedades de data de atualização (automático).

```php
#[Column('updated'), ColumnUpdatedDate, Type('timestamp')]
public $updated;
```

### ColumnDeletedDate
Marca propriedades de data de exclusão (soft delete).

```php
#[Column('deleted'), ColumnDeletedDate, Type('timestamp')]
public $deleted;
```

## Exemplo Completo

```php
<?php

namespace App\Models;

use \Psf\Model\{Model, ModelTrait};
use \Psf\Model\Attributes\{Column, Table, PrimaryKey, Type, Standard, Enum, Nullable, ColumnCreatedDate, ColumnUpdatedDate, ColumnDeletedDate, Database};

use \App\Utils\Enumerators\{StatusEnum};

#[Table('usuarios'), Database('portalpass')]
class Usuario extends Model {
    use ModelTrait;

    #[Column('id'), PrimaryKey]
    public $id;

    #[Column('nome')]
    public $nome;

    #[Column('email'), Nullable(false)]
    public $email;

    #[Column('status'), Standard(StatusEnum::Ativo), Enum(StatusEnum::class)]
    public $status;

    #[Column('data_nascimento'), Type('date'), Nullable(true)]
    public $dataNascimento;

    #[Column('created'), ColumnCreatedDate, Type('timestamp')]
    public $created;

    #[Column('updated'), ColumnUpdatedDate, Type('timestamp')]
    public $updated;

    #[Column('deleted'), ColumnDeletedDate, Type('timestamp')]
    public $deleted;
}
```

## Funcionalidades Automáticas

- **ColumnCreatedDate**: Automaticamente define a data/hora atual quando um registro é criado
- **ColumnUpdatedDate**: Automaticamente define a data/hora atual quando um registro é atualizado
- **ColumnDeletedDate**: Automaticamente define a data/hora atual quando um registro é marcado como deletado (soft delete)
- **Standard**: Define valores padrão que são aplicados automaticamente quando a propriedade está vazia
- **Enum**: Permite trabalhar com enums do PHP 8.1+ de forma integrada
- **Nullable**: Valida se campos obrigatórios não estão vazios 