# Guia Prático — PSF Framework

Tudo que você precisa para construir uma API com o PSF do zero.

---

## Índice

1. [Instalação](#1-instalação)
2. [Configuração](#2-configuração)
3. [Criando um Model](#3-criando-um-model)
4. [Criando um Controller](#4-criando-um-controller)
5. [Criando Rotas](#5-criando-rotas)
6. [CRUD — operações no banco](#6-crud--operações-no-banco)
7. [Buscas com o Query Builder](#7-buscas-com-o-query-builder)
8. [Migrations](#8-migrations)
9. [Testes](#9-testes)

---

## 1. Instalação

```bash
composer require psf/framework
```

### Estrutura mínima de um projeto

```
meu-projeto/
  config/
    config.php       ← configurações do framework
  app/
    Controllers/
    Models/
  db/
    migrations/      ← migrations geradas pelo Phinx
    seeds/
  public/
    index.php        ← entry point
    .htaccess
```

### `public/index.php`

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

PSF::init(['config' => __DIR__ . '/../config/config.php']);

// Registra seus controllers para que o Router encontre as rotas
$controllers = [
    App\Controllers\UsuarioController::class,
    App\Controllers\ProdutoController::class,
];

$router = new \Psf\Http\Router(...$controllers);
$router->handle();
```

### `public/.htaccess`

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?_url=$1 [QSA,L]
```

---

## 2. Configuração

Crie `config/config.php` retornando um array com as seções abaixo.

### Configuração completa comentada

```php
<?php

use Psf\Enumerators\DBDriver;

return [

    // ─── Banco de dados ──────────────────────────────────────────────────────
    'db' => [

        // Conexão padrão (obrigatória)
        'default' => [
            'driver'   => DBDriver::MySQL,      // MySQL, PostgreSQL, SQLServer
            'hostname' => 'localhost',
            'username' => 'root',
            'password' => 'senha',
            'database' => 'meu_banco',
            'port'     => 3306,
        ],

        // Segunda conexão (opcional — para multi-banco)
        'legado' => [
            'driver'   => DBDriver::SQLServer,
            'hostname' => '192.168.1.10',
            'username' => 'sa',
            'password' => 'senha',
            'database' => 'banco_legado',
            'port'     => 1433,
        ],
    ],

    // ─── JWT ─────────────────────────────────────────────────────────────────
    'jwt' => [
        'secret' => 'troque-por-uma-chave-secreta-longa',
    ],

    // ─── Configurações gerais ────────────────────────────────────────────────
    'settings' => [
        // Classe e método responsável por validar o token (middleware 'authentication')
        'verifyauth' => [App\Controllers\AuthController::class, 'verify'],

        // Classe e método para logar requisições (middleware 'loggin')
        'logrequest' => [App\Controllers\LogController::class, 'save'],

        // URL que serve a documentação automática da API (opcional)
        'docsapi' => 'api/docs',
    ],

];
```

### Drivers disponíveis

| Constante | Banco |
|-----------|-------|
| `DBDriver::MySQL` | MySQL / MariaDB |
| `DBDriver::PostgreSQL` | PostgreSQL |
| `DBDriver::SQLServer` | SQL Server |

---

## 3. Criando um Model

Models ficam em `app/Models/`. Cada model estende `Model` e usa PHP Attributes para mapear colunas.

### Exemplo completo

```php
<?php

namespace App\Models;

use Psf\Model\Model;
use Psf\Model\ModelTrait;
use Psf\Model\Attributes\Table;
use Psf\Model\Attributes\Database;
use Psf\Model\Attributes\Column;
use Psf\Model\Attributes\PrimaryKey;
use Psf\Model\Attributes\Type;
use Psf\Model\Attributes\Nullable;
use Psf\Model\Attributes\Standard;
use Psf\Model\Attributes\ColumnCreatedDate;
use Psf\Model\Attributes\ColumnUpdatedDate;
use Psf\Model\Attributes\ColumnDeletedDate;

#[Table('usuarios')]
#[Database('default')]   // opcional — omitir usa 'default'
class Usuario extends Model
{
    use ModelTrait;      // habilita find(), findById(), etc.

    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('nome')]
    #[Nullable(false)]
    #[Type('VARCHAR(100) NOT NULL')]
    public ?string $nome = null;

    #[Column('email')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $email = null;

    #[Column('status')]
    #[Standard('ativo')]          // valor padrão no INSERT
    public ?string $status = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]          // preenchido automaticamente no INSERT
    #[Type('DATETIME')]
    public ?string $createdAt = null;

    #[Column('updated_at')]
    #[ColumnUpdatedDate]          // atualizado automaticamente no UPDATE
    #[Type('DATETIME')]
    public ?string $updatedAt = null;

    #[Column('deleted_at')]
    #[ColumnDeletedDate]          // soft delete: save() com timestamp em vez de DELETE
    #[Type('DATETIME')]
    public ?string $deletedAt = null;
}
```

### Referência de Attributes

| Attribute | O que faz |
|-----------|-----------|
| `#[Table('nome')]` | Nome da tabela no banco |
| `#[Database('conn')]` | Qual conexão usar (padrão: `default`) |
| `#[Column('col')]` | Nome da coluna no banco |
| `#[PrimaryKey]` | Marca como chave primária |
| `#[Type('DEF')]` | Tipo SQL da coluna (usado nas migrations) |
| `#[Nullable(false)]` | NOT NULL — ignora valor `null` no INSERT |
| `#[Standard('valor')]` | Valor padrão quando a propriedade está `null` |
| `#[Standard('UUIDV4')]` | Gera UUID v4 automaticamente no INSERT |
| `#[ColumnCreatedDate]` | Injeta `NOW()` no INSERT, nunca toca no UPDATE |
| `#[ColumnUpdatedDate]` | Injeta `NOW()` no INSERT e UPDATE |
| `#[ColumnDeletedDate]` | Soft delete: `delete()` preenche a coluna em vez de apagar |
| `#[Enum(EnumClass::class)]` | Mapeia para um enum PHP ao hidratar |

### Model com UUID como PK

```php
#[PrimaryKey]
#[Column('id')]
#[Standard('UUIDV4')]          // UUID gerado automaticamente
#[Type('CHAR(36) NOT NULL')]
public ?string $id = null;
```

### Model usando segunda conexão

```php
#[Table('clientes')]
#[Database('legado')]          // aponta para a conexão 'legado' do config
class Cliente extends Model { ... }
```

---

## 4. Criando um Controller

Controllers ficam em `app/Controllers/`. Estendem `ControllerBase` para ter acesso ao body da requisição, token JWT, e métodos de transação.

```php
<?php

namespace App\Controllers;

use Psf\Model\ControllerBase;
use Psf\Http\Http;
use App\Models\Usuario;

class UsuarioController extends ControllerBase
{
    // $this->data   → body da requisição (array)
    // $this->token  → Bearer token extraído do header Authorization
    // $this->method → método HTTP (GET, POST, etc.)

    public function index()
    {
        $usuarios = Usuario::find()->all();
        Http::response('OK', $usuarios);
    }

    public function show(int $id)
    {
        $usuario = Usuario::findById($id);

        if (!$usuario) {
            Http::response('Usuário não encontrado', [], 404);
        }

        Http::response('OK', $usuario);
    }

    public function store()
    {
        $usuario = new Usuario();
        $usuario->nome  = $this->data['nome']  ?? null;
        $usuario->email = $this->data['email'] ?? null;

        if ($usuario->create()) {
            Http::response('Usuário criado', ['id' => $usuario->id], 201);
        }

        Http::response('Erro ao criar usuário', [], 500);
    }

    public function update(int $id)
    {
        $usuario = Usuario::findById($id);

        if (!$usuario) {
            Http::response('Usuário não encontrado', [], 404);
        }

        $usuario->assign($this->data);
        $usuario->save();

        Http::response('Usuário atualizado', $usuario);
    }

    public function destroy(int $id)
    {
        $usuario = Usuario::findById($id);

        if (!$usuario) {
            Http::response('Usuário não encontrado', [], 404);
        }

        $usuario->delete();
        Http::response('Usuário removido', [], 204);
    }
}
```

### Propriedades disponíveis em `ControllerBase`

| Propriedade | Tipo | Conteúdo |
|------------|------|----------|
| `$this->data` | `array` | Body da requisição (JSON, form-data ou query string) |
| `$this->token` | `string\|null` | Bearer token do header `Authorization` |
| `$this->method` | `string` | Método HTTP (`GET`, `POST`, etc.) |

### Verificar método HTTP

```php
$this->isGet()     // true se GET
$this->isPost()    // true se POST
$this->isPut()     // true se PUT
$this->isDelete()  // true se DELETE
```

### Transações

```php
public function transferir()
{
    $this->initTransaction();

    try {
        // operações...
        $this->commitTransaction();
        Http::response('Transferência realizada');
    } catch (\Exception $e) {
        $this->rollBackTransaction();
        Http::response('Erro', [], 500);
    }
}
```

### `Http::response()`

```php
Http::response(string $message, array $data = [], int $status = 200, array $headers = []);

// Exemplos:
Http::response('OK', $lista);                          // 200 com dados
Http::response('Criado', ['id' => 1], 201);            // 201
Http::response('Não encontrado', [], 404);             // 404
Http::response('OK', $dados, 200, ['X-Version' => '2']); // com header extra
```

---

## 5. Criando Rotas

Rotas são definidas com o attribute `#[Router]` diretamente nos métodos do controller.

### Sintaxe

```php
#[Router(
    method: 'GET',
    path: '/caminho/{param:tipo}',
    version: 1,
    middlewares: ['authentication'],
    docs: ['title' => '...', 'description' => '...']
)]
public function meuMetodo($param) { ... }
```

### Exemplo com todos os verbos

```php
use Psf\Http\Router;

class UsuarioController extends ControllerBase
{
    #[Router(method: 'GET', path: '/usuarios', version: 1)]
    public function index() { ... }

    #[Router(method: 'GET', path: '/usuarios/{id:int}', version: 1)]
    public function show(int $id) { ... }

    #[Router(method: 'POST', path: '/usuarios', version: 1, middlewares: ['authentication'])]
    public function store() { ... }

    #[Router(method: 'PUT', path: '/usuarios/{id:int}', version: 1, middlewares: ['authentication'])]
    public function update(int $id) { ... }

    #[Router(method: 'DELETE', path: '/usuarios/{id:int}', version: 1, middlewares: ['authentication'])]
    public function destroy(int $id) { ... }
}
```

A URL gerada fica: `/v1/usuarios`, `/v1/usuarios/42`, etc.

### Tipos de parâmetro

| Padrão | Valida | Exemplo |
|--------|--------|---------|
| `{id:int}` | Apenas números inteiros | `/usuarios/42` |
| `{nome:string}` | Alfanumérico e underscore | `/produtos/camiseta_azul` |
| `{slug:slug}` | Letras minúsculas, números e hífen | `/blog/meu-post` |
| `{uuid:uuid4}` | UUID v4 | `/documentos/550e8400-...` |
| `{cod:/^[A-Z]{3}-\d{4}$/}` | Regex customizado | `/cod/ABC-1234` |

Sem tipo (`{id}`), o parâmetro aceita qualquer valor.

### Middlewares disponíveis

| Nome | O que faz |
|------|-----------|
| `authentication` | Executa `verifyauth` definido no config; bloqueia com 401 se falhar |
| `loggin` | Executa `logrequest` no config para registrar a requisição |

### Acesso ao usuário autenticado

Após o middleware `authentication` passar, `Router::$auth` contém o retorno do seu método de verificação:

```php
$usuarioLogado = Router::$auth; // objeto ou array retornado pelo seu verifyauth
```

---

## 6. CRUD — operações no banco

### Criar registro

```php
$usuario = new Usuario();
$usuario->nome  = 'Maria';
$usuario->email = 'maria@exemplo.com';

$ok = $usuario->create();
// Após create(), $usuario->id é preenchido com o ID gerado
```

### Atualizar registro

```php
$usuario = Usuario::findById(5);
$usuario->nome = 'Maria Silva';
$usuario->save();
```

### Deletar registro

```php
$usuario = Usuario::findById(5);
$usuario->delete();
// Se o model tem #[ColumnDeletedDate], faz soft delete (preenche deleted_at)
// Caso contrário, executa DELETE real
```

### Atribuição em massa

```php
// Atribui apenas propriedades que existem no model (seguro):
$usuario->assign($this->data);

// Atribui qualquer chave, mesmo que não exista no model (force):
$usuario->assign($this->data, force: true);
```

### Converter para array

```php
$arr = $usuario->toArray();
// ['id' => 1, 'nome' => 'Maria', 'email' => 'maria@exemplo.com', ...]
```

---

## 7. Buscas com o Query Builder

O `ModelTrait` disponibiliza `find()` e `findById()` em qualquer model.

### Busca simples

```php
// Todos os registros
$lista = Usuario::find()->all();

// Um registro por ID
$usuario = Usuario::findById(42);

// Um registro com filtro
$usuario = Usuario::find()
    ->andWhere([Usuario::class . '.email' => 'maria@exemplo.com'])
    ->one();
```

### Filtros

```php
// Igualdade
->andWhere([Usuario::class . '.status' => 'ativo'])

// Operadores: =, <>, >, <, >=, <=, LIKE, NOT LIKE, IN, NOT IN, IS NULL, IS NOT NULL
->andWhere([Usuario::class . '.idade', '>', 18])
->andWhere([Usuario::class . '.email', 'LIKE', '%@empresa.com'])
->andWhere([Usuario::class . '.id', 'IN', [1, 2, 3]])
->andWhere([Usuario::class . '.deleted_at', 'IS NULL'])

// OR
->orWhere([Usuario::class . '.tipo' => 'admin'])
```

### Paginação

```php
$resultado = Usuario::find()
    ->orderBy(Usuario::class . '.nome', 'ASC')
    ->paginator(page: 1, itensPerPage: 20);

// Retorna:
// [
//   'itens' => [...],
//   'paginator' => [
//     'itens' => ['total' => 100, 'perPage' => 20, 'inThisPage' => 20],
//     'pages' => ['atual' => 1, 'estimated' => 5, 'hasBefore' => false, 'hasAfter' => true]
//   ]
// ]
```

### Joins

```php
// LEFT JOIN simples (sem hidratar relação)
$usuarios = Usuario::find()
    ->leftJoin([Empresa::class, 'e'], 'e.id = usuarios.empresa_id')
    ->fields([
        Usuario::class . '.id',
        Usuario::class . '.nome',
        'e.nome AS empresa_nome',
    ])
    ->all();

// LEFT JOIN com relação hidratada (1:N)
$usuarios = Usuario::find()
    ->leftJoinAndSelect([Pedido::class, 'p'], 'pedidos', 'p.usuario_id = usuarios.id')
    ->all();

// Cada usuário terá: $usuario->pedidos = [Pedido, Pedido, ...]
```

### Agregações

```php
$total     = Usuario::find()->count();
$temAtivo  = Usuario::find()->andWhere([Usuario::class . '.status' => 'ativo'])->exist();
$somaVenda = Pedido::find()->sum(Pedido::class . '.valor');
```

Para mais exemplos, veja `docs/query-builder.md`.

---

## 8. Migrations

O PSF usa o Phinx como ferramenta de migrations. O executável está em `vendor/bin/phinx` (ou `bin/phinx` se o projeto incluir o wrapper próprio).

### Pré-requisito

O Phinx lê a configuração do framework para saber qual banco usar. Certifique-se de que `config/config.php` tem a chave `database` na conexão (não `base`):

```php
'default' => [
    'driver'   => DBDriver::MySQL,
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'meu_banco',   // ← obrigatório para Phinx
    'port'     => 3306,
],
```

### Comandos do dia a dia

```bash
# Criar uma nova migration em branco
./vendor/bin/phinx create NomeDaMigration

# Gerar migration a partir de um model PSF
./vendor/bin/phinx generate-model -- --model=App\\Models\\Usuario

# Rodar todas as migrations pendentes
./vendor/bin/phinx migrate

# Rodar migrations de uma conexão específica
./vendor/bin/phinx migrate -e legado

# Desfazer a última migration
./vendor/bin/phinx rollback

# Ver status das migrations
./vendor/bin/phinx status

# Comparar model com banco (colunas faltando ou a mais)
./vendor/bin/phinx check-model -- --model=App\\Models\\Usuario
```

### Estrutura de uma migration gerada

O comando `generate-model` gera a migration automaticamente a partir do model:

```php
<?php

use Phinx\Migration\AbstractMigration;
use Psf\Database\Command\ModelAwareMigration;

class CreateUsuariosTable extends AbstractMigration
{
    use ModelAwareMigration;

    public function change(): void
    {
        // SQL gerado automaticamente a partir dos #[Column] e #[Type] do model
        $this->execute($this->generateCreateTableSql(\App\Models\Usuario::class));
    }
}
```

### Escrevendo uma migration manual

```php
<?php

use Phinx\Migration\AbstractMigration;

class AddTelefoneToUsuarios extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE usuarios ADD COLUMN telefone VARCHAR(20) NULL AFTER email");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE usuarios DROP COLUMN telefone");
    }
}
```

---

## 9. Testes

O PSF usa **Pest v3** (sintaxe moderna, engine PHPUnit por baixo).

### Rodar os testes

```bash
# Todos os testes
./vendor/bin/pest

# Apenas testes unitários
./vendor/bin/pest --testsuite=Unit

# Apenas um arquivo
./vendor/bin/pest tests/Unit/Model/MetadataCacheTest.php

# Com cobertura de código
./vendor/bin/pest --coverage

# Modo verboso (ver nome de cada teste)
./vendor/bin/pest --verbose
```

### Estrutura de testes

```
tests/
  bootstrap.php          ← carregado automaticamente (inicializa PSF)
  Fixtures/
    config.php           ← config de teste (sem banco real)
    Models/
      FakeUserModel.php  ← model para usar nos testes
  Unit/
    Database/            ← testes de Create, Read, Update, Delete
    Http/                ← testes de Router, Http, Request
    Model/               ← testes de MetadataCache, ControllerBase
    Utils/               ← testes de JWT
    Migrations/          ← testes de ModelGenerator, TableAnalyzer
  Integration/           ← testes que precisam de banco real
```

### Criando um novo teste unitário

Crie um arquivo em `tests/Unit/` seguindo o padrão Pest:

```php
<?php
// tests/Unit/Model/UsuarioTest.php

use Tests\Fixtures\Models\FakeUserModel;
use Psf\Model\MetadataCache;

// Limpa o cache de metadados antes de cada teste
beforeEach(fn () => MetadataCache::clearCache());

it('maps properties to correct column names', function () {
    $map = MetadataCache::getColumnMap(FakeUserModel::class);

    expect($map['createdAt'])->toBe('created_at');
    expect($map['email'])->toBe('email');
});

it('returns the table name from the #[Table] attribute', function () {
    $table = MetadataCache::getTable(FakeUserModel::class);

    expect($table)->toBe('fake_users');
});
```

### Criando um fixture de model para testes

```php
<?php
// tests/Fixtures/Models/FakeProdutoModel.php

namespace Tests\Fixtures\Models;

use Psf\Model\Model;
use Psf\Model\ModelTrait;
use Psf\Model\Attributes\{Table, Column, PrimaryKey, Type, Nullable};

#[Table('fake_produtos')]
class FakeProdutoModel extends Model
{
    use ModelTrait;

    #[PrimaryKey]
    #[Column('id')]
    #[Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('nome')]
    #[Nullable(false)]
    #[Type('VARCHAR(255) NOT NULL')]
    public ?string $nome = null;

    #[Column('preco')]
    #[Type('DECIMAL(10,2)')]
    public ?float $preco = null;
}
```

### Testando um controller com mock de request

```php
<?php
// tests/Unit/Http/UsuarioControllerTest.php

use App\Controllers\UsuarioController;
use Psf\Http\Http;

it('store() returns 201 when data is valid', function () {
    // Simula o body da requisição
    $_POST = ['nome' => 'João', 'email' => 'joao@teste.com'];

    $controller = new UsuarioController();

    // Http::response() chama exit() — capture com output buffering
    ob_start();
    try {
        $controller->store();
    } catch (\Throwable) {}
    $output = ob_get_clean();

    $decoded = json_decode($output, true);
    expect($decoded['message'])->toBe('Usuário criado');
});
```

### Testando JWT

```php
use Psf\Utils\JWT;

it('encodes and decodes a token', function () {
    $payload = ['user_id' => 1, 'role' => 'admin'];

    $token   = JWT::encode($payload);
    $decoded = JWT::decode($token, true);

    expect($decoded)->toMatchArray($payload);
});

it('rejects an expired token', function () {
    $token = JWT::encode(['user_id' => 1, 'exp' => time() - 3600]);
    expect(JWT::decode($token, true))->toBeFalse();
});
```

### Helpers do Pest úteis

```php
expect($valor)->toBe('esperado');          // igualdade estrita
expect($valor)->toEqual(['a' => 1]);       // igualdade não-estrita
expect($valor)->toMatchArray(['chave' => 'valor']); // contém as chaves
expect($valor)->toBeNull();
expect($valor)->toBeFalse();
expect($valor)->not->toBeEmpty();
expect($valor)->toBeInstanceOf(Usuario::class);
expect($valor)->toHaveCount(3);
```

---

## Resumo rápido

| Tarefa | Comando / Classe |
|--------|-----------------|
| Criar model | Estender `Model` + `use ModelTrait` + Attributes |
| Criar controller | Estender `ControllerBase` |
| Criar rota | Attribute `#[Router(...)]` no método |
| Responder JSON | `Http::response($msg, $data, $status)` |
| Buscar todos | `Modelo::find()->all()` |
| Buscar por ID | `Modelo::findById($id)` |
| Inserir | `$obj->create()` |
| Atualizar | `$obj->save()` |
| Deletar | `$obj->delete()` |
| Criar migration | `./vendor/bin/phinx create Nome` |
| Gerar do model | `./vendor/bin/phinx generate-model -- --model=App\\Models\\X` |
| Rodar migrations | `./vendor/bin/phinx migrate` |
| Rodar testes | `./vendor/bin/pest` |
