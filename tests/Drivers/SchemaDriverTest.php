<?php

use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\SqlDate;
use Psf\Monitor\Sinks\DatabaseSink;

/*
| Instalação (migrations reais pelo Phinx), conexão dedicada e SqlDate em
| MySQL e PostgreSQL reais. Mesmo roteiro de tests/Integration/Monitor/{Installer,ExternalSchema}Test.php.
*/

it('installs the six tables with the contracted columns, types and indexes', function (string $driver) {
    driverUse($driver, ['enabled' => true]);
    $installer = new Installer();
    expect($installer->isInstalled())->toBeFalse();

    $installer->install();

    expect($installer->isInstalled())->toBeTrue();
    $pdo = driverPdo();
    expect(driverTables($pdo))->toContain(...Installer::TABLES)
        ->toContain(Installer::MIGRATION_TABLE)
        ->not->toContain('phinxlog');

    $req = driverColumns($pdo, $driver, 'psf_monitor_requests');
    expect(array_keys($req))->toEqualCanonicalizing([
        'id', 'request_id', 'ts', 'method', 'route', 'version', 'status', 'duration_ms',
        'memory_kb', 'user_id', 'env', 'app_release', 'dropped_events', 'sample_weight',
    ]);
    expect($req['id']['type'])->toBe('bigint')
        ->and($req['id']['identity'])->toBeTrue()
        ->and($req['ts']['type'])->toBe($driver === 'mysql' ? 'datetime' : 'timestamp without time zone')
        ->and($req['route']['length'])->toBe(191)
        ->and($req['status']['nullable'])->toBeFalse()
        ->and($req['user_id']['nullable'])->toBeTrue()
        ->and($req['app_release']['nullable'])->toBeTrue()
        ->and([$req['duration_ms']['precision'], $req['duration_ms']['scale']])->toBe([10, 2])
        ->and([$req['sample_weight']['precision'], $req['sample_weight']['scale']])->toBe([12, 4]);
    expect($req['duration_ms']['type'])->toBe($driver === 'mysql' ? 'decimal' : 'numeric');

    $q = driverColumns($pdo, $driver, 'psf_monitor_queries');
    expect(array_keys($q))->toEqualCanonicalizing(['id', 'request_id', 'ts', 'sql_hash', 'sql_text', 'duration_ms', 'connection_name', 'sample_weight']);
    expect($q['sql_text']['type'])->toBe('text')->and($q['id']['type'])->toBe('bigint')->and($q['id']['identity'])->toBeTrue();

    $e = driverColumns($pdo, $driver, 'psf_monitor_exceptions');
    expect(array_keys($e))->toEqualCanonicalizing(['id', 'request_id', 'ts', 'exception_class', 'message', 'file', 'line_no', 'trace_hash', 'trace', 'sample_weight']);
    expect($e['trace']['type'])->toBe('text')->and($e['message']['length'])->toBe(500)->and($e['exception_class']['length'])->toBe(191);

    $x = driverColumns($pdo, $driver, 'psf_monitor_external');
    expect(array_keys($x))->toEqualCanonicalizing(['id', 'request_id', 'ts', 'kind', 'target', 'duration_ms', 'status', 'sample_weight']);
    expect($x['status']['nullable'])->toBeTrue()
        ->and($x['target']['length'])->toBe(191)
        ->and($x['kind']['length'])->toBe(16)
        ->and($x['id']['identity'])->toBeTrue();

    $r = driverColumns($pdo, $driver, 'psf_monitor_rollups');
    expect($r['histogram']['type'])->toBe('text')
        ->and($r['sample_text']['nullable'])->toBeTrue()
        ->and($r['last_ts']['nullable'])->toBeTrue()
        ->and([$r['hits']['precision'], $r['hits']['scale']])->toBe([14, 4])
        ->and([$r['sum_ms']['precision'], $r['sum_ms']['scale']])->toBe([18, 2])
        ->and($r['metric_key']['length'])->toBe(191);

    expect(driverIndexes($pdo, $driver, 'psf_monitor_rollups')['PRIMARY'])->toBe(['bucket_hour', 'metric', 'metric_key']);
    expect(driverIndexes($pdo, $driver, 'psf_monitor_state')['PRIMARY'])->toBe(['name']);

    $expected = [
        'psf_monitor_requests'   => [
            'idx_psf_monitor_requests_ts' => ['ts'],
            'idx_psf_monitor_requests_route_ts' => ['route', 'ts'],
            'idx_psf_monitor_requests_request_id' => ['request_id'],
        ],
        'psf_monitor_queries'    => [
            'idx_psf_monitor_queries_request_id' => ['request_id'],
            'idx_psf_monitor_queries_hash_ts' => ['sql_hash', 'ts'],
            'idx_psf_monitor_queries_ts' => ['ts'],
        ],
        'psf_monitor_exceptions' => [
            'idx_psf_monitor_exceptions_ts' => ['ts'],
            'idx_psf_monitor_exceptions_hash_ts' => ['trace_hash', 'ts'],
            'idx_psf_monitor_exceptions_request_id' => ['request_id'],
        ],
        'psf_monitor_external'   => [
            'idx_psf_monitor_external_request_id' => ['request_id'],
            'idx_psf_monitor_external_ts' => ['ts'],
            'idx_psf_monitor_external_kind_target_ts' => ['kind', 'target', 'ts'],
        ],
    ];
    foreach ($expected as $table => $indexes) {
        $actual = driverIndexes($pdo, $driver, $table);
        expect($actual['PRIMARY'])->toBe(['id']);
        foreach ($indexes as $name => $cols) {
            expect($actual[$name] ?? null)->toBe($cols);
        }
    }
})->with('drivers');

