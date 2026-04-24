<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Database\Dialect\MySQLDialect;
use Psf\Database\Dialect\SQLServerDialect;
use Psf\Database\Dialect\PostgreSQLDialect;
use Psf\Enumerators\DBDriver;

// ---------------------------------------------------------------------------
// DialectFactory
// ---------------------------------------------------------------------------

it('DialectFactory::make returns the correct dialect for each driver', function () {
    expect(DialectFactory::make(DBDriver::MySQL))->toBeInstanceOf(MySQLDialect::class);
    expect(DialectFactory::make(DBDriver::SQLServer))->toBeInstanceOf(SQLServerDialect::class);
    expect(DialectFactory::make(DBDriver::PostgreSQL))->toBeInstanceOf(PostgreSQLDialect::class);
});

it('DialectFactory::fromConfig falls back to MySQL when driver is missing', function () {
    expect(DialectFactory::fromConfig([]))->toBeInstanceOf(MySQLDialect::class);
});

it('DialectFactory::fromConfig uses driver from config array', function () {
    expect(DialectFactory::fromConfig(['driver' => DBDriver::PostgreSQL]))
        ->toBeInstanceOf(PostgreSQLDialect::class);
});

// ---------------------------------------------------------------------------
// quoteIdentifier
// ---------------------------------------------------------------------------

it('MySQL quoteIdentifier uses backticks', function () {
    $d = new MySQLDialect();
    expect($d->quoteIdentifier('user_id'))->toBe('`user_id`');
    expect($d->quoteIdentifier('*'))->toBe('*');
    expect($d->quoteIdentifier('col`name'))->toBe('`col``name`'); // escaping
});

it('SQLServer quoteIdentifier uses square brackets', function () {
    $d = new SQLServerDialect();
    expect($d->quoteIdentifier('user_id'))->toBe('[user_id]');
    expect($d->quoteIdentifier('*'))->toBe('*');
    expect($d->quoteIdentifier('col]name'))->toBe('[col]]name]'); // escaping
});

it('PostgreSQL quoteIdentifier uses double quotes', function () {
    $d = new PostgreSQLDialect();
    expect($d->quoteIdentifier('user_id'))->toBe('"user_id"');
    expect($d->quoteIdentifier('*'))->toBe('*');
    expect($d->quoteIdentifier('col"name'))->toBe('"col""name"'); // escaping
});

// ---------------------------------------------------------------------------
// quoteTable
// ---------------------------------------------------------------------------

it('MySQL quoteTable without database', function () {
    expect((new MySQLDialect())->quoteTable('users'))->toBe('`users`');
});

it('MySQL quoteTable with database prefix', function () {
    expect((new MySQLDialect())->quoteTable('users', 'myapp'))->toBe('`myapp`.`users`');
});

it('SQLServer quoteTable includes dbo schema', function () {
    expect((new SQLServerDialect())->quoteTable('users', 'myapp'))->toBe('[myapp].[dbo].[users]');
});

it('PostgreSQL quoteTable with database prefix', function () {
    expect((new PostgreSQLDialect())->quoteTable('users', 'myapp'))->toBe('"myapp"."users"');
});

// ---------------------------------------------------------------------------
// topClause / limitOffsetClause
// ---------------------------------------------------------------------------

it('MySQL topClause is always empty', function () {
    expect((new MySQLDialect())->topClause(10, null))->toBe('');
    expect((new MySQLDialect())->topClause(10, 20))->toBe('');
});

it('MySQL limitOffsetClause generates LIMIT and OFFSET', function () {
    $d = new MySQLDialect();
    expect($d->limitOffsetClause(10, null))->toBe(' LIMIT 10');
    expect($d->limitOffsetClause(10, 20))->toBe(' LIMIT 10 OFFSET 20');
    expect($d->limitOffsetClause(null, null))->toBe('');
});

it('PostgreSQL limitOffsetClause is identical to MySQL', function () {
    $d = new PostgreSQLDialect();
    expect($d->limitOffsetClause(5, 15))->toBe(' LIMIT 5 OFFSET 15');
    expect($d->topClause(5, null))->toBe('');
});

it('SQLServer topClause returns TOP n only when there is no offset', function () {
    $d = new SQLServerDialect();
    expect($d->topClause(10, null))->toBe('TOP 10 ');
    expect($d->topClause(10, 20))->toBe(''); // com offset usa OFFSET/FETCH no final
    expect($d->topClause(null, null))->toBe('');
});

it('SQLServer limitOffsetClause returns OFFSET/FETCH when both are set', function () {
    $d = new SQLServerDialect();
    expect($d->limitOffsetClause(10, 20))->toBe(' OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY');
    expect($d->limitOffsetClause(10, null))->toBe(''); // TOP n já foi no início
    expect($d->limitOffsetClause(null, null))->toBe('');
});

// ---------------------------------------------------------------------------
// DSN
// ---------------------------------------------------------------------------

$config = [
    'hostname' => 'localhost',
    'database' => 'testdb',
    'port'     => 3306,
    'username' => 'root',
    'password' => '',
];

