<?php

use Psf\Database\Dialect\MySQLDialect;
use Psf\Database\Dialect\PostgreSQLDialect;
use Psf\Database\Dialect\SQLiteDialect;
use Psf\Database\Dialect\SQLServerDialect;
use Psf\Monitor\SqlDate;

it('uses the ISO form with T only on SQL Server', function () {
    expect(SqlDate::bind('2026-10-24 10:00:00', new SQLServerDialect()))->toBe('2026-10-24T10:00:00');
});

it('keeps the space form on the other dialects', function ($dialect) {
    expect(SqlDate::bind('2026-10-24 10:00:00', $dialect))->toBe('2026-10-24 10:00:00');
})->with([
    'mysql'      => fn () => new MySQLDialect(),
    'postgresql' => fn () => new PostgreSQLDialect(),
    'sqlite'     => fn () => new SQLiteDialect(),
]);

it('returns malformed input unchanged', function (string $value) {
    expect(SqlDate::bind($value, new SQLServerDialect()))->toBe($value);
})->with(['', '2026-10-24', '2026-10-24T10:00:00', '2026-10-24 10:00:00.000', "2026-10-24 10:00:00\n", 'lixo']);
