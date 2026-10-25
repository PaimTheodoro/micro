<?php

use Psf\Enumerators\DBDriver;
use Psf\Monitor\Connection;

$sqlsrv = ['driver' => DBDriver::SQLServer->value, 'host' => 'db', 'database' => 'app'];

it('uses ATTR_TIMEOUT for drivers other than SQL Server', function () {
    [$dsn, $options] = Connection::parameters(['driver' => DBDriver::MySQL->value, 'host' => 'db', 'database' => 'app'], 2);

    expect($dsn)->not->toContain('LoginTimeout')
        ->and($options[\PDO::ATTR_TIMEOUT])->toBe(2)
        ->and($options[\PDO::ATTR_ERRMODE])->toBe(\PDO::ERRMODE_EXCEPTION);
});

it('lets extras override ATTR_TIMEOUT but always forces ERRMODE', function () {
    [, $options] = Connection::parameters([
        'driver' => DBDriver::MySQL->value, 'host' => 'db', 'database' => 'app',
        'extras' => [\PDO::ATTR_TIMEOUT => 9, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT],
    ], 2);

    expect($options[\PDO::ATTR_TIMEOUT])->toBe(9)
        ->and($options[\PDO::ATTR_ERRMODE])->toBe(\PDO::ERRMODE_EXCEPTION);
});

it('uses LoginTimeout in the DSN instead of ATTR_TIMEOUT on SQL Server', function () use ($sqlsrv) {
    [$dsn, $options] = Connection::parameters($sqlsrv, 3);

    expect($dsn)->toBe('sqlsrv:Server=db;Database=app;LoginTimeout=3')
        ->and($options)->not->toHaveKey(\PDO::ATTR_TIMEOUT)
        ->and($options[\PDO::ATTR_ERRMODE])->toBe(\PDO::ERRMODE_EXCEPTION);
});

it('accepts the SQL Server driver as the enum instance', function () use ($sqlsrv) {
    [$dsn, $options] = Connection::parameters(['driver' => DBDriver::SQLServer] + $sqlsrv, 1);

    expect($dsn)->toEndWith(';LoginTimeout=1')
        ->and($options)->not->toHaveKey(\PDO::ATTR_TIMEOUT);
});

it('does not duplicate a LoginTimeout already in the DSN', function () {
    expect(Connection::appendLoginTimeout('sqlsrv:Server=db;Database=app;logintimeout=7', 3))
        ->toBe('sqlsrv:Server=db;Database=app;logintimeout=7')
        ->and(Connection::appendLoginTimeout('sqlsrv:Server=db;Database=app;', 3))
        ->toBe('sqlsrv:Server=db;Database=app;LoginTimeout=3');
});
