<?php

use Psf\Database\Command\TableAnalyzer;
use Psf\Model\MetadataCache;
use Tests\Fixtures\Models\FakeUserModel;
use Tests\Fixtures\Models\FakeProductModel;

beforeEach(fn () => MetadataCache::clearCache());

// Usa reflection para chamar getModelColumns() que é private
function callGetModelColumns(TableAnalyzer $analyzer, string $modelClass): array
{
    $method = new ReflectionMethod($analyzer, 'getModelColumns');
    $method->setAccessible(true);
    return $method->invoke($analyzer, $modelClass);
}

// ---------------------------------------------------------------------------
// getModelColumns — usa MetadataCache, não propriedades públicas brutas
// ---------------------------------------------------------------------------

it('getModelColumns returns columns indexed by DB column name (not PHP property)', function () {
    $columns = callGetModelColumns(new TableAnalyzer(), FakeUserModel::class);

    // Chaves devem ser nomes de coluna do banco
    expect($columns)->toHaveKey('id');
    expect($columns)->toHaveKey('name');
    expect($columns)->toHaveKey('email');
    expect($columns)->toHaveKey('created_at');

    // NÃO devem aparecer nomes de propriedade PHP
    expect($columns)->not->toHaveKey('createdAt');
});

it('getModelColumns includes the PHP property name in each entry', function () {
    $columns = callGetModelColumns(new TableAnalyzer(), FakeUserModel::class);

    expect($columns['created_at']['property'])->toBe('createdAt');
    expect($columns['id']['property'])->toBe('id');
});

it('getModelColumns includes PHP type from type hint', function () {
    $columns = callGetModelColumns(new TableAnalyzer(), FakeUserModel::class);

    // FakeUserModel::$id é ?int
    expect($columns['id']['type'])->toBe('int');
    // FakeUserModel::$name é ?string
    expect($columns['name']['type'])->toBe('string');
});

it('getModelColumns maps snake_case columns from FakeProductModel correctly', function () {
    $columns = callGetModelColumns(new TableAnalyzer(), FakeProductModel::class);

    expect($columns)->toHaveKey('user_id');
    expect($columns)->toHaveKey('updated_at');
    expect($columns['user_id']['property'])->toBe('userId');
    expect($columns['updated_at']['property'])->toBe('updatedAt');
});

it('getModelColumns throws for non-existent class', function () {
    callGetModelColumns(new TableAnalyzer(), 'NonExistent\\Model');
})->throws(\Exception::class);

it('getModelColumns throws for class with no #[Column] attributes', function () {
    $plain = new class extends \Psf\Model\Model {
        public string $foo = 'bar';
    };
    callGetModelColumns(new TableAnalyzer(), $plain::class);
})->throws(\Exception::class, 'não possui #[Column] attributes');
