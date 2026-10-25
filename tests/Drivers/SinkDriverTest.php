<?php

use Psf\Monitor\ExceptionDescriber;
use Psf\Monitor\Installer;
use Psf\Monitor\SqlNormalizer;
use Psf\Monitor\Sinks\DatabaseSink;

/*
| DatabaseSink em MySQL e PostgreSQL reais: tipos, limites de tamanho (multibyte),
| NULLs, lotes em chunks e transação única com rollback.
| Mesmo roteiro de tests/Integration/Monitor/{DatabaseSink,ExternalSchema}Test.php.
*/

it('writes every table in one transaction with exact values, NULLs and limits', function (string $driver) {
    driverUse($driver, ['enabled' => true]);
    (new Installer())->install();
    $pdo = driverPdo();

    $route   = '/' . str_repeat('é', 190);                       // 191 caracteres, 381 bytes
    $target  = str_repeat('a', 180) . '.exemplo.c';              // 190
    $target .= 'ç';                                              // 191 caracteres
    $sql     = 'SELECT ' . str_repeat('ção, ', 400);        // 2000 caracteres (multibyte)
    $sql     = mb_substr($sql, 0, SqlNormalizer::MAX_LENGTH);
    $message = str_repeat('😀', ExceptionDescriber::MAX_MESSAGE); // 500 caracteres, 2000 bytes
    $trace   = str_repeat('😀', intdiv(ExceptionDescriber::MAX_TRACE_BYTES, 4)); // 16 KB de 4 bytes
    expect(mb_strlen($route))->toBe(191)->and(mb_strlen($target))->toBe(191)
        ->and(mb_strlen($sql))->toBe(2000)->and(strlen($trace))->toBe(16384);

    (new DatabaseSink())->write([
        'requests'   => [drvRequestRow(['request_id' => 'r1', 'route' => $route, 'user_id' => null, 'sample_weight' => 0.3333, 'duration_ms' => 1234.56])],
        'queries'    => [drvQueryRow(['request_id' => 'r1', 'sql_text' => $sql, 'sample_weight' => 3.0303])],
        'exceptions' => [drvExceptionRow(['request_id' => 'r1', 'message' => $message, 'trace' => $trace, 'sample_weight' => 10.0])],
        'external'   => [
            drvExternalRow(['request_id' => 'r1', 'target' => $target, 'status' => null, 'sample_weight' => 0.0001]),
            drvExternalRow(['request_id' => 'r1', 'status' => 503, 'duration_ms' => 99999999.99]),
        ],
    ]);

    $req = $pdo->query("SELECT * FROM psf_monitor_requests WHERE request_id = 'r1'")->fetch(PDO::FETCH_ASSOC);
    expect($req['route'])->toBe($route)
        ->and($req['user_id'])->toBeNull()
        ->and($req['app_release'])->toBeNull()
        ->and((string) $req['sample_weight'])->toBe('0.3333')
        ->and((string) $req['duration_ms'])->toBe('1234.56')
        ->and((int) $req['status'])->toBe(200);

    expect($pdo->query('SELECT sql_text FROM psf_monitor_queries')->fetchColumn())->toBe($sql);
    expect((string) $pdo->query('SELECT sample_weight FROM psf_monitor_queries')->fetchColumn())->toBe('3.0303');

    $exc = $pdo->query('SELECT message, trace FROM psf_monitor_exceptions')->fetch(PDO::FETCH_ASSOC);
    expect($exc['message'])->toBe($message)->and($exc['trace'])->toBe($trace);

    $ext = $pdo->query('SELECT target, status, sample_weight, duration_ms FROM psf_monitor_external ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect($ext[0]['target'])->toBe($target)
        ->and($ext[0]['status'])->toBeNull()
        ->and((string) $ext[0]['sample_weight'])->toBe('0.0001')
        ->and((int) $ext[1]['status'])->toBe(503)
        ->and((string) $ext[1]['duration_ms'])->toBe('99999999.99');
})->with('drivers');

it('writes a 1200-row batch in chunks', function (string $driver) {
    driverUse($driver, ['enabled' => true]);
    (new Installer())->install();
    $rows = [];
    for ($i = 0; $i < 1200; $i++) {
        $rows[] = drvRequestRow(['request_id' => 'req-' . $i, 'sample_weight' => 1 / 3]);
    }
    expect(count($rows))->toBeGreaterThan(DatabaseSink::rowsPerStatement(13));

    (new DatabaseSink())->write(['requests' => $rows, 'external' => array_map(fn ($i) => drvExternalRow(['request_id' => "req-{$i}"]), range(0, 1199))]);

    $pdo = driverPdo();
    expect(driverCount($pdo, 'psf_monitor_requests'))->toBe(1200)
        ->and(driverCount($pdo, 'psf_monitor_external'))->toBe(1200);
    expect((string) $pdo->query("SELECT sample_weight FROM psf_monitor_requests WHERE request_id = 'req-7'")->fetchColumn())->toBe('0.3333');
})->with('drivers');

it('rolls back the whole batch when a row of a later chunk fails', function (string $driver, string $bad) {
    driverUse($driver, ['enabled' => true]);
    (new Installer())->install();
    $rows = array_map(fn ($i) => drvRequestRow(['request_id' => 'req-' . $i]), range(0, 1199));
    if ($bad === 'not null') {
        $rows[1100]['env'] = null;
    } elseif ($bad === 'unknown column') {
        $rows[1100]['coluna_inexistente'] = 'x';
    } else {
        $rows[1100]['status'] = 'abc';
    }

    $sink = new DatabaseSink();
    expect(fn () => $sink->write([
        'queries'  => [drvQueryRow()],
        'requests' => $rows,
        'external' => [drvExternalRow()],
    ]))->toThrow(PDOException::class);

    $pdo = driverPdo();
    foreach (['psf_monitor_requests', 'psf_monitor_queries', 'psf_monitor_external'] as $table) {
        expect(driverCount($pdo, $table))->toBe(0);
    }

    // A mesma instância continua utilizável depois do rollback (PostgreSQL: transação abortada desfeita).
    $sink->write(['requests' => [drvRequestRow(['request_id' => 'depois'])]]);
    expect(driverCount($pdo, 'psf_monitor_requests'))->toBe(1);
})->with('drivers')->with(['not null', 'unknown column', 'invalid integer']);

it('rejects unknown batch keys and detects the schema', function (string $driver) {
    driverUse($driver, ['enabled' => true]);
    $sink = new DatabaseSink();
    expect($sink->schemaInstalled())->toBeFalse();
    expect(fn () => $sink->write(['foo' => [drvRequestRow()]]))->toThrow(InvalidArgumentException::class);

    (new Installer())->install();
    expect($sink->schemaInstalled())->toBeTrue();
})->with('drivers');
