<?php

use Psf\Monitor\Installer;

/** Abre um PDO direto no arquivo SQLite do teste. */
function installerPdo(string $sqlite): PDO
{
    $pdo = new PDO('sqlite:' . $sqlite);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/** @return array<int, string> */
function installerTables(PDO $pdo): array
{
    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
}

beforeEach(function () {
    $this->sqlite = monitorUseConfig(['enabled' => true]);
});

it('creates the six monitor tables', function () {
    $installer = new Installer();
    $installer->install();

    $tables = installerTables(installerPdo($this->sqlite));
    expect($tables)->toContain('psf_monitor_requests', 'psf_monitor_state', 'psf_monitor_rollups', 'psf_monitor_queries', 'psf_monitor_exceptions', 'psf_monitor_external');
    expect($installer->isInstalled())->toBeTrue();
});

it('has the contracted columns and indexes', function () {
    (new Installer())->install();
    $pdo = installerPdo($this->sqlite);

    $columns = $pdo->query('PRAGMA table_info(psf_monitor_requests)')->fetchAll(PDO::FETCH_ASSOC);
    expect(array_column($columns, 'name'))->toEqualCanonicalizing([
        'id', 'request_id', 'ts', 'method', 'route', 'version', 'status', 'duration_ms',
        'memory_kb', 'user_id', 'env', 'app_release', 'dropped_events', 'sample_weight',
    ]);

    $indexes = array_column($pdo->query('PRAGMA index_list(psf_monitor_requests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($indexes)->toContain(
        'idx_psf_monitor_requests_ts',
        'idx_psf_monitor_requests_route_ts',
        'idx_psf_monitor_requests_request_id',
    );

    $rollupPk = [];
    foreach ($pdo->query('PRAGMA table_info(psf_monitor_rollups)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ((int) $col['pk'] > 0) {
            $rollupPk[(int) $col['pk']] = $col['name'];
        }
    }
    ksort($rollupPk);
    expect($rollupPk)->toBe([1 => 'bucket_hour', 2 => 'metric', 3 => 'metric_key']);

    $statePk = array_column(
        array_filter(
            $pdo->query('PRAGMA table_info(psf_monitor_state)')->fetchAll(PDO::FETCH_ASSOC),
            fn ($c) => (int) $c['pk'] > 0
        ),
        'name'
    );
    expect($statePk)->toBe(['name']);
});

it('has the contracted columns and indexes for queries and exceptions', function () {
    (new Installer())->install();
    $pdo = installerPdo($this->sqlite);

    $cols = fn (string $t) => array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $idx  = fn (string $t) => array_column($pdo->query("PRAGMA index_list({$t})")->fetchAll(PDO::FETCH_ASSOC), 'name');

    expect($cols('psf_monitor_queries'))->toEqualCanonicalizing([
        'id', 'request_id', 'ts', 'sql_hash', 'sql_text', 'duration_ms', 'connection_name', 'sample_weight',
    ]);
    expect($idx('psf_monitor_queries'))->toContain(
        'idx_psf_monitor_queries_request_id',
        'idx_psf_monitor_queries_hash_ts',
        'idx_psf_monitor_queries_ts',
    );

    expect($cols('psf_monitor_exceptions'))->toEqualCanonicalizing([
        'id', 'request_id', 'ts', 'exception_class', 'message', 'file', 'line_no', 'trace_hash', 'trace', 'sample_weight',
    ]);
    expect($idx('psf_monitor_exceptions'))->toContain(
        'idx_psf_monitor_exceptions_ts',
        'idx_psf_monitor_exceptions_hash_ts',
        'idx_psf_monitor_exceptions_request_id',
    );
});

it('creates only the contracted nullable columns as NULL (Phinx 0.16 defaults to NULL)', function () {
    (new Installer())->install();
    $pdo = installerPdo($this->sqlite);

    $nullable = [
        'psf_monitor_requests'   => ['user_id', 'app_release'],
        'psf_monitor_state'      => [],
        'psf_monitor_rollups'    => ['sample_text', 'last_ts'],
        'psf_monitor_queries'    => [],
        'psf_monitor_exceptions' => [],
        'psf_monitor_external'   => ['status'],
    ];
    foreach ($nullable as $table => $expected) {
        $actual = [];
        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ((int) $col['notnull'] === 0 && (int) $col['pk'] === 0) {
                $actual[] = $col['name'];
            }
        }
        expect($actual)->toEqualCanonicalizing($expected);
    }
});

it('upgrades a phase-1 install', function () {
    $installer = new Installer();
    $installer->install();
    $pdo = installerPdo($this->sqlite);
    $pdo->exec("INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) "
        . "VALUES ('keep', '2026-10-24 12:00:00', 'GET', '/x', 200, 1, 1, 'dev', 1)");

    $pdo->exec('DROP TABLE psf_monitor_queries');
    $pdo->exec('DROP TABLE psf_monitor_exceptions');
    $pdo->exec('DELETE FROM psf_monitor_migrations WHERE version IN (20261025000001, 20261025000002)');
    expect($installer->isInstalled())->toBeFalse();

    $installer->install();

    expect($installer->isInstalled())->toBeTrue();
    expect((int) $pdo->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn())->toBe(1);
    expect((int) $pdo->query('SELECT COUNT(*) FROM psf_monitor_migrations')->fetchColumn())->toBe(6);
});

it('is idempotent', function () {
    $installer = new Installer();
    $installer->install();
    $installer->install();

    $count = (int) installerPdo($this->sqlite)->query('SELECT COUNT(*) FROM psf_monitor_migrations')->fetchColumn();
    expect($count)->toBe(6);
});

it('keeps the project phinxlog untouched', function () {
    (new Installer())->install();

    expect(installerTables(installerPdo($this->sqlite)))->not->toContain('phinxlog');
});

it('reports not installed on an empty database', function () {
    expect((new Installer())->isInstalled())->toBeFalse();
});

it('throws when the database key does not exist', function () {
    (new Installer('nao_existe'))->install();
})->throws(RuntimeException::class);

it('exposes an absolute migrations path', function () {
    $path = Installer::migrationsPath();
    expect(is_dir($path))->toBeTrue();
    expect(glob($path . '/*.php'))->toHaveCount(6);
});
