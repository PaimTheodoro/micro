# Rotas

O roteamento é feito via o atributo `#[Router(...)]` nos métodos dos controllers. As rotas são automaticamente escaneadas e cacheadas pelo `RouteCacheManager`.

### Exemplo de definição de rota em um Controller

```php
use Psf\Http\Router;

class UsuarioController {
    #[Router(
        method: 'GET',
        path: '/usuarios/{id:int}',
        version: 1,
        middlewares: ['authentication'],
        docs: [
            'title' => 'Buscar usuário por ID',
            'description' => 'Retorna os dados de um usuário pelo seu ID inteiro.',
            'fields' => [
                ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'ID numérico do usuário']
            ]
        ]
    )]
    public function show($id) {
        // ...
    }
}
```

- **method**: Método HTTP (GET, POST, PUT, DELETE)
- **path**: Caminho da rota, suporta parâmetros dinâmicos (`{id:int}`)
- **version**: Versão da API
- **middlewares**: Lista de middlewares (ex: 'authentication', 'loggin')
- **docs**: Metadados para documentação automática

### Patterns pré-definidos para parâmetros dinâmicos

Você pode usar os seguintes patterns já prontos para validação automática:

| Pattern  | Descrição                                      | Exemplo de uso                |
|----------|------------------------------------------------|-------------------------------|
| int      | Apenas números inteiros positivos               | `/usuarios/{id:int}`          |
| string   | Alfanumérico e underscore                      | `/produtos/{nome:string}`     |
| slug     | Letras minúsculas, números e hífen              | `/blog/{slug:slug}`           |
| uuid4    | UUID v4 padrão                                 | `/usuarios/{uuid:uuid4}`      |

Também é possível usar regex inline diretamente na rota, por exemplo:

```php
#[Router(path: '/produtos/{codigo:/^[A-Z]{3}-\d{4}$/}')]
```

Expressões sem delimitador (`{n:\d+}`) são ancoradas (`^...$`) — não casam por substring. Regex inválida faz a rota não casar. O prefixo de versão aceita qualquer quantidade de dígitos (`v1`, `v12`, ...).

### Erros nos controllers

- `HttpResponseException` (resposta HTTP deliberada) passa direto.
- Uma exceção com código 4xx explícito (`throw new \Exception('Sem permissão', 403)`) mantém status e mensagem.
- Qualquer outra exceção vira 500 "Erro ao executar a rota." (a original fica em `getPrevious()`).
- `\Error`/`TypeError` lançados pelo controller são propagados sem alteração.

Com o [monitor](observabilidade.md#exceções) ligado, o `Router` registra a exceção original das falhas 5xx e os `\Error`/`TypeError` (os 4xx e `HttpResponseException` não são registrados).

### CORS

Configure `settings.cors` para liberar origens e responder o preflight (`OPTIONS`) com 204:

```php
'settings' => [
    'cors' => [
        'origins'     => ['https://app.exemplo.com'], // ou '*'; padrão: nenhuma origem
        'methods'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers'     => ['Content-Type', 'Authorization'],
        'credentials' => false, // com true, '*' devolve a origem da requisição
        'max_age'     => 600,
        'expose_headers' => ['X-Request-Id'], // opcional: emite Access-Control-Expose-Headers
    ],
],
```

Com o [monitor](observabilidade.md#x-request-id) ligado (e `expose_request_id`, o padrão), o `Router` acrescenta `X-Request-Id` a `expose_headers` automaticamente.

### Cache de rotas

Com APCu, as rotas ficam em cache (`prd`: chave estática por app, TTL de 1h; `dev`: invalidado pelo mtime dos arquivos). No deploy em `prd`, chame `(new \Psf\Http\RouteCacheManager())->clear()`.

### Execução

O roteador (`Psf\Http\Router`) resolve a rota, executa middlewares e despacha para o método do controller.

### Monitor (observabilidade)

Com `settings.monitor.enabled`, o construtor do `Router` inicia o monitor e emite o header `X-Request-Id` em toda resposta; ao casar a rota, o monitor grava o **padrão** dela (`/usuarios/{id:int}`), nunca a URL real (`(unmatched)` quando nenhuma rota casa), e o `user_id` vem de `Router::$auth` após a autenticação. Detalhes em [observabilidade.md](observabilidade.md#ciclo-de-vida-do-request).
