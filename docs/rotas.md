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

### Execução

O roteador (`Psf\Http\Router`) resolve a rota, executa middlewares e despacha para o método do controller. 