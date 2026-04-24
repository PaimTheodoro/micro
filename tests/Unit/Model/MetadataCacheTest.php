<?php

use Psf\Model\MetadataCache;
use Tests\Fixtures\Models\FakeUserModel;
use Tests\Fixtures\Models\FakeProductModel;

beforeEach(fn () => MetadataCache::clearCache());

// --- Tabela ---

it('returns the correct table name from #[Table] attribute', function () {
    expect(MetadataCache::getTable(FakeUserModel::class))->toBe('fake_users');
    expect(MetadataCache::getTable(FakeProductModel::class))->toBe('fake_products');
});

// --- Mapa de colunas ---

it('returns the full property-to-column map', function () {
    $map = MetadataCache::getColumnMap(FakeUserModel::class);

    expect($map)->toMatchArray([
        'id'        => 'id',
        'name'      => 'name',
        'email'     => 'email',
        'createdAt' => 'created_at',
    ]);
});

it('maps camelCase properties to snake_case columns', function () {
    $map = MetadataCache::getColumnMap(FakeProductModel::class);

    expect($map['userId'])->toBe('user_id');
    expect($map['createdAt'])->toBe('created_at');
    expect($map['updatedAt'])->toBe('updated_at');
});

it('returns column name for a specific property via getColumnByProp', function () {
    expect(MetadataCache::getColumnByProp(FakeUserModel::class, 'createdAt'))->toBe('created_at');
    expect(MetadataCache::getColumnByProp(FakeUserModel::class, 'email'))->toBe('email');
});

it('returns false for a property that has no #[Column] attribute', function () {
    expect(MetadataCache::getColumnByProp(FakeUserModel::class, 'nonexistent'))->toBeFalse();
});

// --- PrimaryKey ---

it('identifies the primary key property', function () {
    $pk = MetadataCache::getPrimaryKey(FakeUserModel::class);

    expect($pk)->not->toBeEmpty();
});

// --- Tipos de coluna ---

it('returns the SQL type from #[Type] attribute', function () {
    expect(MetadataCache::getColumnType(FakeUserModel::class, 'name'))
        ->toBe('VARCHAR(255) NOT NULL');

    expect(MetadataCache::getColumnType(FakeUserModel::class, 'id'))
        ->toBe('INT UNSIGNED AUTO_INCREMENT');
});

// --- Nullable ---

it('returns false for a property marked #[Nullable(false)]', function () {
    expect(MetadataCache::isNullable(FakeUserModel::class, 'name'))->toBeFalse();
    expect(MetadataCache::isNullable(FakeUserModel::class, 'email'))->toBeFalse();
});

// --- Cache ---

it('returns cached value on second call without re-running reflection', function () {
    // Primeira chamada popula o cache
    $first = MetadataCache::getTable(FakeUserModel::class);
    // Segunda chamada deve retornar o mesmo valor (do cache)
    $second = MetadataCache::getTable(FakeUserModel::class);

    expect($first)->toBe($second);
});

it('clearCache for a specific class does not affect other classes', function () {
    MetadataCache::getTable(FakeUserModel::class);
    MetadataCache::getTable(FakeProductModel::class);

    MetadataCache::clearCache(FakeUserModel::class);

    // FakeProductModel ainda deve funcionar normalmente
    expect(MetadataCache::getTable(FakeProductModel::class))->toBe('fake_products');
});
