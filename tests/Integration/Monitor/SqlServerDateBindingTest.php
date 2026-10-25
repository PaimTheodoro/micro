<?php

use Psf\Database\Dialect\SQLServerDialect;
use Psf\Enumerators\DBDriver;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Pruner;
use Psf\Monitor\Reader;
use Psf\Monitor\Rollup;
use Psf\Monitor\Sinks\DatabaseSink;
use Tests\Fixtures\Monitor\SpyStatement;

/*
 * Dialeto SQL Server sobre um PDO SQLite: os colchetes do dialeto funcionam no
 * SQLite, então os statements rodam; o que importa são os valores ligados.
 */

function spyPdo(): PDO
{
    $pdo = new PDO('sqlite:' . \PSF::config('db', 'default', 'database'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SpyStatement::class]);
    return $pdo;
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
    SpyStatement::$calls = [];
});

it('binds the request ts in ISO form on SQL Server in the sink', function () {
    $sink = new DatabaseSink(fn () => spyPdo(), 'default', new SQLServerDialect());
    $sink->write([
        'requests'   => [['request_id' => 'r', 'ts' => '2026-10-24 10:05:00', 'method' => 'GET', 'route' => '/x', 'status' => 200, 'duration_ms' => 1, 'memory_kb' => 0, 'env' => 'test', 'sample_weight' => 1]],
        'queries'    => [['request_id' => 'r', 'ts' => '2026-10-24 10:05:01', 'sql_hash' => 'h', 'sql_text' => 'SELECT 1', 'duration_ms' => 1, 'connection_name' => 'default', 'sample_weight' => 1]],
        'exceptions' => [['request_id' => 'r', 'ts' => '2026-10-24 10:05:02', 'exception_class' => 'E', 'message' => '2026-10-24 10:05:02', 'file' => 'f', 'line_no' => 1, 'trace_hash' => 'h', 'trace' => 't', 'sample_weight' => 1]],
    ]);

    expect(SpyStatement::boundIn('[psf_monitor_requests]'))->toContain('2026-10-24T10:05:00')->not->toContain('2026-10-24 10:05:00');
    expect(SpyStatement::boundIn('[psf_monitor_queries]'))->toContain('2026-10-24T10:05:01');
    // Só a coluna `ts` muda; um texto com cara de data fica como está.
    expect(SpyStatement::boundIn('[psf_monitor_exceptions]'))->toContain('2026-10-24T10:05:02')->toContain('2026-10-24 10:05:02');
});

it('binds the rollup windows and bucket_hour in ISO form on SQL Server', function () {
    $this->pdo->exec("INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) VALUES ('r', '2026-10-24T10:05:00', 'GET', '/x', 200, 1, 0, 'test', 1)");

    (new Rollup(spyPdo(), new SQLServerDialect()))->run(new DateTimeImmutable('2026-10-24 11:10:00', new DateTimeZone('UTC')));

    $windows = SpyStatement::boundIn('FROM [psf_monitor_requests]');
    expect($windows)->toContain('2026-10-24T10:00:00')->toContain('2026-10-24T11:00:00');
    $inserted = SpyStatement::boundIn('INSERT INTO [psf_monitor_rollups]');
    expect($inserted)->toContain('2026-10-24T10:00:00')->toContain('2026-10-24T10:05:00');
    expect(SpyStatement::boundIn('DELETE FROM [psf_monitor_rollups]'))->toBe(['2026-10-24T10:00:00']);
    // O watermark (texto) continua no formato com espaço.
    expect($this->pdo->query("SELECT value FROM psf_monitor_state")->fetchColumn())->toBe('2026-10-24 11:00:00');
});

it('binds the prune cutoffs in ISO form on SQL Server', function () {
    $pdo = spyPdo();
    $now = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));

    (new Pruner($pdo, new SQLServerDialect(), 5000, 0))->pruneAggregates($now, 90);
    expect(SpyStatement::boundIn('DELETE FROM [psf_monitor_rollups]'))->toBe(['2026-07-26T12:00:00']);

    // `TOP` não existe no SQLite: este PDO o remove para o SELECT do lote rodar aqui.
    SpyStatement::$calls = [];
    $raw = new class ('sqlite:' . \PSF::config('db', 'default', 'database')) extends PDO {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return parent::prepare(str_replace('SELECT TOP 5000 ', 'SELECT ', $query), $options);
        }
    };
    $raw->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $raw->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SpyStatement::class]);
    (new Pruner($raw, new SQLServerDialect(), 5000, 0))->pruneRaw($now, 7);

    $cutoffs = array_values(array_filter(
        SpyStatement::boundIn('< :cutoff'),
        fn ($v) => is_string($v) && str_starts_with($v, '2026-')
    ));
    expect($cutoffs)->not->toBe([]);
    expect(array_unique($cutoffs))->toBe(['2026-10-17T12:00:00']);
});

it('binds the reader range in ISO form on SQL Server', function () {
    monitorUseConfig(['enabled' => true], ['db' => ['mssql' => ['driver' => DBDriver::SQLServer, 'database' => 'x']]]);
    (new Installer())->install();
    SpyStatement::$calls = [];

    $reader = new Reader('mssql', spyPdo());
    $reader->throughput(
        new DateTimeImmutable('2026-10-24 10:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC')),
    );

    expect(SpyStatement::boundIn('[psf_monitor_rollups]'))->toBe(['request', '2026-10-24T10:00:00', '2026-10-24T12:00:00']);
});
