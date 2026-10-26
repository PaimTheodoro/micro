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
10. [Observabilidade (monitor)](#10-observabilidade-monitor)

---

## 1. Instalação

```bash
composer require psf/framework
composer require robmorgan/phinx   # se for usar migrations ou o monitor
```

### Estrutura mínima de um projeto

```
meu-projeto/
  config.php         ← configurações do framework (o vendor/bin/psf procura este arquivo na raiz)
  app/
    Controllers/     ← varrido em busca de #[Router] (settings.controllers)
    Models/
  db/
    migrations/      ← migrations do Phinx (crie a pasta; o Phinx não a cria)
    seeds/
  public/
    index.php        ← entry point
    .htaccess
  composer.json      ← "autoload": {"psr-4": {"App\\": "app/"}}
```

O `vendor/bin/psf` procura, na raiz do projeto e nesta ordem, `bootstrap/app.php`, `config/bootstrap.php`, `bootstrap.php` ou `config.php`, e usa o primeiro que encontrar como arquivo de configuração. Esse arquivo precisa **retornar o array de config** (ele é passado para `PSF::init()`). Por isso a config fica em `config.php` na raiz: um `config/config.php` não é encontrado pelo Phinx.

### `public/index.php`

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Psf\Http\{Http, Router};

PSF::init(['config' => __DIR__ . '/../config.php']);

try {
    // As rotas vêm dos diretórios em settings.controllers (não de uma lista de classes)
    (new Router())->handle();
} catch (\Throwable $e) {
    // O Router sinaliza 404 (rota não encontrada), 4xx do controller e 500 lançando
    // exceções com o status HTTP no código. Sem este catch, elas chegam à página do Ignition.
    $code   = $e->getCode();
    $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
    Http::response($status < 500 ? $e->getMessage() : 'Erro interno', [], $status);
}
```

O construtor do `Router` ainda aceita uma lista de classes de controller (formato antigo), mas não a utiliza: a descoberta de rotas depende **só** de `settings.controllers`.

### `public/.htaccess`

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?_url=$1 [QSA,L]
```

---

## 2. Configuração

Crie `config.php` na raiz do projeto retornando um array com as seções abaixo.

### Configuração completa comentada

```php
<?php

use Psf\Enumerators\DBDriver;

return [

    // 'prd' mantém o cache de rotas (APCu) com chave estática: limpe no deploy
    'environment' => 'dev',

    // ─── Banco de dados ──────────────────────────────────────────────────────
    'db' => [

        // Conexão padrão (obrigatória)
        'default' => [
            'driver'   => DBDriver::MySQL,      // MySQL, PostgreSQL, SQLServer ou SQLite
            'hostname' => 'localhost',          // 'host', 'user' e 'pass' também são aceitos
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
        'secret' => 'troque-por-uma-chave-secreta-longa', // mínimo de 16 caracteres
        // 'require_exp' => false, // padrão true: token sem `exp` é recusado em verify()/decode(); false só p/ tokens legados
    ],

    // ─── Configurações gerais ────────────────────────────────────────────────
    'settings' => [
        // Diretórios varridos em busca de #[Router] (OBRIGATÓRIO: sem isto nenhuma rota é encontrada)
        'controllers' => [__DIR__ . '/app/Controllers'],

        // true expõe mensagem/arquivo/linha dos erros de banco na resposta
        'debug' => false,

        // Classe e método que validam a requisição (middleware 'authentication')
        'verifyauth' => [App\Auth::class, 'verify'],

        // Classe e método para logar requisições (middleware 'loggin')
        'logrequest' => [App\RequestLog::class, 'save'],

        // URL que serve a documentação automática da API (opcional)
        'docsapi' => 'api/docs',
    ],

];
```

O método de `verifyauth` não recebe argumentos: retornar `true` ou um objeto libera a rota (o objeto fica em `Router::$auth`); qualquer outro retorno responde 401. O de `logrequest` é chamado com argumentos nomeados `endpoint`, `version`, `method`, `body` (o body já interpretado, sem mascaramento), `headers` (com `Authorization`, `Proxy-Authorization` e `Cookie` trocados por `[redacted]`), `response` e `httpCode`. As duas classes são instanciadas sem argumentos.

### Drivers disponíveis

| Constante | Banco |
|-----------|-------|
| `DBDriver::MySQL` | MySQL / MariaDB (padrão quando `driver` não é informado) |
| `DBDriver::PostgreSQL` | PostgreSQL |
| `DBDriver::SQLServer` | SQL Server |
| `DBDriver::SQLite` | SQLite (`database` é o caminho do arquivo ou `:memory:`) |

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
    #[Standard('ativo')]          // valor usado quando a propriedade está vazia ao gravar
    public ?string $status = null;

    #[Column('created_at')]
    #[ColumnCreatedDate]          // preenchido com a data atual quando está vazio ao gravar
    #[Type('DATETIME')]
    public ?string $createdAt = null;

    #[Column('updated_at')]
    #[ColumnUpdatedDate]          // idem: só é preenchido quando está vazio
    #[Type('DATETIME')]
    public ?string $updatedAt = null;

    #[Column('deleted_at')]
    #[ColumnDeletedDate]          // soft delete: delete() preenche a data e faz save() em vez de DELETE
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
| `#[PrimaryKey]` | Marca como chave primária (fica fora do INSERT e do SET do UPDATE) |
| `#[Type('DEF')]` | Tipo SQL da coluna (usado pelos geradores de migration) |
| `#[Nullable(false)]` | Obrigatório: `create()`/`save()` lançam exceção se a propriedade estiver vazia |
| `#[Standard('valor')]` | Valor usado quando a propriedade está vazia ao gravar (aceita um case de enum) |
| `#[Standard('UUIDV4')]` / `#[Standard('NOW()')]` | Gera um UUID v4 / a data atual quando a propriedade está vazia |
| `#[ColumnCreatedDate]` | Preenche com a data atual (`Y-m-d H:i:s`, ou `time()` se o `Type` for `timestamp`/`bigint`) quando a propriedade está vazia |
| `#[ColumnUpdatedDate]` | Mesma regra. Num model carregado do banco a propriedade já vem preenchida: para atualizar a data num `save()`, esvazie-a antes (`$obj->updatedAt = null`) |
| `#[ColumnDeletedDate]` | Soft delete: `delete()` preenche a coluna e grava em vez de apagar; as consultas filtram `IS NULL` sozinhas |
| `#[Enum(EnumClass::class)]` | Metadado: associa a propriedade a um enum PHP (consultado por `Model::propIsEnum()`); a hidratação **não** converte o valor para o enum |

"Vazia" segue o `empty()` do PHP: `0`, `'0'` e `''` também contam como vazios.

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

Controllers ficam em `app/Controllers/` (o diretório de `settings.controllers`). Estendem `ControllerBase` para ter acesso ao body da requisição, ao token Bearer e aos métodos de transação. As rotas são declaradas com `#[Router]` nos métodos (ver [seção 5](#5-criando-rotas)); aqui elas foram omitidas.

`Http::response()` envia o JSON e **encerra a execução com `exit`**: nada depois dele roda. Por isso os exemplos abaixo não precisam de `return` nem de `else` depois de uma resposta.

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
        $usuarios = Usuario::find()->all(); // lista de objetos Usuario
        Http::response('OK', $usuarios);    // serializados pelas propriedades públicas
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
        Http::response('Usuário removido');
    }
}
```

### Propriedades disponíveis em `ControllerBase`

| Propriedade | Tipo | Conteúdo |
|------------|------|----------|
| `$this->data` | `array` | Body da requisição (JSON, form-data ou form-urlencoded) mesclado com a query string; o body tem precedência |
| `$this->token` | `string\|null` | Bearer token do header `Authorization` (`null` sem o esquema `Bearer`) |
| `$this->method` | `string\|null` | Método HTTP (`GET`, `POST`, etc.) |

As três são preenchidas no construtor do `ControllerBase`: se o seu controller tiver construtor, chame `parent::__construct()`.

### Verificar método HTTP

```php
$this->isGet()     // true se GET
$this->isPost()    // true se POST
$this->isPut()     // true se PUT
$this->isDelete()  // true se DELETE
```

### Transações

Os métodos recebem a conexão (chave de `config['db']`, padrão `'default'`).

```php
public function transferir()
{
    $this->initTransaction();

    try {
        // operações...
        $this->commitTransaction();
    } catch (\Throwable $e) {
        $this->rollBackTransaction();
        throw $e; // vira 500 no Router (ou trate e responda aqui)
    }

    // Fora do try: Http::response() encerra a execução (e, nos testes, lança HttpResponseException)
    Http::response('Transferência realizada');
}
```

`runServiceTransaction()` faz o mesmo para uma ação que devolve `[mensagem, dados, status]`: dá rollback se a ação lançar (e relança) ou se o status for >= 400, e commit nos demais casos:

```php
[$mensagem, $dados, $status] = $this->runServiceTransaction(fn () => $this->service->transferir($this->data));
Http::response($mensagem, $dados, $status);
```

### `Http::response()`

```php
Http::response($message = '', $data = [], $status = 200, $headers = []); // envia o JSON e chama exit

// Exemplos:
Http::response('OK', $lista);                          // 200 com dados
Http::response('Criado', ['id' => 1], 201);            // 201
Http::response('Não encontrado', [], 404);             // 404
Http::response('OK', $dados, 200, ['X-Version' => '2']); // com header extra
```

O corpo é `{"message": ..., "data": ...}` (cada chave só aparece quando não está vazia). `$status` aceita um inteiro ou um `HttpStatusCode`; fora de 100–599 vira 500. Com a constante `PSF_TESTING` definida, em vez de responder e chamar `exit`, ele lança `Psf\Http\HttpResponseException` (`getStatus()`, `getPayload()`).

---

## 5. Criando Rotas

Rotas são definidas com o attribute `#[Router]` diretamente nos métodos do controller. O `RouteCacheManager` só procura esses attributes nos diretórios de `settings.controllers` (ver [seção 2](#2-configuração)); com APCu, a tabela de rotas fica em cache.

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

`method`, `path` e `version` são obrigatórios na prática; `middlewares` e `docs` são opcionais. Os campos de `docs.fields` marcados com `'required' => true` são validados antes de o método rodar.

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

A URL gerada fica: `/v1/usuarios`, `/v1/usuarios/42`, etc. Sem o prefixo de versão (`/usuarios/42`), a rota casa com qualquer versão. Os parâmetros são passados ao método **pelo nome** (argumentos nomeados): `{id:int}` exige um parâmetro `$id` no método.

Uma exceção lançada pelo controller com código 4xx (`throw new \Exception('Sem permissão', 403)`) mantém status e mensagem; outras viram 500 "Erro ao executar a rota." (com a original em `getPrevious()`). Rota inexistente lança exceção com código 404. Todas elas chegam ao `try/catch` do `public/index.php` ([seção 1](#publicindexphp)).

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
| `authentication` | Executa `verifyauth` definido no config; responde 401 se ele não retornar `true` ou um objeto (ou se `verifyauth` não estiver configurado) |
| `loggin` | Depois do método, chama `logrequest` com endpoint, versão, método, body, headers (credenciais mascaradas) e resposta |

### Acesso ao usuário autenticado

Após o middleware `authentication` passar, `Router::$auth` contém o retorno do seu método de verificação:

```php
$usuarioLogado = Router::$auth; // o objeto retornado pelo verifyauth (ou true)
```

---

## 6. CRUD — operações no banco

### Criar registro

```php
$usuario = new Usuario();
$usuario->nome  = 'Maria';
$usuario->email = 'maria@exemplo.com';

$ok = $usuario->create();
// Após create(), $usuario->id recebe o ID gerado (lastInsertId), se o model tiver a propriedade `id`
```

### Atualizar registro

```php
$usuario = Usuario::findById(5);
$usuario->nome = 'Maria Silva';
$usuario->save(); // UPDATE de todas as colunas mapeadas, filtrado pela chave primária
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

`toArray()` lê as colunas da tabela no banco (uma vez por requisição) e devolve, com o nome da coluna como chave, só as colunas que têm uma propriedade **com o mesmo nome**: no model acima, `created_at` (propriedade `createdAt`) fica de fora. Para a resposta completa, passe o próprio objeto ao `Http::response()` (serializado pelas propriedades públicas) ou monte o array.

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

// Operadores: =, <>, !=, >, <, >=, <=, LIKE, NOT LIKE, IN, NOT IN, BETWEEN, NOT BETWEEN, IS NULL, IS NOT NULL
->andWhere([Usuario::class . '.id', '>', 100])
->andWhere([Usuario::class . '.email', 'LIKE', '%@empresa.com'])
->andWhere([Usuario::class . '.id', 'IN', [1, 2, 3]])
->andWhere([Usuario::class . '.createdAt', 'BETWEEN', ['2026-01-01', '2026-01-31']])
->andWhere([Usuario::class . '.updatedAt', 'IS NULL'])

// OR: o resultado é (andWheres) OR (orWheres)
->orWhere([Usuario::class . '.status' => 'admin'])
```

As referências usam o **nome da propriedade** (`createdAt`), não o da coluna; `Usuario::col('createdAt')` faz o mesmo e valida a propriedade na hora. Não é preciso filtrar `deletedAt`: com `#[ColumnDeletedDate]` a consulta já aplica `deleted_at IS NULL`.

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

O PSF usa o Phinx como ferramenta de migrations (`composer require robmorgan/phinx`). O comando é o wrapper do framework, `vendor/bin/psf` (dentro do repositório do framework, `./bin/psf`): ele encontra o autoloader e o arquivo de config do projeto ([seção 1](#estrutura-mínima-de-um-projeto)), chama `PSF::init()`, transforma cada entrada de `config['db']` num ambiente do Phinx e acrescenta os comandos do PSF. Não use o Phinx puro (`vendor/robmorgan/phinx/bin/phinx`).

`vendor/bin/psf` é o nome estável. O `robmorgan/phinx` também declara um binário `phinx` e o Composer mantém o primeiro que instalar: `vendor/bin/phinx` só é o wrapper do PSF quando o Composer ligou o do PSF (continua com esse nome por compatibilidade); num projeto que já tinha o Phinx, ele segue sendo o Phinx puro, sem os comandos `make:*` e `monitor:*`. Por isso prefira sempre `vendor/bin/psf`.

### Pré-requisitos

- Cada conexão de `config['db']` vira um ambiente do Phinx com o mesmo nome (`-e legado`); o ambiente padrão é a primeira. Conexões sem `driver` ou sem `database` são ignoradas:

```php
'default' => [
    'driver'   => DBDriver::MySQL,
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'meu_banco',   // ← obrigatório para o Phinx
    'port'     => 3306,
],
```

- As migrations ficam em `db/migrations` e os seeds em `db/seeds`, na raiz do projeto. Crie as pastas: o Phinx não as cria.
- Sem um `phinx.php` (ou `.json`/`.yml`) próprio no diretório atual, o wrapper usa a config do framework; `-c arquivo` escolhe outra.

### Comandos do dia a dia

```bash
# Criar uma nova migration em branco
vendor/bin/psf create NomeDaMigration

# Gerar uma migration comparando o model com o banco (CREATE TABLE se a tabela não existe, ALTER se existe)
vendor/bin/psf make:migration-from-model 'App\Models\Usuario'

# Gerar um model PSF (com attributes) a partir de uma tabela existente
vendor/bin/psf make:model-from-table usuarios 'App\Models\Usuario'

# Comparar a tabela com o model (colunas faltando, a mais e tipos diferentes); -o grava o relatório
vendor/bin/psf analyze:table-vs-model usuarios 'App\Models\Usuario' -o relatorio.md

# Rodar todas as migrations pendentes
vendor/bin/psf migrate

# Rodar migrations de uma conexão específica (chave de config['db'])
vendor/bin/psf migrate -e legado

# Desfazer a última migration
vendor/bin/psf rollback

# Ver status das migrations
vendor/bin/psf status

# Seeds
vendor/bin/psf seed:create NomeDoSeed
vendor/bin/psf seed:run
```

Os três comandos do PSF (`make:migration-from-model`, `make:model-from-table` e `analyze:table-vs-model`) usam sempre o ambiente padrão (a primeira conexão de `config['db']`). O `make:model-from-table` grava o model em `app/models/` seguido do namespace sem o `App\` (para `App\Models\Usuario`, `app/models/Models/Usuario.php`): mova o arquivo para onde o autoload do projeto espera. Os comandos `monitor:*` estão na [seção 10](#10-observabilidade-monitor).

### Estrutura de uma migration gerada

O `make:migration-from-model` lê os `#[Column]`, `#[Type]` e `#[PrimaryKey]` do model e grava em `db/migrations` uma migration comum do Phinx com o SQL do dialeto do banco (`ModelAwareMigration` é a classe desse comando, não uma trait para usar nas migrations):

```php
<?php
use Phinx\Migration\AbstractMigration;

class CreateUsuarioTable extends AbstractMigration{
    public function up(){
        $this->execute("CREATE TABLE `usuarios` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(){
        $this->execute("DROP TABLE `usuarios`;");
    }
}
```

Coluna sem `#[Type]` vira `VARCHAR(255) NOT NULL`. Quando a tabela já existe, a migration gerada (`Update<Model>Table`) traz os `ALTER TABLE` das diferenças. Revise o arquivo antes de rodar o `migrate`.

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

O PSF usa **Pest v3** (sintaxe moderna, engine PHPUnit por baixo). Os comandos e a estrutura abaixo são os do repositório do framework; num projeto consumidor, o mesmo padrão vale com um `tests/bootstrap.php` próprio que chame `PSF::init()` com uma config de teste e defina `PSF_TESTING`.

### Rodar os testes

```bash
# Todos os testes
./vendor/bin/pest

# Apenas testes unitários (PDO simulado)
./vendor/bin/pest --testsuite=Unit

# Apenas testes de integração (SQLite :memory:, sem servidor de banco)
./vendor/bin/pest --testsuite=Integration

# Apenas um arquivo
./vendor/bin/pest tests/Unit/Model/MetadataCacheTest.php

# Com cobertura de código
./vendor/bin/pest --coverage

# Nome de cada teste
./vendor/bin/pest --testdox

# Análise estática (PHPStan nível 1)
composer analyse
```

### Estrutura de testes

```
tests/
  bootstrap.php          ← carregado automaticamente (PSF::init com a config de teste e PSF_TESTING)
  Pest.php               ← schema SQLite dos testes de integração
  Fixtures/
    config.php           ← config de teste (MySQL fictício; os testes unitários não conectam)
    config.sqlite.php    ← config dos testes de integração (SQLite :memory:)
    Controllers/ Http/ Models/ Monitor/   ← classes de apoio (FakeUserModel, FakeController, ...)
  Unit/
    Database/            ← dialetos, ambientes do Phinx, ModelGenerator, TableAnalyzer
    Http/                ← Router, RouteCacheManager, RequestParser, Http, Cors
    Model/               ← MetadataCache, QueryBuilder, ReferenceResolver, ControllerBase
    Monitor/             ← monitor de observabilidade
    Utils/               ← JWT
  Integration/           ← CRUD e transações em SQLite :memory: (e o monitor)
  Drivers/               ← monitor em MySQL/PostgreSQL reais (só com PSF_DRIVERS=1)
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

Com `PSF_TESTING` definida, `Http::response()` lança `HttpResponseException` em vez de imprimir e chamar `exit`: capture a exceção e confira status e payload. O `ControllerBase` lê o body no construtor, então prepare `$_POST`/`$_SERVER` antes de instanciar. O `store()` do exemplo grava no banco: rode-o contra uma config de teste (por exemplo SQLite `:memory:`).

```php
<?php
// tests/Integration/UsuarioControllerTest.php

use App\Controllers\UsuarioController;
use Psf\Http\HttpResponseException;

it('store() returns 201 when data is valid', function () {
    // Simula um POST com form-urlencoded (sem Content-Type, o body é lido como JSON de php://input)
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
    $_POST = ['nome' => 'João', 'email' => 'joao@teste.com'];

    $controller = new UsuarioController();

    try {
        $controller->store();
        $this->fail('esperava HttpResponseException');
    } catch (HttpResponseException $e) {
        expect($e->getStatus())->toBe(201);
        expect($e->getPayload()['message'])->toBe('Usuário criado');
    }
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

## 10. Observabilidade (monitor)

O monitor grava métricas de requests, queries SQL e exceções no banco do próprio projeto e as agrega por hora. É opt-in: desligado, cada gancho é só uma checagem de booleano. Referência completa: [`docs/observabilidade.md`](observabilidade.md).

### Ligar

```php
'settings' => [
    'monitor' => [
        'enabled'  => true,
        'database' => 'default',         // chave de config['db'] onde ficam as tabelas
        'sample'   => ['normal' => 0.1], // erros, exceções e lentos são sempre gravados
    ],
],
```

Com o monitor ligado, toda resposta traz o header `X-Request-Id` (o de entrada, se válido, ou um gerado; exposto ao navegador quando `settings.cors` está configurado). Use `Monitor::requestId()` para colocá-lo nos logs da aplicação.

### Instalar e agendar

```bash
./vendor/bin/psf monitor:install   # cria as tabelas psf_monitor_* (idempotente; rode a cada atualização)
./vendor/bin/psf monitor:status    # banco, tabelas instaladas, monitor ligado, "Rollup até"
# cron, de hora em hora (rollup + limpeza):
# 17 * * * * cd /caminho/do/projeto && php vendor/bin/psf monitor:maintain
```

### Ler

```php
use Psf\Monitor\Reader;

$reader = new Reader();
$to     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
$from   = $to->modify('-24 hours');
$rotas   = $reader->routes($from, $to, 10);           // hits, error_rate, avg_ms, p50/p95/p99...
$detalhe = $reader->request('valor-do-X-Request-Id'); // linhas brutas de um request
```

O framework não expõe esses dados sozinho: crie um endpoint protegido pela autenticação do projeto.

---

## Resumo rápido

| Tarefa | Comando / Classe |
|--------|-----------------|
| Criar model | Estender `Model` + `use ModelTrait` + Attributes |
| Criar controller | Estender `ControllerBase` |
| Criar rota | Attribute `#[Router(...)]` no método, num diretório de `settings.controllers` |
| Responder JSON | `Http::response($msg, $data, $status)` (encerra com `exit`) |
| Buscar todos | `Modelo::find()->all()` |
| Buscar por ID | `Modelo::findById($id)` |
| Inserir | `$obj->create()` |
| Atualizar | `$obj->save()` |
| Deletar | `$obj->delete()` |
| Criar migration | `vendor/bin/psf create Nome` |
| Gerar do model | `vendor/bin/psf make:migration-from-model 'App\Models\X'` |
| Gerar model da tabela | `vendor/bin/psf make:model-from-table tabela 'App\Models\X'` |
| Comparar tabela e model | `vendor/bin/psf analyze:table-vs-model tabela 'App\Models\X'` |
| Rodar migrations | `vendor/bin/psf migrate` |
| Rodar testes | `./vendor/bin/pest` |
| Instalar o monitor | `./vendor/bin/psf monitor:install` |
| Manutenção do monitor (cron) | `./vendor/bin/psf monitor:maintain` |
