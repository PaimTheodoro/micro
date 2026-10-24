<?php

use Psf\Model\MetadataCache;
use Psf\Model\QueryBuilder;
use Tests\Fixtures\Models\FakeSoftDeleteModel;
use Tests\Fixtures\Models\FakeUserModel;

beforeEach(function () {
    MetadataCache::clearCache();
    $this->makeBuilder = fn(string $class = FakeUserModel::class) =>
        new QueryBuilder($class, null, \PSF::getConfig()->db['default']);
});

// --- WHERE / OR / soft delete ---

it('keeps the plain AND-only format unchanged', function () {
    $qb = ($this->makeBuilder)();
    $qb->addAndWhere([FakeUserModel::class . '.name', '=', 'a']);
    $qb->addAndWhere([FakeUserModel::class . '.email', '=', 'b']);

    expect($qb->writeQuery())->toMatch('/WHERE `fake_users`\.`name` = :\w+ AND `fake_users`\.`email` = :\w+$/');
});

it('groups where/orWhere and applies the soft delete filter outside the OR', function () {
    $qb = ($this->makeBuilder)(FakeSoftDeleteModel::class);
    $qb->addAndWhere([FakeSoftDeleteModel::class . '.title', '=', 'a']);
    $qb->addOrWhere([FakeSoftDeleteModel::class . '.title', '=', 'b']);
    $qb->addSoftWhere('deleted_date', [FakeSoftDeleteModel::class . '.deletedAt', 'IS NULL']);

    expect($qb->writeQuery())->toMatch(
        '/WHERE \(\(`fake_notes`\.`title` = :\w+\) OR `fake_notes`\.`title` = :\w+\) AND `fake_notes`\.`deleted_at` IS NULL$/'
    );
});

it('applies only orWhere conditions instead of dropping the WHERE clause', function () {
    $qb = ($this->makeBuilder)();
    $qb->addOrWhere([FakeUserModel::class . '.name', '=', 'a']);
    $qb->addOrWhere([FakeUserModel::class . '.name', '=', 'b']);

    expect($qb->writeQuery())->toMatch('/WHERE \(`fake_users`\.`name` = :\w+ OR `fake_users`\.`name` = :\w+\)$/');
});

it('does not duplicate the soft delete filter when added twice', function () {
    $qb = ($this->makeBuilder)(FakeSoftDeleteModel::class);
    $qb->addSoftWhere('deleted_date', [FakeSoftDeleteModel::class . '.deletedAt', 'IS NULL']);
    $qb->addSoftWhere('deleted_date', [FakeSoftDeleteModel::class . '.deletedAt', 'IS NULL']);

    expect(substr_count($qb->writeQuery(), 'IS NULL'))->toBe(1);
});

it('compiles IS NULL with a third element without a dangling placeholder', function () {
    $qb = ($this->makeBuilder)();
    $qb->addAndWhere([FakeUserModel::class . '.name', 'IS NULL', null]);

    expect($qb->writeQuery())->toEndWith('`fake_users`.`name` IS NULL');
    expect($qb->getParses())->toBeFalse();
});

it('accepts >=, <=, != and BETWEEN operators', function (string $op) {
    $qb = ($this->makeBuilder)();
    $qb->addAndWhere([FakeUserModel::class . '.id', $op, 5]);

    expect($qb->writeQuery())->toMatch('/`fake_users`\.`id` ' . preg_quote($op, '/') . ' :\w+$/');
})->with(['>=', '<=', '!=']);

it('compiles BETWEEN with two bound values', function () {
    $qb = ($this->makeBuilder)();
    $qb->addAndWhere([FakeUserModel::class . '.id', 'BETWEEN', [1, 9]]);

    expect($qb->writeQuery())->toMatch('/`fake_users`\.`id` BETWEEN :\w+ AND :\w+$/');
    expect(array_values($qb->getParses()))->toBe([1, 9]);
});

it('throws on an invalid condition instead of silently dropping the filter', function (array $condition) {
    $qb = ($this->makeBuilder)();

    expect(fn() => $qb->addAndWhere($condition))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown operator'  => [[FakeUserModel::class . '.id', 'XOR', 1]],
    'operator injection' => [[FakeUserModel::class . '.id', '= 1 OR 1=1 --', 1]],
    'two elements'      => [[FakeUserModel::class . '.id', 1]],
    'BETWEEN not a pair' => [[FakeUserModel::class . '.id', 'BETWEEN', [1]]],
]);

it('rejects statement stacking and comments in raw where strings', function (string $raw) {
    $qb = ($this->makeBuilder)();

    expect(fn() => $qb->addAndWhere($raw))->toThrow(InvalidArgumentException::class);
})->with([
    'semicolon' => ['id = 1; DROP TABLE fake_users'],
    'comment'   => ['id = 1 -- resto'],
    'block'     => ['id = 1 /* x */'],
]);

it('keeps ; and -- inside quoted literals in raw where strings', function () {
    $qb = ($this->makeBuilder)();
    $qb->addAndWhere("name = 'a;b -- c'");

    expect($qb->writeQuery())->toContain("'a;b -- c'");
});

// --- ORDER BY ---

it('orders with a case-insensitive direction', function () {
    $qb = ($this->makeBuilder)();
    $qb->addOrderBy(FakeUserModel::class . '.createdAt', 'desc');

    expect($qb->writeQuery())->toEndWith('ORDER BY `fake_users`.`created_at` DESC');
});

it('accepts a raw order expression only when it is a plain column list', function () {
    $qb = ($this->makeBuilder)();
    $qb->addOrderBy('name DESC, id ASC', null);

    expect($qb->writeQuery())->toEndWith('ORDER BY name DESC, id ASC');
});

it('rejects injection through the ORDER BY field or direction', function (string $field, ?string $type) {
    $qb = ($this->makeBuilder)();

    expect(fn() => $qb->addOrderBy($field, $type))->toThrow(InvalidArgumentException::class);
})->with([
    'direction'     => ['name', 'DESC; DROP TABLE x'],
    'raw field'     => ['name; DROP TABLE x', null],
    'subselect'     => ['(SELECT password FROM users LIMIT 1)', null],
    'function+union' => ['COUNT(1) UNION SELECT 1', null],
]);

// --- generateField ---

it('lets allow-listed functions through and quotes plain identifiers', function () {
    $qb = ($this->makeBuilder)();

    expect($qb->generateField('COUNT(*) AS total'))->toBe('COUNT(*) AS total');
    expect($qb->generateField('count_total'))->toBe('`fake_users`.`count_total`');
    expect($qb->generateField('IFNULL_flag'))->toBe('`fake_users`.`IFNULL_flag`');
    expect($qb->generateField('1'))->toBe('1');
});

it('rejects raw expressions that smuggle other statements', function (string $expr) {
    $qb = ($this->makeBuilder)();

    expect(fn() => $qb->generateField($expr))->toThrow(InvalidArgumentException::class);
})->with([
    'stacked'        => ['COUNT(1); DROP TABLE fake_users'],
    'comment'        => ['COUNT(1) -- x'],
    'subselect call' => ['COALESCE((SELECT password FROM users LIMIT 1), 1)'],
    'unbalanced'     => ['COUNT(1'],
    'leading paren'  => ['(SELECT password FROM users)'],
]);

it('still allows an explicit subquery tuple', function () {
    $qb = ($this->makeBuilder)();

    expect($qb->generateField(['subquery', '(SELECT 1) AS x']))->toBe('(SELECT 1) AS x');
});