it('is idempotent and upgrades a partial (phase-1 and phase-3) install keeping the rows', function (string $driver) {
    driverUse($driver, ['enabled' => true]);
    $installer = new Installer();
    $installer->install();
    $installer->install();
    $pdo = driverPdo();
    expect(driverCount($pdo, Installer::MIGRATION_TABLE))->toBe(6);

    drvInsert($pdo, 'psf_monitor_requests', drvRequestRow(['request_id' => 'keep']));
    $pdo->exec('DROP TABLE psf_monitor_queries');
    $pdo->exec('DROP TABLE psf_monitor_exceptions');
    $pdo->exec('DROP TABLE psf_monitor_external');
    $pdo->exec('DELETE FROM ' . Installer::MIGRATION_TABLE . ' WHERE version IN (20261025000001, 20261025000002, 20261026000001)');
    expect($installer->isInstalled())->toBeFalse();
    expect((new DatabaseSink())->schemaInstalled())->toBeFalse();

    $installer->install();

    expect($installer->isInstalled())->toBeTrue();
    expect((new DatabaseSink())->schemaInstalled())->toBeTrue();
    expect(driverCount($pdo, 'psf_monitor_requests'))->toBe(1);
    expect(driverCount($pdo, Installer::MIGRATION_TABLE))->toBe(6);
})->with('drivers');

it('opens a dedicated connection with the timeout option accepted by the driver', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'connect_timeout' => 2]);

    $pdo = Connection::open('default');

    expect($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION)
        ->and($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe($driver);
    expect((string) $pdo->query('SELECT 1')->fetchColumn())->toBe('1');
    // O PDO::ATTR_TIMEOUT foi passado ao construtor (e aceito: a conexão abriu).
    [$dsn, $options] = Connection::parameters(\PSF::config('db', 'default'), 2);
    expect($options[PDO::ATTR_TIMEOUT])->toBe(2)->and($dsn)->toStartWith($driver . ':');
    expect(Connection::open('default'))->not->toBe($pdo);
})->with('drivers');

it('binds Y-m-d H:i:s unchanged and compares datetimes correctly', function (string $driver) {
    driverUse($driver, ['enabled' => true]);
    (new Installer())->install();
    $pdo = driverPdo();
    $dialect = driverDialect();

    expect(SqlDate::bind('2026-01-02 03:04:05', $dialect))->toBe('2026-01-02 03:04:05');

    // Dia 02 / mês 01 (seria trocado em linguagem de sessão errada no SQL Server).
    drvInsert($pdo, 'psf_monitor_requests', drvRequestRow(['ts' => SqlDate::bind('2026-01-02 03:04:05', $dialect)]));
    $stmt = $pdo->prepare('SELECT ts FROM psf_monitor_requests WHERE ts >= ? AND ts < ?');
    $stmt->execute([SqlDate::bind('2026-01-02 00:00:00', $dialect), SqlDate::bind('2026-01-03 00:00:00', $dialect)]);
    expect($stmt->fetchAll(PDO::FETCH_COLUMN))->toBe(['2026-01-02 03:04:05']);
})->with('drivers');
