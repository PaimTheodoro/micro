# Models

Os models herdam de `Psf\Model\Model` e usam atributos para mapear colunas, chaves primárias, tipos, etc.

### Exemplo de Model

```php
use Psf\Model\Model;
use Psf\Model\Attributes\{Table, Database, Column, PrimaryKey, Type, Nullable, Standard, Enum, ColumnCreatedDate, ColumnUpdatedDate, ColumnDeletedDate};

#[Table('usuarios'), Database('dafault')]
class Usuario extends Model {
    #[Column('id'), PrimaryKey]
    public $id;

    #[Column('nome')]
    public $nome;

    #[Column('email'), Nullable(false)]
    public $email;

    #[Column('status'), Standard('Ativo')]
    public $status;

    #[Column('created'), ColumnCreatedDate, Type('timestamp')]
    public $created;

    #[Column('updated'), ColumnUpdatedDate, Type('timestamp')]
    public $updated;

    #[Column('deleted'), ColumnDeletedDate, Type('timestamp')]
    public $deleted;
}
```

### Métodos principais

- `$usuario->create()`: Cria novo registro
- `$usuario->save()`: Atualiza registro existente
- `$usuario->delete()`: Remove (soft delete se houver `ColumnDeletedDate`)
- `Usuario::find()->all()`: Busca todos
- `Usuario::find()->fields(['nome'])->andWhere(['email' => 'a@b.com'])->one()`: Busca customizada 