<?php

use Psf\Database\{Connect, Create, Delete, Read, Update};
use Psf\Http\HttpResponseException;
use Tests\Fixtures\Models\{FakeSoftDeleteModel, FakeUserModel};

function seedNotes(string ...$titles): void
{
    foreach ($titles as $title) {
        $note        = new FakeSoftDeleteModel();
        $note->title = $title;
        $note->create();
    }
}

it('discovers the SQLite tables and columns through the dialect', function () {
    expect(Connect::$tables['default'])->toContain('fake_users', 'fake_notes');

    $columns = array_map(fn($c) => $c->Field, Connect::getColunsForTable('fake_users', 'default'));
    expect($columns)->toBe(['id', 'name', 'email', 'created_at']);

    $pk = array_filter(Connect::getColunsForTable('fake_users', 'default'), fn($c) => $c->Key === 'PRI');
    expect(array_values(array_map(fn($c) => $c->Field, $pk)))->toBe(['id']);
});

it('creates, finds, updates and hard-deletes a model', function () {
    $user        = new FakeUserModel();
    $user->name  = 'Ana';
    $user->email = 'ana@exemplo.com';

    expect($user->create())->toBeTrue();
    expect($user->id)->toBeGreaterThan(0);

    $found = FakeUserModel::findById($user->id);
    expect($found->name)->toBe('Ana');

    $found->name = 'Ana Maria';
    expect($found->save())->toBeTrue();
    expect(FakeUserModel::findById($user->id)->name)->toBe('Ana Maria');

    $found->delete();
    expect(FakeUserModel::findById($user->id))->toBeEmpty();
});

it('soft-deletes and hides the row from queries', function () {
    seedNotes('a', 'b');

    $a = FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.title', '=', 'a'])->one();
    $a->delete();

    $titles = array_map(fn($n) => $n->title, FakeSoftDeleteModel::find()->all());
    expect($titles)->toBe(['b']);

    // A linha continua no banco, com deleted_at preenchido
    $raw = Connect::getConnection('default')->query('SELECT deleted_at FROM fake_notes WHERE title = "a"')->fetchColumn();
    expect($raw)->not->toBeEmpty();
});

it('never resurrects soft-deleted rows through orWhere', function () {
    seedNotes('a', 'b', 'c');
    FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.title', '=', 'b'])->one()->delete();

    $titles = array_map(
        fn($n) => $n->title,
        FakeSoftDeleteModel::find()
            ->andWhere([FakeSoftDeleteModel::class . '.title', '=', 'a'])
            ->orWhere([FakeSoftDeleteModel::class . '.title', '=', 'b'])
            ->all()
    );

    expect($titles)->toBe(['a']);
});

it('applies a lone orWhere instead of returning every row', function () {
    seedNotes('a', 'b', 'c');

    $titles = array_map(
        fn($n) => $n->title,
        FakeSoftDeleteModel::find()->orWhere([FakeSoftDeleteModel::class . '.title', '=', 'c'])->all()
    );

    expect($titles)->toBe(['c']);
});

it('is idempotent when a query is executed twice', function () {
    seedNotes('a', 'b');

    $query = FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.title', '=', 'a']);

    expect(count($query->all()))->toBe(1);
    expect(count($query->all()))->toBe(1);
});

