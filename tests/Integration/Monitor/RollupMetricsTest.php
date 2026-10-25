<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Rollup;

function metricsRollup(PDO $pdo): Rollup
{
    return new Rollup($pdo, DialectFactory::fromConfig(\PSF::config('db', 'default')));
}

function metricsNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-24 11:06:00', new DateTimeZone('UTC'));
}

function metricsQuery(PDO $pdo, string $ts, string $hash, string $text, float $ms, float $weight = 1.0): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) '
        . "VALUES ('q', :ts, :h, :t, :d, 'default', :w)"
    )->execute([':ts' => $ts, ':h' => $hash, ':t' => $text, ':d' => $ms, ':w' => $weight]);
}

function metricsException(PDO $pdo, string $ts, string $class, string $message, float $weight = 1.0): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_exceptions (request_id, ts, exception_class, message, file, line_no, trace_hash, trace, sample_weight) '
        . "VALUES ('e', :ts, :c, :m, 'f.php', 1, 'th', 't', :w)"
    )->execute([':ts' => $ts, ':c' => $class, ':m' => $message, ':w' => $weight]);
}

/** @return array<int, array<string, mixed>> */
function metricsRows(PDO $pdo, ?string $metric = null): array
{
    $sql = 'SELECT bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram, sample_text, last_ts '
        . 'FROM psf_monitor_rollups ' . ($metric !== null ? "WHERE metric = '{$metric}' " : '')
        . 'ORDER BY bucket_hour, metric, metric_key';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
});

it('aggregates queries by sql_hash with a sample text', function () {
    metricsQuery($this->pdo, '2026-10-24 10:05:00', 'A', 'SELECT primeiro', 20, 1.0);
    metricsQuery($this->pdo, '2026-10-24 10:30:00', 'A', 'SELECT segundo', 300, 4.0);
    metricsQuery($this->pdo, '2026-10-24 10:10:00', 'B', 'SELECT b', 5, 1.0);

    metricsRollup($this->pdo)->run(metricsNow());

    [$a, $b] = metricsRows($this->pdo, 'query');
    expect($a['metric_key'])->toBe('A');
    expect((float) $a['hits'])->toBe(5.0);
    expect((float) $a['errors'])->toBe(0.0);
    expect((float) $a['sum_ms'])->toBe(1220.0);
    expect((float) $a['max_ms'])->toBe(300.0);
    expect($a['sample_text'])->toBe('SELECT primeiro');
    expect($a['last_ts'])->toBe('2026-10-24 10:30:00');
    expect(json_decode($a['histogram'], true))->toBe([0.0, 1.0, 0.0, 0.0, 0.0, 4.0, 0.0, 0.0, 0.0, 0.0]);
    expect($b['metric_key'])->toBe('B');
    expect($b['sample_text'])->toBe('SELECT b');
});

it('aggregates exceptions by class', function () {
    metricsException($this->pdo, '2026-10-24 10:01:00', 'RuntimeException', 'primeira', 1);
    metricsException($this->pdo, '2026-10-24 10:02:00', 'RuntimeException', 'segunda', 1);
    metricsException($this->pdo, '2026-10-24 10:50:00', 'RuntimeException', 'terceira', 8);
    metricsException($this->pdo, '2026-10-24 10:03:00', 'PDOException', 'banco', 1);

    metricsRollup($this->pdo)->run(metricsNow());

    [$pdo, $rt] = metricsRows($this->pdo, 'exception');
    expect($pdo['metric_key'])->toBe('PDOException');
    expect((float) $pdo['hits'])->toBe(1.0);
    expect($rt['metric_key'])->toBe('RuntimeException');
    expect((float) $rt['hits'])->toBe(10.0);
    expect((float) $rt['errors'])->toBe(10.0);
    expect((float) $rt['sum_ms'])->toBe(0.0);
    expect((float) $rt['max_ms'])->toBe(0.0);
    expect(json_decode($rt['histogram'], true))->toBe(array_fill(0, 10, 0.0));
    expect($rt['sample_text'])->toBe('primeira');
    expect($rt['last_ts'])->toBe('2026-10-24 10:50:00');
});

it('writes the three metrics for the same hour and replaces all of them on re-run', function () {
    $this->pdo->exec('INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES ('r', '2026-10-24 10:05:00', 'GET', 'x', 200, 40, 0, 'test', 1)");
    metricsQuery($this->pdo, '2026-10-24 10:06:00', 'A', 'SELECT 1', 7);
    metricsException($this->pdo, '2026-10-24 10:07:00', 'E', 'm');

    metricsRollup($this->pdo)->run(metricsNow());
    $first = metricsRows($this->pdo);
    expect(array_column($first, 'metric'))->toBe(['exception', 'query', 'request']);

    $this->pdo->exec('DELETE FROM psf_monitor_state');
    metricsRollup($this->pdo)->run(metricsNow());
    expect(metricsRows($this->pdo))->toBe($first);
});

it('chunks the sample text lookup', function () {
    $this->pdo->beginTransaction();
    for ($i = 0; $i < 1200; $i++) {
        metricsQuery($this->pdo, '2026-10-24 10:05:00', 'h' . $i, 'SELECT ' . $i, 1);
    }
    $this->pdo->commit();

    metricsRollup($this->pdo)->run(metricsNow());

    $rows = metricsRows($this->pdo, 'query');
    expect($rows)->toHaveCount(1200);
    expect(array_filter($rows, fn ($r) => $r['sample_text'] === null))->toBe([]);
});

it('ignores metric rows of other hours', function () {
    metricsQuery($this->pdo, '2026-10-24 09:59:59', 'A', 'antes', 1);
    metricsQuery($this->pdo, '2026-10-24 10:30:00', 'A', 'dentro', 2);
    metricsQuery($this->pdo, '2026-10-24 11:00:00', 'A', 'depois', 3);
    metricsException($this->pdo, '2026-10-24 09:59:59', 'E', 'antes');
    metricsException($this->pdo, '2026-10-24 11:00:00', 'E', 'depois');

    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 10:00:00')");
    metricsRollup($this->pdo)->run(metricsNow());

    // A hora 09 também é reagregada (janela de 2 h antes do watermark); só a hora 10 importa aqui.
    $rows = array_values(array_filter(metricsRows($this->pdo), fn ($r) => $r['bucket_hour'] === '2026-10-24 10:00:00'));
    expect($rows)->toHaveCount(1);
    expect($rows[0]['metric'])->toBe('query');
    expect((float) $rows[0]['hits'])->toBe(1.0);
    expect($rows[0]['sample_text'])->toBe('dentro');
});

it('keeps decimals exact enough', function () {
    metricsQuery($this->pdo, '2026-10-24 10:05:00', 'A', 'x', 1, 3.3333);
    metricsQuery($this->pdo, '2026-10-24 10:06:00', 'A', 'x', 1, 6.6667);

    metricsRollup($this->pdo)->run(metricsNow());

    expect((float) metricsRows($this->pdo, 'query')[0]['hits'])->toEqualWithDelta(10.0, 1e-4);
});
