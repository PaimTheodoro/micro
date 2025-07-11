# Exemplos de Uso Real

### Buscar usuário por ID

```php
// Controller
class UsuarioController {
    #[Router(method: 'GET', path: '/usuarios/{id:UUID4}', version: 1)]
    public function show($id) {
        $usuario = Usuario::find()->andWhere(['id' => $id])->one();
        if (!$usuario) {
            return Http::response('Usuário não encontrado', [], 404);
        }
        return Http::response('Usuário encontrado', $usuario->toArray());
    }
}
```

### Criar novo usuário

```php
class UsuarioController {
    #[Router(method: 'POST', path: '/usuarios', version: 1)]
    public function create() {
        $data = $_POST; // ou use $this->data no ControllerBase
        $usuario = new Usuario();
        $usuario->assign($data);
        if ($usuario->create()) {
            return Http::response('Usuário criado', $usuario->toArray(), 201);
        }
        return Http::response('Erro ao criar usuário', [], 400);
    }
}
``` 