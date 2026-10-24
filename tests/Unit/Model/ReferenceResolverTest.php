<?php

use Psf\Model\MetadataCache;
use Psf\Model\QueryBuilder;
use Psf\Model\ReferenceResolver;
use Tests\Fixtures\Models\{FakeAmbiguousModel, FakeProductModel, FakeUserModel};

const U = FakeUserModel::class;
const P = FakeProductModel::class;

beforeEach(function () {
    MetadataCache::clearCache();
    $this->builder = fn(string $class = U) => new QueryBuilder($class, null, \PSF::getConfig()->db['default']);
});

/** Remove aspas/backticks para comparar `a`.`b` e a.b como a mesma referência. */
function plain(string $sql): string
{
    return str_replace(['`', '"'], '', $sql);
}

/**
 * Cada ponto de entrada recebe a referência e devolve o SQL gerado. A mesma referência tem que
 * resolver para a mesma coluna em todos eles.
 */
function entryPoints(): array
{
    return [
        'where [ref, =, v]'        => fn(QueryBuilder $b, string $r) => $b->addAndWhere([$r, '=', 1]),
        'where [ref => v]'         => fn(QueryBuilder $b, string $r) => $b->addAndWhere([$r => 1]),
        'where [ref IS NULL]'      => fn(QueryBuilder $b, string $r) => $b->addAndWhere([$r, 'IS NULL']),
        'where "ref = :x"'         => fn(QueryBuilder $b, string $r) => $b->addAndWhere("$r = :x", ['x' => 1]),
        'where "(ref = :x)"'       => fn(QueryBuilder $b, string $r) => $b->addAndWhere("($r = :x)", ['x' => 1]),
        'where "1=ref"'            => fn(QueryBuilder $b, string $r) => $b->addAndWhere("1=$r"),
        'orWhere [ref, =, v]'      => fn(QueryBuilder $b, string $r) => $b->addOrWhere([$r, '=', 1]),
        'join ON'                  => fn(QueryBuilder $b, string $r) => $b->addLeftJoin(null, "LEFT JOIN x ON $r = x.id"),
        'orderBy(ref, ASC)'        => fn(QueryBuilder $b, string $r) => $b->addOrderBy($r, 'ASC'),
        'orderBy("ref DESC")'      => fn(QueryBuilder $b, string $r) => $b->addOrderBy("$r DESC", null),
        'groupBy'                  => fn(QueryBuilder $b, string $r) => $b->addGroupBy($r),
        'fields [ref]'             => fn(QueryBuilder $b, string $r) => $b->setFields([$r]),
        'fields [ref AS a]'        => fn(QueryBuilder $b, string $r) => $b->setFields(["$r AS a"]),
        'fields SUM(ref)'          => fn(QueryBuilder $b, string $r) => $b->setFields(["SUM($r) AS t"]),
        'fields COALESCE(ref, 0)'  => fn(QueryBuilder $b, string $r) => $b->setFields(["COALESCE($r, 0) AS t"]),
    ];
}

