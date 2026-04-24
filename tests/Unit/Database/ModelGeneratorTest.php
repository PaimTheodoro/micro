<?php

use Psf\Database\Command\ModelGenerator;
use Phinx\Db\Table\Column;

// Helper: cria um Column stub com os valores mais usados nos testes
function makeColumn(
    string $name,
    string $type,
    bool   $null       = true,
    bool   $identity   = false,
    bool   $signed     = true,
    ?int   $limit      = null
): Column {
    $col = new Column();
    $col->setName($name);
    $col->setType($type);
    $col->setNull($null);
    $col->setIdentity($identity);
    $col->setSigned($signed);
    if ($limit !== null) {
        $col->setLimit($limit);
    }
    return $col;
}

// ---------------------------------------------------------------------------
// snakeToCamel
// ---------------------------------------------------------------------------

it('snakeToCamel converts simple snake_case', function () {
    $g = new ModelGenerator();
    expect($g->snakeToCamel('created_at'))->toBe('createdAt');
    expect($g->snakeToCamel('user_id'))->toBe('userId');
    expect($g->snakeToCamel('id'))->toBe('id');
    expect($g->snakeToCamel('first_name_last'))->toBe('firstNameLast');
});

// ---------------------------------------------------------------------------
// mapColumnToPhpType
// ---------------------------------------------------------------------------

it('maps integer Phinx type to PHP int', function () {
    $g = new ModelGenerator();
    expect($g->mapColumnToPhpType(makeColumn('id', 'integer')))->toBe('int');
    expect($g->mapColumnToPhpType(makeColumn('id', 'biginteger')))->toBe('int');
});

it('maps boolean Phinx type to PHP bool', function () {
    $g = new ModelGenerator();
    expect($g->mapColumnToPhpType(makeColumn('active', 'boolean')))->toBe('bool');
});

it('maps tinyinteger with limit 1 to PHP bool', function () {
    $g = new ModelGenerator();
    expect($g->mapColumnToPhpType(makeColumn('flag', 'tinyinteger', limit: 1)))->toBe('bool');
});

it('maps decimal/float to PHP float', function () {
    $g = new ModelGenerator();
    expect($g->mapColumnToPhpType(makeColumn('price', 'decimal')))->toBe('float');
    expect($g->mapColumnToPhpType(makeColumn('weight', 'float')))->toBe('float');
});

it('maps string/text/date types to PHP string', function () {
    $g = new ModelGenerator();
    expect($g->mapColumnToPhpType(makeColumn('name', 'string')))->toBe('string');
    expect($g->mapColumnToPhpType(makeColumn('bio', 'text')))->toBe('string');
    expect($g->mapColumnToPhpType(makeColumn('dob', 'date')))->toBe('string');
    expect($g->mapColumnToPhpType(makeColumn('created', 'datetime')))->toBe('string');
});

// ---------------------------------------------------------------------------
// mapColumnToSqlType
// ---------------------------------------------------------------------------

it('generates INT UNSIGNED NOT NULL AUTO_INCREMENT for identity column', function () {
    $g   = new ModelGenerator();
    $col = makeColumn('id', 'integer', null: false, identity: true, signed: false);
    $sql = $g->mapColumnToSqlType($col);
    expect($sql)->toContain('INT');
    expect($sql)->toContain('UNSIGNED');
    expect($sql)->toContain('NOT NULL');
    expect($sql)->toContain('AUTO_INCREMENT');
});

it('generates VARCHAR(255) NOT NULL for non-null string column', function () {
    $g   = new ModelGenerator();
    $col = makeColumn('email', 'string', null: false, limit: 255);
    $sql = $g->mapColumnToSqlType($col);
    expect($sql)->toBe('VARCHAR(255) NOT NULL');
});

it('generates DATETIME NULL for nullable datetime column', function () {
    $g   = new ModelGenerator();
    $col = makeColumn('created_at', 'datetime', null: true);
    $sql = $g->mapColumnToSqlType($col);
    expect($sql)->toBe('DATETIME NULL');
});

// ---------------------------------------------------------------------------
// generateModelCode — estrutura do código gerado
// ---------------------------------------------------------------------------

it('generated code has correct namespace and class name', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('id', 'integer', null: false, identity: true, signed: false)];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->toContain('namespace App\Models;');
    expect($code)->toContain('class User extends Model');
});

it('generated code has #[Table] attribute with table name', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('id', 'integer', null: false, identity: true, signed: false)];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->toContain("#[Table('users')]");
});

it('generated code includes #[PrimaryKey] for identity column', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('id', 'integer', null: false, identity: true, signed: false)];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->toContain('#[PrimaryKey]');
    expect($code)->toContain("#[Column('id')]");
});

it('generated code adds #[ColumnCreatedDate] for created_at column', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('created_at', 'datetime')];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->toContain('#[ColumnCreatedDate]');
    expect($code)->not->toContain('#[ColumnUpdatedDate]');
});

it('generated code adds #[ColumnUpdatedDate] for updated_at column', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('updated_at', 'datetime')];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->toContain('#[ColumnUpdatedDate]');
    expect($code)->not->toContain('#[ColumnCreatedDate]');
});

it('generated code adds #[Nullable(false)] for NOT NULL columns', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('email', 'string', null: false, limit: 255)];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->toContain('#[Nullable(false)]');
});

it('generated code converts snake_case column to camelCase property', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('user_id', 'integer', null: false)];
    $code = $g->generateModelCode('posts', 'App\\Models\\Post', $cols);

    expect($code)->toContain('$userId');
    expect($code)->not->toContain('$user_id');
});

it('generated code uses PSF Attributes, not Laravel-style properties', function () {
    $g    = new ModelGenerator();
    $cols = [makeColumn('id', 'integer', null: false, identity: true, signed: false)];
    $code = $g->generateModelCode('users', 'App\\Models\\User', $cols);

    expect($code)->not->toContain('protected $table');
    expect($code)->not->toContain('protected $fillable');
    expect($code)->not->toContain('protected $casts');
    expect($code)->toContain('use Psf\Model\Attributes\\{');
});