it('filters with the new comparison operators and BETWEEN', function () {
    seedNotes('a', 'b', 'c', 'd');

    $ids = fn($query) => array_map(fn($n) => $n->id, $query->all());

    expect($ids(FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.id', '>=', 3])))->toBe([3, 4]);
    expect($ids(FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.id', '!=', 1])))->toBe([2, 3, 4]);
    expect($ids(FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.id', 'BETWEEN', [2, 3]])))->toBe([2, 3]);
    expect($ids(FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.id', 'IN', [1, 4]])))->toBe([1, 4]);
});

it('throws on an invalid condition instead of silently returning every row', function () {
    seedNotes('a');

    expect(fn() => FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.id', 'NOPE', 1])->all())
        ->toThrow(InvalidArgumentException::class);
});

it('orders case-insensitively and paginates', function () {
    seedNotes('a', 'b', 'c', 'd', 'e');

    $titles = array_map(
        fn($n) => $n->title,
        FakeSoftDeleteModel::find()->orderBy(FakeSoftDeleteModel::class . '.id', 'desc')->all()
    );
    expect($titles)->toBe(['e', 'd', 'c', 'b', 'a']);

    $page = FakeSoftDeleteModel::find()->orderBy(FakeSoftDeleteModel::class . '.id', 'ASC')->paginator(2, 2);
    expect(array_column($page['itens'], 'title'))->toBe(['c', 'd']);
    expect((int) $page['paginator']['itens']['total'])->toBe(5);
});

it('counts and checks existence', function () {
    seedNotes('a', 'b');

    expect(FakeSoftDeleteModel::find()->count())->toBe(2);
    expect(FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.title', '=', 'a'])->exist())->toBeTrue();
    expect(FakeSoftDeleteModel::find()->andWhere([FakeSoftDeleteModel::class . '.title', '=', 'zzz'])->exist())->toBeFalse();
});

it('batch-inserts rows', function () {
    $notes = array_map(function ($title) {
        $n = new FakeSoftDeleteModel();
        $n->title = $title;
        return $n;
    }, ['x', 'y', 'z']);

    expect(FakeSoftDeleteModel::createMany($notes))->toBeTrue();
    expect(FakeSoftDeleteModel::find()->count())->toBe(3);
});

it('updates using placeholders that do not depend on column names', function () {
    seedNotes('a');

    $update = Update::exe('fake_notes', ['title' => 'novo'], 'WHERE id = :id', null, 'default', ['id' => 1]);

    expect($update->getResult())->toBeTrue();
    expect($update->getRowCount())->toBe(1);
    expect(FakeSoftDeleteModel::findById(1)->title)->toBe('novo');
});

it('applies the legacy parse string of Update::exe', function () {
    seedNotes('a', 'b');

    Update::exe('fake_notes', ['title' => 'z'], 'WHERE id = :id', 'id=2');

    expect(FakeSoftDeleteModel::findById(2)->title)->toBe('z');
    expect(FakeSoftDeleteModel::findById(1)->title)->toBe('a');
});

it('does not let a malicious column name inject SQL through Update', function () {
    seedNotes('a');

    $evil = 'title" = \'x\'; DROP TABLE fake_notes; --';

    expect(fn() => Update::exe('fake_notes', [$evil => 1], 'WHERE id = :id', null, 'default', ['id' => 1]))
        ->toThrow(HttpResponseException::class);

    expect(Connect::getConnection('default')->query('SELECT COUNT(*) FROM fake_notes')->fetchColumn())->toBe(1);
});

it('deletes through Delete::exe and reports the real result', function () {
    seedNotes('a', 'b');

    $delete = Delete::exe('fake_notes', 'WHERE id = :id', '', 'default', ['id' => 1]);

    expect($delete->getResult())->toBeTrue();
    expect($delete->getRowCount())->toBe(1);
});

it('reads through Read::exe using the dialect to quote the table', function () {
    seedNotes('a');

    $read = (new Read())->exe('fake_notes', 'WHERE id = :id', ['id' => 1], 'default');

    expect($read->getResult())->toHaveCount(1);
});

it('scopes ModelQuery::query() to the model table and database', function () {
    seedNotes('a', 'b');

    $rows = FakeSoftDeleteModel::find()->query('WHERE id = :id', ['id' => 2]);

    expect($rows)->not->toBeEmpty();
});

it('rediscovers a table created after the connection was opened', function () {
    Connect::getConnection('default')->exec('CREATE TABLE late_table (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

    expect(Connect::verifyTableExist('late_table', 'default'))->toBeTrue();
    expect(Create::exe('late_table', ['label' => 'x'], 'default')->getResult())->not->toBeEmpty();
    expect(Connect::verifyTableExist('nao_existe', 'default'))->toBeFalse();
});

it('memoizes the column list per request until the cache is cleared', function () {
    $pdo = Connect::getConnection('default');

    $first = Connect::getColunsForTable('fake_notes', 'default');
    $pdo->exec('ALTER TABLE fake_notes ADD COLUMN extra TEXT');

    expect(Connect::getColunsForTable('fake_notes', 'default'))->toBe($first);

    Connect::clearColumnsCache();
    expect(count(Connect::getColunsForTable('fake_notes', 'default')))->toBe(count($first) + 1);
});

it('toArray returns the table columns that exist as properties', function () {
    $user        = new FakeUserModel();
    $user->name  = 'Ana';
    $user->email = 'ana@exemplo.com';
    $user->create();

    expect(FakeUserModel::findById($user->id)->toArray())->toMatchArray([
        'id'    => $user->id,
        'name'  => 'Ana',
        'email' => 'ana@exemplo.com',
    ]);
});

it('hydrates objects and arrays with the cached property plan', function () {
    seedNotes('a', 'b');

    $objects = FakeSoftDeleteModel::find()->orderBy(FakeSoftDeleteModel::class . '.id', 'ASC')->all();
    expect($objects[0])->toBeInstanceOf(FakeSoftDeleteModel::class);
    expect($objects[1]->title)->toBe('b');
    expect($objects[0]->deletedAt)->toBeNull();

    $arrays = FakeSoftDeleteModel::find()->orderBy(FakeSoftDeleteModel::class . '.id', 'ASC')->asArray()->all();
    expect($arrays[0])->toBe(['id' => 1, 'title' => 'a', 'deletedAt' => null]);
});

// --- Referências a entidades: joins reais com alias, ordem livre e modo estrito ---

function seedUsersAndProducts(): void
{
    foreach ([['Ana', 'ana@x.com'], ['Beto', 'beto@x.com']] as [$name, $email]) {
        $u = new FakeUserModel();
        $u->name = $name;
        $u->email = $email;
        $u->create();
    }

    foreach ([[1, 'Caneta', 2.5], [1, 'Caderno', 10.0], [2, 'Lápis', 1.0]] as [$userId, $name, $price]) {
        $p = new \Tests\Fixtures\Models\FakeProductModel();
        $p->userId = $userId;
        $p->name = $name;
        $p->price = $price;
        $p->create();
    }
}

it('joins with aliases and resolves props through alias, class and col() against a real database', function () {
    seedUsersAndProducts();
    $P = \Tests\Fixtures\Models\FakeProductModel::class;

    // where/fields/orderBy antes do join e do alias: a ordem das chamadas não importa
    $rows = FakeUserModel::find()
        ->andWhere([$P::col('price', 'p'), '>=', 1.0])
        ->fields(['o.name', 'SUM(p.price) AS total'])
        ->orderBy('total DESC', null)
        ->groupBy('o.name')
        ->innerJoin($P, 'p', 'p.userId = o.id')
        ->alias('o')
        ->asArray()
        ->all();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('Ana');
    expect((float) $rows[0]['total'])->toBe(12.5);
    expect($rows[1]['name'])->toBe('Beto');
});

it('filters with the class-name form on the joined entity, which becomes its alias in the SQL', function () {
    seedUsersAndProducts();
    $P = \Tests\Fixtures\Models\FakeProductModel::class;

    $count = FakeUserModel::find()
        ->innerJoin([$P, 'p'], 'p.userId = ' . FakeUserModel::class . '.id')
        ->andWhere([$P . '.name', '=', 'Lápis'])
        ->count();

    expect($count)->toBe(1);
});

it('accepts associative conditions with a null and an IN list', function () {
    seedNotes('a', 'b', 'c');
    FakeSoftDeleteModel::find()->andWhere(['title' => 'c'])->one()->delete();

    $titles = array_map(
        fn($row) => $row->title,
        FakeSoftDeleteModel::find()->andWhere(['title' => ['a', 'c'], 'deletedAt' => null])->orderBy('id', 'ASC')->all()
    );

    // 'c' está no IN, mas foi deletado (deleted_at preenchido), e 'b' não está no IN
    expect($titles)->toBe(['a']);
});

it('keeps the soft delete filter working with alias, self-join and strict mode', function () {
    seedNotes('a', 'b');
    FakeSoftDeleteModel::find()->andWhere(['title', '=', 'b'])->one()->delete();

    $rows = FakeSoftDeleteModel::find()
        ->strict()
        ->alias('n')
        ->innerJoin([FakeSoftDeleteModel::class, 'm'], 'm.id = n.id')
        ->asArray()
        ->all();

    expect(array_column($rows, 'title'))->toBe(['a']);
});

it('strict mode surfaces a typo before reaching the database', function () {
    seedNotes('a');

    expect(fn() => FakeSoftDeleteModel::find()->strict()->andWhere([FakeSoftDeleteModel::class . '.titel', '=', 'a'])->all())
        ->toThrow(InvalidArgumentException::class, 'titel');
});

it('resolves references in the free-form tail of ModelQuery::query()', function () {
    seedNotes('a', 'b');

    $rows = FakeSoftDeleteModel::find()->alias('n')->query('WHERE ' . FakeSoftDeleteModel::class . '.title = :t', ['t' => 'b']);

    expect($rows)->not->toBeEmpty();
});