it('resolves every way of naming a column to the same column in every entry point (no aliases)', function () {
    $forms = [
        'Class.prop'           => U . '.createdAt',
        'Model::col()'         => FakeUserModel::col('createdAt'),
        'short name'           => 'FakeUserModel.createdAt',
        'short name, any case' => 'fakeusermodel.createdAt',
        'table.prop'           => 'fake_users.createdAt',
        'table.column'         => 'fake_users.created_at',
        'leading backslash'    => '\\' . U . '.createdAt',
        'bare prop (root)'     => 'createdAt',
    ];

    $failures = [];
    foreach (entryPoints() as $entry => $apply) {
        foreach ($forms as $form => $ref) {
            // 'join ON' com referência solta: o SQL cru de join não pode misturar a raiz com `x`
            $b = ($this->builder)();
            $b->addInnerJoin(P, P . '.userId = ' . U . '.id');

            $apply($b, $ref);
            $sql = plain($b->writeQuery());

            if (!str_contains($sql, 'fake_users.created_at')) {
                $failures[] = "[$entry] $form: $sql";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('resolves aliases, class and table names to the alias in every entry point', function () {
    $forms = [
        'alias'          => 'p.userId',
        'Class.prop'     => P . '.userId',
        'short name'     => 'FakeProductModel.userId',
        'table.prop'     => 'fake_products.userId',
        'Model::col()'   => FakeProductModel::col('userId'),
        'col() w/ alias' => FakeProductModel::col('userId', 'p'),
    ];

    $failures = [];
    foreach (entryPoints() as $entry => $apply) {
        foreach ($forms as $form => $ref) {
            $b = ($this->builder)();
            $b->setAlias('o');
            $b->addInnerJoin([P, 'p'], 'p.user_id = o.id');

            $apply($b, $ref);
            $sql = plain($b->writeQuery());

            // a referência ao produto vira p.user_id (alias, coluna) — nunca fake_products.* nem user_id sem alias
            $clean = str_replace('INNER JOIN fake_products AS p ON p.user_id = o.id', '', $sql);
            if (!str_contains($clean, 'p.user_id') || str_contains($clean, 'fake_products.')) {
                $failures[] = "[$entry] $form: $sql";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('does not depend on the call order (where/fields before the join and the alias)', function () {
    $b = ($this->builder)();
    $b->addAndWhere(['p.userId', '=', 1]);
    $b->setFields(['o.name', 'SUM(p.price) AS total']);
    $b->addOrderBy('p.userId', 'ASC');
    $b->addInnerJoin([P, 'p'], 'p.userId = o.id');
    $b->setAlias('o');

    $sql = plain($b->writeQuery());

    expect($sql)->toContain('SELECT o.name, SUM(p.price) AS total FROM psf_test.fake_users AS o');
    expect($sql)->toContain('INNER JOIN fake_products AS p ON p.user_id = o.id');
    expect($sql)->toContain('WHERE p.user_id = :');
    expect($sql)->toEndWith('ORDER BY p.user_id ASC');
});

it('leaves unknown prefixes, literals and 3-part names alone', function () {
    $b = ($this->builder)();
    $b->addAndWhere("sub.total > 1 AND other_db.fake_users.id = 2 AND name = 'fake_users.createdAt'");

    expect(plain($b->writeQuery()))->toContain("sub.total > 1 AND other_db.fake_users.id = 2 AND name = 'fake_users.createdAt'");
});

it('does not translate SQL words or function names that look like props', function () {
    $b = ($this->builder)();
    $b->addAndWhere('createdAt IS NOT NULL AND NOW() > createdAt');

    expect(plain($b->writeQuery()))->toContain('fake_users.created_at IS NOT NULL AND NOW() > fake_users.created_at');
});

it('translates a bare full class name to its table', function () {
    $b = ($this->builder)();
    $b->addAndWhere('EXISTS (SELECT 1 FROM ' . P . ' WHERE ' . P . '.userId = ' . U . '.id)');

    expect(plain($b->writeQuery()))->toContain('FROM fake_products WHERE fake_products.user_id = fake_users.id');
});

it('keeps a self join apart through aliases', function () {
    $b = ($this->builder)();
    $b->addInnerJoin([U, 'boss'], 'boss.id = ' . U . '.id');
    $b->addAndWhere(['boss.createdAt', '=', 1]);

    $sql = plain($b->writeQuery());
    expect($sql)->toContain('INNER JOIN fake_users AS boss ON boss.id = fake_users.id');
    expect($sql)->toContain('WHERE boss.created_at = :');
});

it('isolates the resolver of a cloned builder', function () {
    $original = ($this->builder)();
    $clone    = clone $original;
    $clone->setAlias('c');
    $clone->addInnerJoin([P, 'p'], 'p.userId = c.id');

    expect(plain($original->writeQuery()))->not->toContain(' AS c')->not->toContain('fake_products');
});

// --- alias ---

it('rejects invalid aliases', function (string $alias) {
    $b = ($this->builder)();

    expect(fn() => $b->setAlias($alias))->toThrow(InvalidArgumentException::class);
    expect(fn() => $b->addInnerJoin([P, $alias], 'x = y'))->toThrow(InvalidArgumentException::class);
})->with(['has space', "o'; DROP TABLE x; --", '1abc', 'a.b', '']);

it('qualifies COUNT and the default join fields with the alias', function () {
    $b = ($this->builder)();
    $b->setAlias('o');
    $b->addInnerJoin([P, 'p'], 'p.userId = o.id');
    $b->setIsCount(true);

    expect(plain($b->writeQuery()))->toStartWith('SELECT COUNT(o.id) as qtd FROM psf_test.fake_users AS o');

    $b2 = ($this->builder)();
    $b2->setAlias('o');
    $b2->addInnerJoin([P, 'p'], 'p.userId = o.id');
    expect(plain($b2->writeQuery()))->toStartWith('SELECT o.* FROM');
});

// --- where associativo ---

it('compiles associative conditions (equality, IN, IS NULL, empty IN)', function () {
    $b = ($this->builder)();
    $b->addAndWhere(['name' => 'Ana', 'createdAt' => null, 'id' => [1, 2], 'email' => []]);

    $sql = plain($b->writeQuery());
    expect($sql)->toMatch('/WHERE \(fake_users\.name = :\w+ AND fake_users\.created_at IS NULL AND fake_users\.id IN \(:\w+,:\w+\) AND 0=1\)$/');
    expect(array_values($b->getParses()))->toBe(['Ana', 1, 2]);
});

it('keeps the single-key form and turns its null into IS NULL', function () {
    $b = ($this->builder)();
    $b->addAndWhere(['name' => 'Ana']);
    expect(plain($b->writeQuery()))->toMatch('/WHERE fake_users\.name = :\w+$/');

    $b2 = ($this->builder)();
    $b2->addAndWhere(['name' => null]);
    expect(plain($b2->writeQuery()))->toEndWith('WHERE fake_users.name IS NULL');
});

// --- Model::col ---

it('Model::col builds a validated reference', function () {
    expect(FakeUserModel::col('createdAt'))->toBe(U . '.createdAt');
    expect(FakeUserModel::col('createdAt', 'u'))->toBe('u.createdAt');
    expect(fn() => FakeUserModel::col('createdAtt'))->toThrow(InvalidArgumentException::class, 'createdAtt');
});

// --- modo estrito ---

it('strict mode rejects what looks like a reference but does not resolve', function (callable $apply, string $message) {
    $b = ($this->builder)();
    $b->setStrict(true);

    expect(fn() => $apply($b))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown class (where array)' => [function ($b) { $b->addAndWhere(['App\\Nope\\Order.id', '=', 1]); $b->writeQuery(); }, 'não encontrada'],
    'unknown class (string)'      => [function ($b) { $b->addAndWhere('App\\Nope\\Order.id = 1'); $b->writeQuery(); }, 'não encontrada'],
    'unknown prop (array)'        => [function ($b) { $b->addAndWhere([U . '.idd', '=', 1]); $b->writeQuery(); }, "'idd'"],
    'unknown prop (string)'       => [function ($b) { $b->addAndWhere(U . '.idd = 1'); $b->writeQuery(); }, "'idd'"],
    'unknown bare prop'           => [function ($b) { $b->addAndWhere(['idd', '=', 1]); $b->writeQuery(); }, "'idd'"],
    'ambiguous class reference'   => [function ($b) {
        $b->addInnerJoin([U, 'boss'], 'boss.id = 1');
        $b->addAndWhere([U . '.id', '=', 1]);
        $b->writeQuery();
    }, 'ambígua'],
]);

it('non-strict mode keeps the permissive fallbacks', function () {
    $b = ($this->builder)();
    $b->addAndWhere(['legacy_col', '=', 1]);
    $b->addAndWhere('Nope\\Thing.x = 2');

    expect(plain($b->writeQuery()))->toContain('fake_users.legacy_col = :')->toContain('Nope\\Thing.x = 2');
});

it('strict mode flags a name that is a property and also another property\'s column', function () {
    $strict = new QueryBuilder(FakeAmbiguousModel::class, null, \PSF::getConfig()->db['default']);
    $strict->setStrict(true);
    $strict->addAndWhere(['status', '=', 1]);

    expect(fn() => $strict->writeQuery())->toThrow(InvalidArgumentException::class, 'ambígua');

    // Fora do modo estrito: propriedade primeiro (status → status_id); a coluna literal fica acessível por tabela.coluna... só que
    // tabela.coluna também passa pelo mapa; o jeito de pedir a coluna `status` é pela propriedade que a mapeia.
    $lenient = new QueryBuilder(FakeAmbiguousModel::class, null, \PSF::getConfig()->db['default']);
    $lenient->addAndWhere(['status', '=', 1]);
    $lenient->addAndWhere(['legacyStatus', '=', 'x']);

    $sql = plain($lenient->writeQuery());
    expect($sql)->toContain('fake_ambiguous.status_id = :')->toContain('fake_ambiguous.status = :');
});

it('enables strict mode from settings.strictQueries', function () {
    $config = sys_get_temp_dir() . '/psf-strict-' . uniqid() . '.php';
    file_put_contents($config, "<?php return ['db' => " . var_export(['default' => ['hostname' => 'x', 'database' => 'psf_test', 'username' => 'x', 'password' => '']], true) . ", 'settings' => ['strictQueries' => true]];");

    try {
        \PSF::init(['config' => $config]);
        $b = new QueryBuilder(U, null, \PSF::getConfig()->db['default']);
        $b->addAndWhere(['idd', '=', 1]);

        expect(fn() => $b->writeQuery())->toThrow(InvalidArgumentException::class);
    } finally {
        \PSF::init(['config' => dirname(__DIR__, 2) . '/Fixtures/config.php']);
        @unlink($config);
    }
});

// --- resolver isolado ---

it('resolves tiers in order: alias, class, short name, table', function () {
    $r = new ReferenceResolver(U);
    $r->register(P, 'p');

    expect($r->entityFor('p')['class'])->toBe(P);
    expect($r->entityFor(P)['alias'])->toBe('p');
    expect($r->entityFor('FakeProductModel')['alias'])->toBe('p');
    expect($r->entityFor('fake_users')['class'])->toBe(U);
    expect($r->entityFor('desconhecido'))->toBeNull();
    expect($r->entityFor(null)['class'])->toBe(U);
});
