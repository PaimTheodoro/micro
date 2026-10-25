<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\AutoMigrator;
use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Monitor;
use Psf\Monitor\Pruner;
use Psf\Monitor\Sinks\DatabaseSink;

/** PDO direto no arquivo SQLite do teste. */
function externalPdo(string $sqlite): PDO
{
    $pdo = new PDO('sqlite:' . $sqlite);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function externalCount(string $sqlite, string $table): int
{
    return (int) externalPdo($sqlite)->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

/** @return array<int, string> */
function externalTables(string $sqlite): array
{
    return externalPdo($sqlite)->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
}

/** Linhas do error_log redirecionado que vêm do monitor. */
function externalLogLines(): array
{
    $raw = @file_get_contents(test()->log);
    return $raw === false ? [] : array_values(array_filter(explode("\n", $raw), fn ($l) => str_contains($l, '[PSF][monitor]')));
}

/** Instala o esquema completo e remove a tabela da fase 4, simulando um projeto na fase 3. */
function externalPhase3Schema(string $sqlite): void
{
    (new Installer())->install();
    $pdo = externalPdo($sqlite);
    $pdo->exec('DROP TABLE psf_monitor_external');
    $pdo->exec('DELETE FROM ' . Installer::MIGRATION_TABLE . ' WHERE version = 20261026000001');
}

/** Linha válida de psf_monitor_external. */
function externalRow(int $i = 0, ?int $status = 200): array
{
    return [
        'request_id' => 'req-' . $i, 'ts' => '2026-10-24 12:00:00', 'kind' => 'http',
        'target' => 'api.exemplo.com', 'duration_ms' => 12.5, 'status' => $status, 'sample_weight' => 1.0,
    ];
}

/** Linha válida de psf_monitor_requests. */
function externalRequestRow(int $i = 0): array
{
    return [
        'request_id' => 'req-' . $i, 'ts' => '2026-10-24 12:00:00', 'method' => 'GET', 'route' => '/x',
        'version' => 1, 'status' => 200, 'duration_ms' => 1.0, 'memory_kb' => 1, 'env' => 'dev',
        'dropped_events' => 0, 'sample_weight' => 1.0,
    ];
}

/** Reinicia o monitor com o breaker de teste (e o migrator, se dado) e faz o boot. */
function externalBoot(?AutoMigrator $migrator = null): void
{
    Monitor::reset();
    test()->breaker ??= new CircuitBreaker('test', 3, 300, null, test()->dir);
    Monitor::useBreaker(test()->breaker);
    Monitor::useFatalProvider(fn () => null);
    Monitor::useFinisher(function () {
    });
    if ($migrator !== null) {
        Monitor::useAutoMigrator($migrator);
    }
    Monitor::boot('GET');
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-monitor-extschema-' . uniqid('', true);
    mkdir($this->dir);
    $this->log = $this->dir . '/error.log';
    $this->previousLog = ini_set('error_log', $this->log);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    $this->breaker = null;
});

afterEach(function () {
    ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
    $_SERVER = $this->server;
    foreach (glob($this->dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('has the contracted columns and indexes', function () {
    $sqlite = monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $pdo = externalPdo($sqlite);

    $columns = $pdo->query('PRAGMA table_info(psf_monitor_external)')->fetchAll(PDO::FETCH_ASSOC);
    expect(array_column($columns, 'name'))->toEqualCanonicalizing([
        'id', 'request_id', 'ts', 'kind', 'target', 'duration_ms', 'status', 'sample_weight',
    ]);
    $notNull = array_column($columns, 'notnull', 'name');
    expect((int) $notNull['status'])->toBe(0);

    $indexes = array_column($pdo->query('PRAGMA index_list(psf_monitor_external)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($indexes)->toContain(
        'idx_psf_monitor_external_request_id',
        'idx_psf_monitor_external_ts',
        'idx_psf_monitor_external_kind_target_ts',
    );
    $kindTarget = array_column($pdo->query('PRAGMA index_info(idx_psf_monitor_external_kind_target_ts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($kindTarget)->toBe(['kind', 'target', 'ts']);

    expect(Installer::TABLES)->toHaveCount(6)->toContain('psf_monitor_external')
        ->and(Pruner::RAW_TABLES)->toContain('psf_monitor_external');
});

it('upgrades a phase-3 install', function () {
    $sqlite = monitorUseConfig(['enabled' => true]);
    $installer = new Installer();
    externalPhase3Schema($sqlite);
    $pdo = externalPdo($sqlite);
    $pdo->exec("INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) "
        . "VALUES ('keep', '2026-10-24 12:00:00', 'GET', '/x', 200, 1, 1, 'dev', 1)");
    $pdo->exec("INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) "
        . "VALUES ('keep', '2026-10-24 12:00:00', 'h', 'SELECT ?', 1, 'default', 1)");

    expect($installer->isInstalled())->toBeFalse();

    $installer->install();

    expect($installer->isInstalled())->toBeTrue();
    expect(externalTables($sqlite))->toContain(...Installer::TABLES);
    expect(externalCount($sqlite, 'psf_monitor_requests'))->toBe(1)
        ->and(externalCount($sqlite, 'psf_monitor_queries'))->toBe(1)
        ->and(externalCount($sqlite, Installer::MIGRATION_TABLE))->toBe(6);
});

it('fails safely on a phase-3 schema without auto_migrate', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0]]);
    externalPhase3Schema($sqlite);

    for ($i = 0; $i < 3; $i++) {
        externalBoot();
        Monitor::external('http', 'api.exemplo.com', 10.0, 200);
        Monitor::flush(200, 0.0);
    }

    expect($this->breaker->isOpen())->toBeTrue();
    $lines = externalLogLines();
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('monitor:install');
    expect(externalCount($sqlite, 'psf_monitor_requests'))->toBe(0);
});

it('fails safely on a phase-3 schema and auto_migrate installs only the new table', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    externalPhase3Schema($sqlite);
    $before = externalPdo($sqlite)->query('SELECT version FROM ' . Installer::MIGRATION_TABLE . ' ORDER BY version')
        ->fetchAll(PDO::FETCH_COLUMN);

    externalBoot(new AutoMigrator(new Installer(), 'dev', $this->dir));
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1');
    Monitor::exception(new RuntimeException('x'));
    Monitor::external('http', 'api.exemplo.com', 10.0, 200);
    Monitor::flush(200, 0.0);

    expect(externalTables($sqlite))->toContain(...Installer::TABLES);
    $after = externalPdo($sqlite)->query('SELECT version FROM ' . Installer::MIGRATION_TABLE . ' ORDER BY version')
        ->fetchAll(PDO::FETCH_COLUMN);
    expect(array_values(array_diff($after, $before)))->toEqual(['20261026000001']);
    expect(externalCount($sqlite, 'psf_monitor_requests'))->toBe(1)
        ->and(externalCount($sqlite, 'psf_monitor_queries'))->toBe(1)
        ->and(externalCount($sqlite, 'psf_monitor_exceptions'))->toBe(1)
        ->and(externalCount($sqlite, 'psf_monitor_external'))->toBe(1);
    expect($this->breaker->isOpen())->toBeFalse();
    expect(externalLogLines())->toBe([]);
});

it('writes external rows in the same transaction as the other tables', function () {
    $sqlite = monitorUseConfig(['enabled' => true]);
    (new Installer())->install();

    (new DatabaseSink())->write(['requests' => [externalRequestRow(1)], 'external' => [externalRow(1), externalRow(1, null)]]);

    $rows = externalPdo($sqlite)->query('SELECT * FROM psf_monitor_external ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['kind'])->toBe('http')
        ->and($rows[0]['target'])->toBe('api.exemplo.com')
        ->and((int) $rows[0]['status'])->toBe(200)
        ->and($rows[1]['status'])->toBeNull();

    $bad = externalRow(2);
    $bad['coluna_inexistente'] = 'x';
    try {
        (new DatabaseSink())->write(['requests' => [externalRequestRow(2)], 'external' => [$bad]]);
        $this->fail('Esperava PDOException');
    } catch (PDOException) {
        // esperado
    }
    expect(externalCount($sqlite, 'psf_monitor_requests'))->toBe(1)
        ->and(externalCount($sqlite, 'psf_monitor_external'))->toBe(2);
});

it('prunes old external rows and keeps the new ones', function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $pdo = Connection::open('default');
    $now = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
    foreach (['-10 days' => 'old', '-1 day' => 'new'] as $mod => $rid) {
        $row = externalRow();
        $row['request_id'] = $rid;
        $row['ts'] = $now->modify($mod)->format('Y-m-d H:i:s');
        (new DatabaseSink())->write(['external' => [$row]]);
    }

    $pruner = new Pruner($pdo, DialectFactory::fromConfig(\PSF::config('db', 'default')), 5000, 0);
    expect($pruner->pruneRaw($now, 7))->toBe(1);
    expect($pdo->query('SELECT request_id FROM psf_monitor_external')->fetchAll(PDO::FETCH_COLUMN))->toBe(['new']);
});
