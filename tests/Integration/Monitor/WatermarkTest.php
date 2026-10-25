<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Watermark;

function watermarkMake(): Watermark
{
    $dialect = DialectFactory::fromConfig(\PSF::config('db', 'default'));
    return new Watermark(Connection::open('default'), $dialect);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
});

it('is null without a row', function () {
    expect(watermarkMake()->read())->toBeNull();
});

it('writes then reads', function () {
    $w = watermarkMake();
    $w->write('2026-01-02 03:00:00');
    expect($w->read())->toBe('2026-01-02 03:00:00');
});

it('overwrites', function () {
    $w = watermarkMake();
    $w->write('2026-01-02 03:00:00');
    $w->write('2026-01-02 04:00:00');

    expect($w->read())->toBe('2026-01-02 04:00:00');
    expect((int) $this->pdo->query('SELECT COUNT(*) FROM psf_monitor_state')->fetchColumn())->toBe(1);
});

it('rejects garbage as null', function (string $value) {
    $this->pdo->prepare("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', :v)")
        ->execute([':v' => $value]);
    expect(watermarkMake()->read())->toBeNull();
})->with(['xyz', '2026-13-45 00:00:00']);

it('is null when the state table is missing', function () {
    $this->pdo->exec('DROP TABLE psf_monitor_state');
    expect(watermarkMake()->read())->toBeNull();
});

it('propagates read errors other than a missing table', function () {
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-01-02 03:00:00')");
    $dialect = DialectFactory::fromConfig(\PSF::config('db', 'default'));
    $w = new Watermark(monitorFailingPdo('psf_monitor_state'), $dialect);

    expect(fn () => $w->read())->toThrow(PDOException::class, 'gone away');
});

it('is null when the table is missing on other drivers', function (string $code, string $message) {
    $dialect = DialectFactory::fromConfig(\PSF::config('db', 'default'));
    $w = new Watermark(monitorFailingPdo('psf_monitor_state', $message, $code), $dialect);

    expect($w->read())->toBeNull();
})->with([
    'mysql'      => ['42S02', "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.psf_monitor_state' doesn't exist"],
    'postgresql' => ['42P01', 'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "psf_monitor_state" does not exist'],
    'sqlserver'  => ['42S02', "SQLSTATE[42S02]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name 'psf_monitor_state'."],
    'sqlite'     => ['HY000', 'SQLSTATE[HY000]: General error: 1 no such table: psf_monitor_state'],
]);
