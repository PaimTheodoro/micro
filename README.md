# PSF Micro-Framework PHP (Beta)

O **PSF** (`psf/framework`) é um micro-framework PHP para construir APIs JSON. Rotas e models são declarados com *attributes* do PHP 8, as consultas passam por um único *query builder* que gera SQL para MySQL, PostgreSQL, SQL Server e SQLite, e as migrations rodam sobre o Phinx, com comandos que geram migrations a partir dos models e models a partir das tabelas. O framework também traz um monitor de observabilidade que grava métricas de requests, queries e exceções no banco do próprio projeto.

O framework está em fase **Beta**. Ele é uma biblioteca consumida por outros projetos, por isso as APIs públicas são mantidas retrocompatíveis (ver [Versionamento](#versionamento-e-compatibilidade)).

## O que o diferencia

- **Rotas por attribute**: `#[Router(method: 'GET', path: '/usuarios/{id:int}', version: 1)]` direto no método do controller, com parâmetros tipados, regex inline e prefixo de versão (`/v1/...`). Os controllers são descobertos por varredura de diretório.
- **Models por attribute**: `#[Table]`, `#[Column]`, `#[PrimaryKey]`, `#[Type]`, datas automáticas de criação/atualização e soft delete com `#[ColumnDeletedDate]`.
- **Um só pipeline de consulta**: `Model::find()` → `ModelQuery` → `QueryBuilder` → SQL com *prepared statements*, com o dialeto certo para cada banco (quoting, `LIMIT`/`OFFSET`, listagem de tabelas).
- **Migrations e geradores**: wrapper do Phinx que lê a configuração do PSF e acrescenta `make:migration-from-model`, `make:model-from-table` e `analyze:table-vs-model`.
- **Autenticação, JWT e CORS**: middleware `authentication` ligado a um verificador do projeto, `Psf\Utils\JWT` (HS256) e CORS com resposta de preflight.
- **Caches em APCu**: tabela de rotas e metadados dos models (reflection) ficam em cache entre requests quando a extensão está disponível.
- **Documentação de API gerada das rotas**: os metadados `docs` do attribute viram uma página HTML (ou JSON com `?format=json`), e os campos marcados como `required` são validados antes de o controller rodar.
- **Observabilidade embutida**: requests, queries SQL normalizadas, exceções, erros fatais e chamadas externas (HTTP, e-mail, storage), gravados no banco do projeto e agregados por hora, sem serviço externo.

## Requisitos

- PHP **8.1** ou superior (a CI roda em 8.2, 8.3 e 8.4)
- Extensão `pdo` e o driver PDO do seu banco (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlsrv` ou `pdo_sqlite`)

Opcionais (seção `suggest` do `composer.json`):

| Pacote / extensão | Para quê |
|-------------------|----------|
| `ext-apcu` | Cache de rotas e de metadados dos models entre requisições |
| `ext-curl` | `Psf\Http\Http::request` e `Psf\Http\Request` |
| `aws/aws-sdk-php` | `Psf\Utils\CloudStorage` (Cloudflare R2) |
| `robmorgan/phinx` | Comando `vendor/bin/psf` (migrations, geradores e comandos do monitor) |

`Psf\Utils\Email` usa o PHPMailer (`phpmailer/phpmailer`), que também precisa ser instalado pelo projeto.

## Instalação

```bash
composer require psf/framework
composer require robmorgan/phinx   # se for usar migrations ou o monitor
```

## Início rápido

### Estrutura do projeto

```
meu-projeto/
  config.php            ← configuração do PSF (o vendor/bin/psf procura este arquivo na raiz)
  app/
    Auth.php
    Controllers/
    Models/
  db/
    migrations/
  public/
    index.php
    .htaccess
  composer.json         ← "autoload": {"psr-4": {"App\\": "app/"}}
```

O `vendor/bin/psf` procura, na raiz do projeto e nesta ordem, `bootstrap/app.php`, `config/bootstrap.php`, `bootstrap.php` ou `config.php`, e usa o primeiro que encontrar como arquivo de configuração. Ele precisa **retornar o array de config**.

### `config.php`

```php
<?php

use Psf\Enumerators\DBDriver;

return [
    'environment' => 'dev', // 'prd' mantém o cache de rotas estático (limpe no deploy)

    'db' => [
        'default' => [
            'driver'   => DBDriver::MySQL, // MySQL, PostgreSQL, SQLServer ou SQLite
            'hostname' => 'localhost',     // 'host', 'user' e 'pass' também são aceitos
            'username' => 'root',
            'password' => '',
            'database' => 'meu_banco',     // SQLite: caminho do arquivo ou ':memory:'
            'port'     => 3306,
        ],
    ],

    'jwt' => [
        'secret' => 'uma-chave-longa-e-aleatoria', // mínimo de 16 caracteres
    ],

    'settings' => [
        'controllers' => [__DIR__ . '/app/Controllers'], // diretórios varridos em busca de #[Router]
        'debug'       => false, // true expõe mensagem/arquivo/linha dos erros de banco na resposta
        'verifyauth'  => [App\Auth::class, 'verify'],    // usado pelo middleware 'authentication'
        'docsapi'     => 'api/docs',                     // opcional: página de documentação da API
        'cors'        => [
            'origins'     => ['https://app.exemplo.com'], // ou '*'
            'credentials' => false,
        ],
    ],
];
```

Outras conexões podem ser declaradas em `db` (por exemplo `'legado' => [...]`) e usadas com `#[Database('legado')]` no model.

### `public/index.php` e `.htaccess`

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Psf\Http\{Http, Router};

PSF::init(['config' => __DIR__ . '/../config.php']);

try {
    (new Router())->handle();
} catch (\Throwable $e) {
    // O Router sinaliza 404 (rota não encontrada), 4xx do controller e 500 lançando
    // exceções com o status HTTP no código. Sem este catch, elas chegam à página do Ignition.
    $code   = $e->getCode();
    $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
    Http::response($status < 500 ? $e->getMessage() : 'Erro interno', [], $status);
}
```

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?_url=$1 [QSA,L]
```

O Router lê o caminho em `$_GET['_url']`. As rotas vêm dos diretórios em `settings.controllers`; o construtor do `Router` ainda aceita uma lista de classes (formato antigo), mas não a utiliza.

### Um model

```php
<?php

namespace App\Models;

use Psf\Model\{Model, ModelTrait};
use Psf\Model\Attributes\{Table, Column, PrimaryKey, Type, Nullable, Standard, ColumnCreatedDate, ColumnDeletedDate};

#[Table('usuarios')]
class Usuario extends Model
{
    use ModelTrait; // find(), findById(), col(), createMany()

    #[PrimaryKey, Column('id'), Type('INT UNSIGNED AUTO_INCREMENT')]
    public ?int $id = null;

    #[Column('nome'), Nullable(false), Type('VARCHAR(100) NOT NULL')]
    public ?string $nome = null;

    #[Column('status'), Standard('ativo'), Type('VARCHAR(20)')]
    public ?string $status = null;

    #[Column('created_at'), ColumnCreatedDate, Type('DATETIME')]
    public ?string $createdAt = null;

    #[Column('deleted_at'), ColumnDeletedDate, Type('DATETIME')] // delete() vira soft delete
    public ?string $deletedAt = null;
}
```

### Um controller

```php
<?php

namespace App\Controllers;

use App\Models\Usuario;
use Psf\Http\{Http, Router};
use Psf\Model\ControllerBase;

class UsuarioController extends ControllerBase
{
    #[Router(method: 'GET', path: '/usuarios/{id:int}', version: 1)]
    public function show(int $id)
    {
        $usuario = Usuario::findById($id);
        if (!$usuario) {
            Http::response('Usuário não encontrado', [], 404);
        }

        Http::response('OK', $usuario->toArray());
    }

    #[Router(method: 'POST', path: '/usuarios', version: 1, middlewares: ['authentication'], docs: [
        'title'  => 'Criar usuário',
        'fields' => [['name' => 'nome', 'type' => 'string', 'required' => true]],
    ])]
    public function store()
    {
        $usuario = new Usuario();
        $usuario->assign($this->data); // body já interpretado (JSON, form-data ou query string)
        $usuario->create();

        Http::response('Usuário criado', ['id' => $usuario->id], 201);
    }
}
```

`GET /v1/usuarios/42` chama `show(42)`. `Http::response()` envia o JSON (`{"message": ..., "data": ...}`) e **encerra a execução com `exit`**: nada depois dele roda.

O verificador de `settings.verifyauth` é um método sem argumentos. Retornar `true` ou um objeto libera a rota (o objeto fica em `Router::$auth`); qualquer outro retorno responde 401:

```php
<?php

namespace App;

use Psf\Http\RequestParser;
use Psf\Utils\JWT;

class Auth
{
    public function verify(): object|bool
    {
        $token   = RequestParser::extractBearerToken();
        $payload = $token ? JWT::verify($token) : false; // valida assinatura, exp e nbf

        return $payload ? (object) $payload : false;
    }
}
```

### Uma consulta

```php
$pagina = Usuario::find()
    ->andWhere([Usuario::col('status') => 'ativo'])
    ->andWhere([Usuario::col('createdAt'), '>=', '2026-01-01'])
    ->orderBy(Usuario::col('nome'))
    ->paginator(page: 1, itensPerPage: 20);
```

Referências a propriedades (`Usuario::col('createdAt')`, `Usuario::class . '.createdAt'` ou `alias.createdAt`) são traduzidas para a coluna (`created_at`). Como o model tem `#[ColumnDeletedDate]`, a consulta já filtra `deleted_at IS NULL`.

## Arquitetura

```
public/index.php ─ PSF::init(config) ─ Router
  Router::__construct   Monitor::boot (se ligado) e tabela de rotas (RouteCacheManager, APCu)
  Router::handle        CORS (preflight → 204) · página de docs · casamento da rota (vN + path)
                        middleware 'authentication' (verifyauth) · validação de docs.fields
                        controller (ControllerBase: $this->data, $this->token, $this->method)
  Http::response()      JSON + exit  →  shutdown: flush do monitor, depois da resposta
```

- **Configuração global**: `settings.php` (carregado pelo Composer) define a classe `PSF` com `init()`, `getConfig()` e `config('db', 'default', 'driver')`. Todo o framework lê a configuração por ela, então `PSF::init()` precisa rodar antes de qualquer outro código do framework.
- **Rotas**: o `RouteCacheManager` varre `settings.controllers` atrás de `#[Router]` e guarda a tabela em APCu. Em `prd` a chave é estática (TTL de 1 h; chame `(new \Psf\Http\RouteCacheManager())->clear()` no deploy); em `dev` ela muda quando algum arquivo de controller muda.
- **Metadados dos models**: o `MetadataCache` guarda o resultado da reflection (colunas, tabela, conexão) num cache de dois níveis: array estático no request e APCu entre requests.
- **Consultas**: `Model::find()` → `ModelQuery` → `QueryBuilder` → `Read::exe()`. As referências a entidades e colunas passam pelo `ReferenceResolver`; a hidratação (inclusive relações 1:N de `leftJoinAndSelect`) fica em `ModelHydrator`/`QueryHydrator`, e a serialização (`toArray()`) em `ModelSerializer`.
- **Persistência**: `Connect`, `Create`, `Read`, `Update` e `Delete` em `src/database/`, sempre com *prepared statements*.
- **Dialetos**: `DialectFactory::fromConfig()` devolve o `MySQLDialect`, `PostgreSQLDialect`, `SQLServerDialect` ou `SQLiteDialect` para tudo que muda entre bancos (DSN, quoting de identificadores, paginação, listagem de tabelas).

## Funcionalidades

### Roteamento

- Verbos por rota (`GET`, `POST`, `PUT`, `DELETE`, ...), versão (`/v1/...`, `/v12/...`) e middlewares (`authentication`, `loggin`).
- Parâmetros tipados: `{id:int}`, `{nome:string}`, `{slug:slug}`, `{uuid:uuid4}`; regex inline como `{codigo:/^[A-Z]{3}-\d{4}$/}` (expressões sem delimitador são ancoradas). `{id}` sem tipo aceita qualquer valor.
- Exceção com código 4xx lançada pelo controller mantém status e mensagem; outras viram 500 "Erro ao executar a rota." (a original fica em `getPrevious()`).
- `loggin` chama `settings.logrequest` (`[Classe::class, 'metodo']`) com endpoint, versão, método, body, headers e resposta.

Detalhes em [docs/rotas.md](docs/rotas.md).

### Models e CRUD

`$model->create()`, `save()`, `delete()` (soft delete com `#[ColumnDeletedDate]`), `assign($dados)`, `toArray()`, `Usuario::createMany([...])` para inserção em lote, e transações pelo controller (`initTransaction()`, `commitTransaction()`, `rollBackTransaction()`, `runServiceTransaction()`). Attributes disponíveis: `Table`, `Database`, `Column`, `PrimaryKey`, `Type`, `Nullable`, `Standard` (`'UUIDV4'` gera UUID), `ColumnCreatedDate`, `ColumnUpdatedDate`, `ColumnDeletedDate` e `Enum`. Ver [docs/models.md](docs/models.md) e [docs/guia.md](docs/guia.md).

### Query builder

`andWhere`/`orWhere` (array, associativo ou string com bindings), operadores `=`, `<>`, `!=`, `>`, `<`, `>=`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `IS NULL`; `innerJoin`, `leftJoin`, `leftJoinAndSelect`, `fields`, `groupBy`, `orderBy`, `limit`, `alias`, `asArray`; e os finalizadores `one`, `all`, `count`, `exist`, `sum` e `paginator`. Expressões SQL em `fields`/`groupBy`/`orderBy` só aceitam funções de uma lista permitida. O modo estrito (`settings.strictQueries` ou `->strict()`) faz uma referência que não resolve lançar erro em vez de gerar SQL quebrado. Ver [docs/query-builder.md](docs/query-builder.md).

### Bancos de dados

| Driver | `DBDriver` | Observações |
|--------|------------|-------------|
| MySQL / MariaDB | `DBDriver::MySQL` | padrão quando `driver` não é informado |
| PostgreSQL | `DBDriver::PostgreSQL` | schema `public` na listagem de tabelas |
| SQL Server | `DBDriver::SQLServer` | schema `dbo` |
| SQLite | `DBDriver::SQLite` | `database` é o caminho do arquivo ou `:memory:` |

Configs antigas podem passar o valor inteiro do enum (`'driver' => 1`). Ver [docs/database.md](docs/database.md).

### Autenticação, JWT e CORS

- `JWT::encode($payload)`, `JWT::verify($token)` (assinatura, `exp` e `nbf`) e `JWT::decode()`. `jwt.secret` ausente ou com menos de 16 caracteres lança `RuntimeException`.
- `settings.cors` aceita `origins` (lista ou `'*'`), `methods`, `headers`, `credentials`, `max_age` e `expose_headers`; o preflight `OPTIONS` é respondido com 204 sem chegar às rotas.

### Documentação da API

Com `settings.docsapi` (`'api/docs'`, ou `['path' => 'api/docs', 'username' => ..., 'password' => ...]` para exigir HTTP Basic), o caminho configurado mostra as rotas que têm `docs` no attribute, agrupadas por `docs.category`. `?format=json` devolve o mesmo conteúdo em JSON. A URL base exibida vem de `config['project']['domain']`.

### Utilitários

- `Psf\Utils`: `JWT`, `Email` (SMTP via PHPMailer), `CloudStorage` (Cloudflare R2, configurado em `config['aws']`), `APCU` e `Webview`.
- `Psf\Http\Request`: cliente HTTP fluente sobre cURL (`Request::post()->url(...)->body([...])->send()`).
- `Psf\Helper`: `CheckFields`, `Valid`, `Format`, `Text`, `UUID`, `IP`, `Device`, `TimeZone`, `Apis` e `Miscellaneous`. Ver [docs/helpers.md](docs/helpers.md).

## Observabilidade (monitor)

O monitor grava métricas de cada request da API no **banco do próprio projeto**, sem infraestrutura nova e sem UI: cada projeto decide como consumir os dados (SQL direto, Grafana, Metabase ou um endpoint próprio usando o `Reader`).

**O que é registrado**

- **Requests**: método, padrão da rota (`usuarios/{id:int}`, nunca a URL real; `(unmatched)` para 404), versão, status, duração, pico de memória, `env`, `release` e, opcionalmente, o `user_id` de `Router::$auth`.
- **Queries SQL** executadas pelos shims `Read`, `Create`, `Update` e `Delete`, gravadas **normalizadas** (literais e listas viram `?`) com hash de agrupamento.
- **Exceções** 5xx dos controllers (com a cadeia `getPrevious()`), `PDOException` dos shims e **erros fatais** do PHP, com stack trace sem os argumentos dos frames.
- **Chamadas externas** feitas por `Request`, `Http::request`, `Email` e `CloudStorage` (e por `Monitor::trackExternal()` nas suas): tipo (`http`, `mail`, `storage`), um rótulo seguro do destino (só o host ou `provedor/operação`, nunca URL, query, destinatário nem bucket), duração e status.
- Cada request recebe um `X-Request-Id` (o de entrada, se válido, ou um gerado), devolvido na resposta e, com `settings.cors` configurado, exposto ao JavaScript do navegador. `Monitor::requestId()` devolve o valor para os logs da aplicação.

**Como funciona**

- **Amostragem**: erros (status >= 500), requests com exceção e requests, queries ou chamadas externas lentos são sempre gravados; os demais com a probabilidade `sample.normal`, com peso `1 / sample.normal`. Os agregados somam esses pesos, então estimam o tráfego total.
- **Baixo custo**: durante o request só se guarda o SQL cru, a duração e a conexão. Normalização, hash e gravação acontecem no shutdown, depois de `fastcgi_finish_request()` (com PHP-FPM o cliente já recebeu a resposta), num único lote transacional por uma conexão própria.
- **Circuit breaker**: após falhas seguidas de gravação, o monitor para de gravar por um tempo. Falhas do monitor nunca chegam à aplicação.
- **Agregados por hora** em `psf_monitor_rollups` (hits, erros, tempo total e máximo, histograma para p50/p95/p99), gerados pelo `monitor:rollup`/`monitor:maintain`. O dado agregado atrasa até cerca de 1 h 5 min.
- **Retenção**: brutos por `retention.raw_days`, agregados por `retention.aggregate_days`, limpos pelo `monitor:prune`/`monitor:maintain` (não há limpeza durante requests).
- **Privacidade**: body, headers, cookies, token, query string e valores dos bindings nunca são gravados. O SQL é guardado só normalizado, e as mensagens de `PDOException` e de erros fatais passam por uma limpeza que troca os valores das linhas por `?`. Mensagens das exceções da aplicação são gravadas como vieram (`store_exception_message => false` desliga).

**Ativação**

```php
'settings' => [
    'monitor' => [
        'enabled'   => true,               // padrão: false (monitor totalmente desligado)
        'database'  => 'default',          // chave de config['db'] onde ficam as tabelas
        'sample'    => ['normal' => 0.1],  // fração dos requests "normais" gravados
        'slow_ms'   => ['request' => 1000, 'query' => 200, 'external' => 1000],
        'retention' => ['raw_days' => 7, 'aggregate_days' => 90],
        'release'   => '1.4.2',            // opcional, texto livre
    ],
],
```

Os valores acima são os padrões (exceto `enabled` e `release`). Também existem `max_events_per_request` (200), `breaker` (`failures` 3, `cooldown_seconds` 300), `connect_timeout` (1 s), `expose_request_id` (`true`), `store_user` (`true`), `user_id_property` (`'id'`), `store_exception_message` (`true`) e `auto_migrate` (`false`; cria as tabelas sozinho fora de `prd`) e `api` (`['enabled' => false]`; liga o serviço `MonitorApi`).

```bash
vendor/bin/psf monitor:install    # cria as tabelas psf_monitor_* (idempotente)
vendor/bin/psf monitor:status     # banco, instalação, monitor ligado e até onde o rollup chegou
```

```cron
17 * * * * cd /caminho/do/projeto && php vendor/bin/psf monitor:maintain
```

**Leitura dos dados**: `Psf\Monitor\Reader` oferece `routes()`, `slowQueries()`, `exceptions()`, `external()` e `throughput()` sobre os agregados de um intervalo, e `request($requestId)` com o detalhe bruto de um request. Opcionalmente, `Psf\Monitor\Api\MonitorApi` valida os parâmetros e devolve `[mensagem, dados, status]` para o controller do projeto (desligada por padrão, `settings.monitor.api.enabled`, e sem rotas próprias). O framework não expõe esses dados sozinho: a autenticação e a autorização de um endpoint de métricas ficam por conta do projeto.

**Estado atual**: as fases 1 a 3 e a Parte A da fase 4 estão entregues (requests, queries, exceções, agregados, retenção, `Reader`, chamadas externas e o serviço JSON opcional `MonitorApi`). Quem já usa o monitor precisa rodar `vendor/bin/psf monitor:install` para criar a 6ª tabela (`psf_monitor_external`). A **Parte B (validação em bancos reais) está concluída para MySQL 8.4 e PostgreSQL 16; apenas o SQL Server segue pendente**. Além do SQLite, as migrations, as gravações, o rollup, o prune, o `Reader`, a `MonitorApi` e os comandos foram **validados em MySQL 8.4 e PostgreSQL 16 reais** (suíte `tests/Drivers`, ver [Validação em bancos reais](docs/observabilidade.md#validação-em-bancos-reais)); essa validação corrigiu a instalação no MySQL e o `Read::exe()` no PostgreSQL. **SQL Server ainda não foi validado** (pendente); valide em homologação antes de usar com ele.

A referência completa (esquema das tabelas, regras dos agregados, limites conhecidos) está em [docs/observabilidade.md](docs/observabilidade.md).

## Comandos CLI

O framework instala o binário `psf` do Composer. Em projetos consumidores use `vendor/bin/psf`; dentro deste repositório, `./bin/psf` (ou `./bin/phinx`). É um wrapper: ele encontra o autoloader e o arquivo de config do projeto, chama `PSF::init()`, transforma cada entrada de `config['db']` num ambiente do Phinx e acrescenta os comandos do PSF. Não use o Phinx puro (`vendor/robmorgan/phinx/bin/phinx`) com o PSF.

| Comando | O que faz |
|---------|-----------|
| `create NomeDaMigration` | Cria uma migration em branco |
| `migrate [-e conexao]` | Roda as migrations pendentes (`-e` = chave de `config['db']`) |
| `rollback` | Desfaz a última migration |
| `status` | Mostra o estado das migrations |
| `seed:create` / `seed:run` | Cria e executa seeds |
| `make:migration-from-model 'App\Models\Usuario'` | Cria uma migration comparando o model com o estado atual do banco |
| `make:model-from-table usuarios 'App\Models\Usuario'` | Gera um model PSF (com attributes) a partir de uma tabela |
| `analyze:table-vs-model usuarios 'App\Models\Usuario' [-o arquivo]` | Compara a tabela com o model e mostra as diferenças |
| `monitor:install [-e banco]` | Instala as tabelas do monitor |
| `monitor:status [-e banco]` | Mostra o estado do monitor |
| `monitor:rollup [-e banco] [--max-hours N]` | Agrega por hora os registros brutos |
| `monitor:prune [-e banco]` | Apaga registros brutos e agregados fora da retenção |
| `monitor:maintain [-e banco] [--max-hours N]` | Rollup seguido da limpeza (o comando para o cron) |

`vendor/bin/psf` é o nome estável. O `robmorgan/phinx` também declara um binário `phinx` e o Composer mantém o primeiro que instalar: `vendor/bin/phinx` só é o wrapper do PSF quando o Composer ligou o do PSF (continua com esse nome por compatibilidade); num projeto que já tinha o Phinx, ele segue sendo o Phinx puro, sem os comandos `make:*` e `monitor:*`. Por isso prefira sempre `vendor/bin/psf`.

As migrations do projeto vão para `db/migrations` e os seeds para `db/seeds`, na raiz do projeto (crie as pastas; o Phinx não as cria). Sem um `phinx.php` próprio no diretório atual, o wrapper usa a config do framework; `-c arquivo` escolhe outra. As tabelas do monitor usam migrations próprias, fora desse caminho, com a tabela de controle `psf_monitor_migrations`.

## Testes e qualidade

```bash
composer test               # suíte completa (Pest v3)
composer test:unit          # tests/Unit (PDO simulado)
composer test:integration   # tests/Integration (SQLite :memory:, sem servidor de banco)
composer analyse            # PHPStan nível 1 em src/ e settings.php

# Monitor contra MySQL e PostgreSQL reais (Docker; sem PSF_DRIVERS=1 a suíte é pulada)
docker compose -f docker-compose.drivers.yml up -d --wait
composer test:drivers       # tests/Drivers (PSF_DRIVERS=1)
docker compose -f docker-compose.drivers.yml down -v
```

Os testes rodam com `apc.enable_cli = 0` e com a constante `PSF_TESTING`, que faz o `Http::response()` lançar `HttpResponseException` em vez de chamar `exit`. No momento desta revisão, a suíte completa roda 808 testes (2532 asserções) e pula 69 (os 67 da suíte de drivers, que só roda com `PSF_DRIVERS=1`, e 2 que dependem de bibliotecas opcionais). A suíte de drivers roda 67 testes (33 no MySQL 8.4 e 34 no PostgreSQL 16). A CI (GitHub Actions) executa os testes em PHP 8.2, 8.3 e 8.4 e a análise estática.

## Estrutura do repositório

```
bin/psf, bin/phinx   wrapper do Phinx (inicia o PSF e registra os comandos do framework)
phinx.php            config do Phinx gerada a partir de config['db']
settings.php         classe global PSF, constantes DR/ROOT e Ignition
src/
  database/          Connect, Create, Read, Update, Delete; Dialect/; Command/ (geradores)
  enum/              DBDriver, HTTPMethod, HttpStatusCode, HTTPBodyEncoded, Protocol
  helpers/           CheckFields, Valid, Format, Text, UUID, IP, Device, ...
  http/              Router, RouteCacheManager, RequestParser, Http, Request, Cors, ApiDocsGenerator
  model/             Model, ModelTrait, ControllerBase, ModelQuery, QueryBuilder, MetadataCache, attributes/
  monitor/           Monitor, Sampler, Buffer, ExternalTarget, SqlNormalizer, Rollup, Pruner, Reader, Api/, Command/, migrations/
  utils/             JWT, Email, CloudStorage, APCU, Webview
tests/               Unit/, Integration/, Fixtures/ (Pest)
docs/                documentação de uso
```

## Documentação

| Arquivo | Conteúdo |
|---------|----------|
| [docs/introducao.md](docs/introducao.md) | Visão geral e estrutura de pastas |
| [docs/guia.md](docs/guia.md) | Guia prático: instalação, configuração, models, controllers, rotas, CRUD, migrations e testes |
| [docs/rotas.md](docs/rotas.md) | Attribute `#[Router]`, parâmetros, erros, CORS e cache de rotas |
| [docs/models.md](docs/models.md) | Models e attributes |
| [docs/query-builder.md](docs/query-builder.md) | Referência do query builder |
| [docs/database.md](docs/database.md) | Drivers, chaves de configuração, erros e transações |
| [docs/helpers.md](docs/helpers.md) | Helpers utilitários |
| [docs/exemplos.md](docs/exemplos.md) | Exemplos curtos de controllers |
| [docs/observabilidade.md](docs/observabilidade.md) | Monitor: configuração, esquema das tabelas, agregados, `Reader` e limites |
| [docs/melhorias.md](docs/melhorias.md) | Histórico das melhorias da v0.0.10 à v0.4.0 |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Roadmap de melhorias |
| [CHANGELOG.md](CHANGELOG.md) | Mudanças ainda não lançadas e mudanças de comportamento |

## Status e limites

- **Beta**: o framework ainda evolui e algumas versões mudam comportamentos (sempre listados no [CHANGELOG.md](CHANGELOG.md)); leia antes de atualizar.
- As rotas lançam exceções com o status HTTP no código (404, 4xx do controller, 500): o `public/index.php` precisa tratá-las, como no exemplo acima.
- `Http::response()` encerra o processo com `exit`; código depois dele não roda (nos testes, use `PSF_TESTING`).
- Sem APCu, rotas e metadados são recalculados a cada request (funciona, só custa mais).
- O monitor foi validado em SQLite, MySQL 8.4 e PostgreSQL 16; a validação em SQL Server real está pendente (ver acima).
- Só há os middlewares `authentication` e `loggin`; um novo middleware exige mudança no `Router`.

## Versionamento e compatibilidade

O PSF é consumido por outros projetos: as APIs públicas são mantidas retrocompatíveis, e mudanças de comportamento são listadas no [CHANGELOG.md](CHANGELOG.md) (o histórico anterior está em [docs/melhorias.md](docs/melhorias.md)). As versões são publicadas como tags git.

## Contribuindo

Este projeto está em fase Beta, e aceitamos sugestões e feedbacks. No momento, a contribuição está limitada a um grupo privado devido à licença do framework.

Antes de propor uma mudança, rode `composer test` e `composer analyse`. Código, comentários e documentação são escritos em português do Brasil.

## Licença

Este projeto é licenciado sob uma licença privada. Entre em contato para mais informações sobre o uso e distribuição.

## Contato

Para dúvidas ou suporte, entre em contato pelo e-mail: theodoro@porglin.com
