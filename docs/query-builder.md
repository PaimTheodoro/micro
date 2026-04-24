# Query Builder

Este guia mostra como usar o `ModelQuery` com exemplos praticos.

## Pre requisitos

- Seu model deve estender `Psf\Model\Model`
- O model precisa ter atributos `#[Table(...)]` e `#[Column(...)]`
- O ponto de entrada do query builder e `SeuModel::find()`

Exemplo base:

```php
$usuarios = Usuario::find()->all();
```

## Selecao de dados

### Todos os registros

```php
$usuarios = Usuario::find()->all();
```

### Um unico registro

```php
$usuario = Usuario::find()
    ->andWhere(['id' => 10])
    ->one();
```

### Selecao de campos especificos

```php
$usuarios = Usuario::find()
    ->fields([
        Usuario::class . '.id',
        Usuario::class . '.nome',
        'COUNT(p.id) AS totalPedidos'
    ])
    ->leftJoin(['pedidos', 'p'], 'p.usuario_id = usuarios.id')
    ->groupBy(Usuario::class . '.id')
    ->all();
```

## Filtros (WHERE)

### Igualdade

```php
$usuario = Usuario::find()
    ->andWhere([Usuario::class . '.email' => 'user@empresa.com'])
    ->one();
```

### Operadores

```php
$usuarios = Usuario::find()
    ->andWhere([Usuario::class . '.idade', '>', 18])
    ->andWhere([Usuario::class . '.status', '<>', 'inativo'])
    ->all();
```

### IN e NOT IN

```php
$usuarios = Usuario::find()
    ->andWhere([Usuario::class . '.id', 'IN', [1, 2, 3, 4]])
    ->all();
```

### IS NULL

```php
$usuarios = Usuario::find()
    ->andWhere([Usuario::class . '.deleted_at', 'IS NULL'])
    ->all();
```

### OR e AND compostos

```php
$usuarios = Usuario::find()
    ->andWhere([
        'AND',
        [
            [Usuario::class . '.status', '=', 'ativo'],
            ['OR', [
                [Usuario::class . '.tipo', '=', 'admin'],
                [Usuario::class . '.tipo', '=', 'financeiro']
            ]]
        ]
    ])
    ->all();
```

## Ordenacao, limite e paginacao

### Order by

```php
$usuarios = Usuario::find()
    ->orderBy(Usuario::class . '.nome', 'ASC')
    ->all();
```

### Limit

```php
$ultimos = Usuario::find()
    ->orderBy(Usuario::class . '.id', 'DESC')
    ->limit(20)
    ->all();
```

### Paginador integrado

```php
$resultado = Usuario::find()->paginator(page: 2, itensPerPage: 25);

// Estrutura
// [
//   'itens' => [...],
//   'paginator' => [
//      'itens' => ['total', 'perPage', 'inThisPage'],
//      'pages' => ['atual', 'estimated', 'hasBefore', 'hasAfter']
//   ]
// ]
```

## Joins

### INNER JOIN

```php
$usuarios = Usuario::find()
    ->innerJoin([Empresa::class, 'e'], 'e.id = usuarios.empresa_id')
    ->fields([
        Usuario::class . '.id',
        Usuario::class . '.nome',
        'e.nome AS empresa_nome'
    ])
    ->all();
```

### LEFT JOIN simples

```php
$usuarios = Usuario::find()
    ->leftJoin([Empresa::class, 'e'], 'e.id = usuarios.empresa_id')
    ->fields([
        Usuario::class . '.id',
        Usuario::class . '.nome',
        'e.nome AS empresa_nome'
    ])
    ->all();
```

## leftJoinAndSelect (relacionamentos)

O `leftJoinAndSelect` foi pensado para trazer dados relacionados e hidratar em colecoes no resultado.

### Exemplo 1:N (usuario -> empresas)

```php
$usuarios = Usuario::find()
    ->leftJoinAndSelect([Empresa::class, 'e'], 'empresas', 'e.usuario_id = usuarios.id')
    ->all();
```

Formato esperado (asArray):

```php
[
  [
    'id' => 1,
    'nome' => 'Ana',
    'empresas' => [
      ['id' => 10, 'nome' => 'Acme'],
      ['id' => 11, 'nome' => 'Globex']
    ]
  ]
]
```

### Exemplo N:N (usuario <-> empresa via user_empresa)

```php
$usuarios = Usuario::find()
    ->leftJoin(['user_empresa', 'ue'], 'ue.usuario_id = usuarios.id')
    ->leftJoinAndSelect([Empresa::class, 'e'], 'empresas', 'e.id = ue.empresa_id')
    ->all();
```

### Selecao parcial de colunas da relacao

```php
$usuarios = Usuario::find()
    ->leftJoinAndSelect([Empresa::class, 'e'], 'empresas', 'e.id = ue.empresa_id', ['id', 'nome'])
    ->all();
```

### Relacao aninhada

Voce pode usar caminho com ponto para montar estrutura aninhada:

```php
$pedidos = Pedido::find()
    ->leftJoinAndSelect([Cliente::class, 'c'], 'cliente', 'c.id = pedidos.cliente_id')
    ->leftJoinAndSelect([Empresa::class, 'e'], 'cliente.empresas', 'e.cliente_id = c.id')
    ->all();
```

## Agregacoes

### count

```php
$total = Usuario::find()
    ->andWhere([Usuario::class . '.status', '=', 'ativo'])
    ->count();
```

### countAll

```php
$totalFiltrado = Usuario::find()
    ->andWhere([Usuario::class . '.tipo', '=', 'cliente'])
    ->countAll();
```

### sum

```php
$totalVendas = Pedido::find()
    ->andWhere([Pedido::class . '.status', '=', 'pago'])
    ->sum(Pedido::class . '.valor_total');
```

### exist

```php
$jaExiste = Usuario::find()
    ->andWhere([Usuario::class . '.email' => 'user@empresa.com'])
    ->exist();
```

## Query livre

Use quando precisar de um trecho SQL manual:

```php
$dados = Usuario::find()
    ->query('WHERE usuarios.created_at >= :inicio AND usuarios.status = :status', [
        'inicio' => '2026-01-01',
        'status' => 'ativo'
    ]);
```

## Boas praticas de performance

1. Sempre filtre cedo (`andWhere`) para reduzir linhas antes de joins.
2. Em `leftJoinAndSelect`, selecione apenas campos necessarios da relacao com o parametro `$fields`.
3. Evite `SELECT *` em consultas grandes; prefira `fields([...])`.
4. Garanta indices nas colunas usadas em `ON`, `WHERE`, `ORDER BY`.
5. Use paginacao para endpoints de listagem.
6. Use `count()`/`exist()` para validacoes em vez de carregar colecoes completas.
7. Para relacoes muito grandes, considere carregar em duas etapas (ids + detalhes) para controlar memoria.

## Dicas de depuracao

### Ver SQL gerado

```php
$sql = Usuario::find()
    ->andWhere([Usuario::class . '.status', '=', 'ativo'])
    ->getRowQuery();
```

### Ver placeholders

```php
$parses = Usuario::find()
    ->andWhere([Usuario::class . '.id', 'IN', [1, 2, 3]])
    ->getParses();
```

## Fluxo recomendado para consultas complexas

1. Comece com a consulta base (`find`, `andWhere`, `fields`).
2. Adicione joins um por vez.
3. Valide SQL com `getRowQuery()`.
4. So depois acrescente paginacao e ordenacao.
5. Meça tempo da query no banco antes de publicar endpoint pesado.