it('MySQL buildDsn produces correct DSN string', function () use ($config) {
    $dsn = (new MySQLDialect())->buildDsn($config);
    expect($dsn)->toBe('mysql:host=localhost;dbname=testdb;port=3306;charset=utf8');
});

it('PostgreSQL buildDsn produces correct DSN string', function () use ($config) {
    $cfg = array_merge($config, ['port' => 5432]);
    $dsn = (new PostgreSQLDialect())->buildDsn($cfg);
    expect($dsn)->toBe('pgsql:host=localhost;port=5432;dbname=testdb');
});

it('PostgreSQL buildDsn defaults to port 5432 when not specified', function () use ($config) {
    $cfg = $config;
    unset($cfg['port']);
    $dsn = (new PostgreSQLDialect())->buildDsn($cfg);
    expect($dsn)->toContain('port=5432');
});

it('SQLServer buildDsn produces correct DSN string', function () use ($config) {
    $dsn = (new SQLServerDialect())->buildDsn($config);
    expect($dsn)->toBe('sqlsrv:Server=localhost;Database=testdb');
});

// ---------------------------------------------------------------------------
// autoIncrement
// ---------------------------------------------------------------------------

it('each driver has the correct autoIncrement keyword', function () {
    expect((new MySQLDialect())->autoIncrement())->toBe('AUTO_INCREMENT');
    expect((new SQLServerDialect())->autoIncrement())->toBe('IDENTITY(1,1)');
    expect((new PostgreSQLDialect())->autoIncrement())->toBe('SERIAL');
});

// ---------------------------------------------------------------------------
// modifyColumnSql
// ---------------------------------------------------------------------------

it('MySQL modifyColumnSql uses MODIFY COLUMN', function () {
    $sql = (new MySQLDialect())->modifyColumnSql('email', 'VARCHAR(320) NOT NULL');
    expect($sql)->toBe('MODIFY COLUMN `email` VARCHAR(320) NOT NULL');
});

it('SQLServer modifyColumnSql uses ALTER COLUMN', function () {
    $sql = (new SQLServerDialect())->modifyColumnSql('email', 'NVARCHAR(320) NOT NULL');
    expect($sql)->toBe('ALTER COLUMN [email] NVARCHAR(320) NOT NULL');
});

it('PostgreSQL modifyColumnSql uses ALTER COLUMN ... TYPE', function () {
    $sql = (new PostgreSQLDialect())->modifyColumnSql('email', 'VARCHAR(320) NOT NULL');
    expect($sql)->toBe('ALTER COLUMN "email" TYPE VARCHAR(320) NOT NULL');
});

// ---------------------------------------------------------------------------
// columnsQuery / columnsQueryParams
// ---------------------------------------------------------------------------

it('MySQL columnsQuery embeds table name directly and has no params', function () {
    $d = new MySQLDialect();
    expect($d->columnsQuery('users'))->toContain('`users`');
    expect($d->columnsQueryParams('users'))->toBe([]);
});

it('SQLServer columnsQuery uses :tbl1 and :tbl2 params', function () {
    $d = new SQLServerDialect();
    expect($d->columnsQuery('users'))->toContain(':tbl1');
    expect($d->columnsQuery('users'))->toContain(':tbl2');
    expect($d->columnsQueryParams('orders'))->toBe(['tbl1' => 'orders', 'tbl2' => 'orders']);
});

it('PostgreSQL columnsQuery uses :table1 and :table2 params', function () {
    $d = new PostgreSQLDialect();
    expect($d->columnsQuery('users'))->toContain(':table1');
    expect($d->columnsQuery('users'))->toContain(':table2');
    expect($d->columnsQueryParams('products'))->toBe(['table1' => 'products', 'table2' => 'products']);
});

// ---------------------------------------------------------------------------
// tableOptions
// ---------------------------------------------------------------------------

it('MySQL tableOptions returns ENGINE/CHARSET clause', function () {
    expect((new MySQLDialect())->tableOptions())->toContain('ENGINE=InnoDB');
    expect((new MySQLDialect())->tableOptions())->toContain('utf8mb4');
});

it('SQLServer and PostgreSQL tableOptions return empty string', function () {
    expect((new SQLServerDialect())->tableOptions())->toBe('');
    expect((new PostgreSQLDialect())->tableOptions())->toBe('');
});

// ---------------------------------------------------------------------------
// addColumnSql
// ---------------------------------------------------------------------------

it('MySQL addColumnSql uses ADD COLUMN with backtick', function () {
    expect((new MySQLDialect())->addColumnSql('email', 'VARCHAR(255) NOT NULL'))
        ->toBe('ADD COLUMN `email` VARCHAR(255) NOT NULL');
});

it('SQLServer addColumnSql omits COLUMN keyword', function () {
    expect((new SQLServerDialect())->addColumnSql('email', 'NVARCHAR(255) NOT NULL'))
        ->toBe('ADD [email] NVARCHAR(255) NOT NULL');
});

it('PostgreSQL addColumnSql uses ADD COLUMN with double quotes', function () {
    expect((new PostgreSQLDialect())->addColumnSql('email', 'VARCHAR(255) NOT NULL'))
        ->toBe('ADD COLUMN "email" VARCHAR(255) NOT NULL');
});
