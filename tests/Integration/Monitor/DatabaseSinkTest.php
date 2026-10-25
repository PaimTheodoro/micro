<?php

use Psf\Database\Connect;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Sinks\DatabaseSink;

/** Linha válida de psf_monitor_requests. */
function sinkRow(int $i = 0): array
{
    return [
        'request_id'     => 'req-' . $i,
        'ts'             => '2026-10-24 12:00:00',
        'method'         => 'GET',
        'route'          => '/usuarios',
        'version'        => 'v1',
        'status'         => 200,
        'duration_ms'    => 12.5,
        'memory_kb'      => 2048,
        'user_id'        => null,
        'env'            => 'dev',
        'app_release'    => null,
        'dropped_events' => 0,
        'sample_weight'  => 1.0,
    ];
}

function sinkCount(string $sqlite): int
{
    $pdo = new PDO('sqlite:' . $sqlite);
    return (int) $pdo->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn();
}

beforeEach(function () {
    $this->sqlite = monitorUseConfig(['enabled' => true]);
});

it('opens a dedicated connection', function () {
    $pdo = Connection::open('default');

    expect($pdo)->toBeInstanceOf(PDO::class);
    expect($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION);
    expect($pdo)->not->toBe(Connect::getConnection('default'));
});

it('throws for an unknown database key', function () {
    Connection::open('inexistente');
})->throws(RuntimeException::class);

it('computes rows per statement', function () {
    expect(DatabaseSink::rowsPerStatement(14))->toBe(142);
    expect(DatabaseSink::rowsPerStatement(1))->toBe(1000);
    expect(DatabaseSink::rowsPerStatement(3000))->toBe(1);
});

it('inserts a request row', function () {
    (new Installer())->install();

    (new DatabaseSink())->write(['requests' => [sinkRow(1)]]);

    $pdo  = new PDO('sqlite:' . $this->sqlite);
    $rows = $pdo->query('SELECT * FROM psf_monitor_requests')->fetchAll(PDO::FETCH_ASSOC);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['request_id'])->toBe('req-1');
    expect($rows[0]['route'])->toBe('/usuarios');
    expect((int) $rows[0]['status'])->toBe(200);
});

it('inserts more rows than fit in one statement', function () {
    (new Installer())->install();
    $rows = array_map('sinkRow', range(1, 1200));

    (new DatabaseSink())->write(['requests' => $rows]);

    expect(sinkCount($this->sqlite))->toBe(1200);
});

it('rolls back everything when a chunk fails', function () {
    (new Installer())->install();
    $rows = array_map('sinkRow', range(0, 1199));
    $rows[1100]['coluna_inexistente'] = 'x';

    try {
        (new DatabaseSink())->write(['requests' => $rows]);
        $this->fail('Esperava PDOException');
    } catch (PDOException) {
        // esperado
    }

    expect(sinkCount($this->sqlite))->toBe(0);
});

it('rejects unknown batch keys', function () {
    (new DatabaseSink())->write(['foo' => [sinkRow()]]);
})->throws(InvalidArgumentException::class);

it('detects the schema', function () {
    $sink = new DatabaseSink();
    expect($sink->schemaInstalled())->toBeFalse();

    (new Installer())->install();
    expect($sink->schemaInstalled())->toBeTrue();
});

/** Linha válida de psf_monitor_queries. */
function sinkQueryRow(int $i = 0): array
{
    return [
        'request_id' => 'req-' . $i, 'ts' => '2026-10-24 12:00:00', 'sql_hash' => str_repeat('a', 40),
        'sql_text' => 'SELECT ?', 'duration_ms' => 1.5, 'connection_name' => 'default', 'sample_weight' => 1.0,
    ];
}

/** Linha válida de psf_monitor_exceptions. */
function sinkExceptionRow(int $i = 0): array
{
    return [
        'request_id' => 'req-' . $i, 'ts' => '2026-10-24 12:00:00', 'exception_class' => 'RuntimeException',
        'message' => 'falhou', 'file' => '/app/x.php', 'line_no' => 10, 'trace_hash' => str_repeat('b', 40),
        'trace' => '#0 {main}', 'sample_weight' => 1.0,
    ];
}

it('inserts queries and exceptions in the same transaction', function () {
    (new Installer())->install();
    $pdo = new PDO('sqlite:' . $this->sqlite);

    (new DatabaseSink())->write([
        'requests' => [sinkRow(1)], 'queries' => [sinkQueryRow(1)], 'exceptions' => [sinkExceptionRow(1)],
    ]);
    foreach (['requests', 'queries', 'exceptions'] as $k) {
        expect((int) $pdo->query("SELECT COUNT(*) FROM psf_monitor_{$k}")->fetchColumn())->toBe(1);
    }

    $bad = sinkExceptionRow(2);
    $bad['coluna_inexistente'] = 'x';
    try {
        (new DatabaseSink())->write([
            'requests' => [sinkRow(2)], 'queries' => [sinkQueryRow(2)], 'exceptions' => [$bad],
        ]);
        $this->fail('Esperava PDOException');
    } catch (PDOException) {
        // esperado
    }
    foreach (['requests', 'queries', 'exceptions'] as $k) {
        expect((int) $pdo->query("SELECT COUNT(*) FROM psf_monitor_{$k}")->fetchColumn())->toBe(1);
    }
});
