<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Histogram;
use Psf\Monitor\Installer;
use Psf\Monitor\Rollup;

function rollupAt(string $ts): DateTimeImmutable
{
    return new DateTimeImmutable($ts, new DateTimeZone('UTC'));
}

function rollupMake(?PDO $pdo = null): Rollup
{
    $dialect = DialectFactory::fromConfig(\PSF::config('db', 'default'));
    return new Rollup($pdo ?? Connection::open('default'), $dialect);
}

function rollupInsert(
    PDO $pdo,
    string $ts,
    string $route = 'users/{id:int}',
    int $status = 200,
    float $ms = 10.0,
    float $weight = 1.0,
    string $method = 'GET',
): void {
    $pdo->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . 'VALUES (:r, :ts, :m, :route, :s, :d, 0, :env, :w)'
    )->execute([
        ':r' => uniqid('r', true), ':ts' => $ts, ':m' => $method, ':route' => $route,
        ':s' => $status, ':d' => $ms, ':env' => 'test', ':w' => $weight,
    ]);
}

/** @return array<int, array<string, mixed>> */
function rollupRows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram, sample_text, last_ts '
        . 'FROM psf_monitor_rollups ORDER BY bucket_hour, metric, metric_key'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function rollupWatermark(PDO $pdo): string|false
{
    return $pdo->query("SELECT value FROM psf_monitor_state WHERE name = 'rollup_watermark'")->fetchColumn();
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
});

it('aggregates one complete hour weighted by sample_weight', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00', ms: 40, weight: 1.0);
    rollupInsert($this->pdo, '2026-10-24 10:20:00', ms: 700, weight: 10.0);
    rollupInsert($this->pdo, '2026-10-24 10:40:00', status: 500, ms: 3000, weight: 1.0);

    $result = rollupMake()->run(rollupAt('2026-10-24 11:06:00'));

    expect($result)->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    $rows = rollupRows($this->pdo);
    expect($rows)->toHaveCount(1);
    $r = $rows[0];
    expect($r['bucket_hour'])->toBe('2026-10-24 10:00:00');
    expect($r['metric'])->toBe('request');
    expect($r['metric_key'])->toBe('GET users/{id:int}');
    expect((float) $r['hits'])->toBe(12.0);
    expect((float) $r['errors'])->toBe(1.0);
    expect((float) $r['sum_ms'])->toBe(10040.0);
    expect((float) $r['max_ms'])->toBe(3000.0);
    expect($r['last_ts'])->toBe('2026-10-24 10:40:00');
    expect($r['sample_text'])->toBeNull();
    expect(json_decode($r['histogram'], true))->toBe([0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 10.0, 0.0, 1.0, 0.0]);
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 11:00:00');
});

it('groups by method and route and puts edge values in the upper bucket', function () {
    rollupInsert($this->pdo, '2026-10-24 10:01:00', ms: 10, method: 'GET');
    rollupInsert($this->pdo, '2026-10-24 10:02:00', ms: 5000, method: 'GET');
    rollupInsert($this->pdo, '2026-10-24 10:03:00', ms: 9.99, method: 'POST');
    rollupInsert($this->pdo, '2026-10-24 10:04:00', status: 404, ms: 1, method: 'POST');

    expect(rollupMake()->run(rollupAt('2026-10-24 11:06:00'))['rows'])->toBe(2);

    [$get, $post] = rollupRows($this->pdo);
    expect($get['metric_key'])->toBe('GET users/{id:int}');
    expect(json_decode($get['histogram'], true))->toBe([0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0]);
    expect($post['metric_key'])->toBe('POST users/{id:int}');
    expect(json_decode($post['histogram'], true))->toBe([2.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]);
    expect((float) $post['errors'])->toBe(0.0);
});

it('does not process the current hour nor the grace period', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00');

    expect(rollupMake()->run(rollupAt('2026-10-24 11:04:59')))
        ->toBe(['hours' => 0, 'rows' => 0, 'watermark' => null, 'reaggregated' => 0, 'skipped' => 0]);
    expect(rollupRows($this->pdo))->toBe([]);
    expect(rollupWatermark($this->pdo))->toBeFalse();

    expect(rollupMake()->run(rollupAt('2026-10-24 11:05:00')))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
});

it('converts now to UTC', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00');
    // 08:06 em -03:00 = 11:06 UTC.
    $now = new DateTimeImmutable('2026-10-24 08:06:00', new DateTimeZone('America/Sao_Paulo'));

    expect(rollupMake()->run($now)['watermark'])->toBe('2026-10-24 11:00:00');
});

it('is idempotent', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00', ms: 40);
    rollupInsert($this->pdo, '2026-10-24 10:20:00', ms: 700, weight: 10.0);
    $now = rollupAt('2026-10-24 11:06:00');

    rollupMake()->run($now);
    $first = rollupRows($this->pdo);

    $this->pdo->exec('DELETE FROM psf_monitor_state');
    rollupMake()->run($now);
    expect(rollupRows($this->pdo))->toBe($first);

    rollupInsert($this->pdo, '2026-10-24 10:59:58', ms: 3000);
    $this->pdo->exec('DELETE FROM psf_monitor_state');
    expect(rollupMake()->run($now))->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);

    $rows = rollupRows($this->pdo);
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['hits'])->toBe(12.0);
    expect((float) $rows[0]['sum_ms'])->toBe(40.0 + 7000.0 + 3000.0);
    expect($rows[0]['last_ts'])->toBe('2026-10-24 10:59:58');
});

it('keeps requests that cross the hour boundary in the hour of their ts', function () {
    rollupInsert($this->pdo, '2026-10-24 10:59:59', ms: 1);
    rollupInsert($this->pdo, '2026-10-24 11:00:00', ms: 2);

    expect(rollupMake()->run(rollupAt('2026-10-24 12:05:00')))
        ->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 12:00:00', 'reaggregated' => 0, 'skipped' => 0]);

    $rows = rollupRows($this->pdo);
    expect(array_column($rows, 'bucket_hour'))->toBe(['2026-10-24 10:00:00', '2026-10-24 11:00:00']);
    expect(array_map(fn ($r) => (float) $r['sum_ms'], $rows))->toBe([1.0, 2.0]);
});

it('starts at the oldest raw row when there is no watermark and advances per hour', function () {
    rollupInsert($this->pdo, '2026-10-24 10:30:00');
    rollupInsert($this->pdo, '2026-10-24 08:15:00');

    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 3, 'rows' => 2, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    expect(array_column(rollupRows($this->pdo), 'bucket_hour'))
        ->toBe(['2026-10-24 08:00:00', '2026-10-24 10:00:00']);
});

it('considers queries and exceptions for the starting hour', function () {
    rollupInsert($this->pdo, '2026-10-24 10:30:00');
    $this->pdo->exec("INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) "
        . "VALUES ('q', '2026-10-24 09:10:00', 'h', 'SELECT ?', 1, 'default', 1)");
    $this->pdo->exec("INSERT INTO psf_monitor_exceptions (request_id, ts, exception_class, message, file, line_no, trace_hash, trace, sample_weight) "
        . "VALUES ('e', '2026-10-24 08:59:00', 'E', 'm', 'f', 1, 'h', 't', 1)");

    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 3, 'rows' => 3, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
});

it('resumes from an existing watermark', function () {
    // 07:15 fica fora da janela de reagregação (watermark − 2 h = 08:00).
    rollupInsert($this->pdo, '2026-10-24 07:15:00');
    rollupInsert($this->pdo, '2026-10-24 10:30:00');
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 10:00:00')");

    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    expect(array_column(rollupRows($this->pdo), 'bucket_hour'))->toBe(['2026-10-24 10:00:00']);
});

it('caps the work per run and resumes', function () {
    foreach (['06', '07', '08', '09', '10'] as $h) {
        rollupInsert($this->pdo, "2026-10-24 {$h}:30:00");
    }
    $now = rollupAt('2026-10-24 11:10:00');

    expect(rollupMake()->run($now, 2))->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 08:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 08:00:00');

    // As 2 horas já processadas são reagregadas a cada execução, sem contar em maxHours.
    expect(rollupMake()->run($now, 2))->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 10:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    expect(rollupMake()->run($now, 2))->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    expect(rollupMake()->run($now, 2))->toBe(['hours' => 0, 'rows' => 0, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    expect(rollupRows($this->pdo))->toHaveCount(5);
});

it('persists progress when a later hour fails', function () {
    rollupInsert($this->pdo, '2026-10-24 08:30:00');
    rollupInsert($this->pdo, '2026-10-24 09:30:00');
    rollupInsert($this->pdo, '2026-10-24 10:30:00');
    // Registro antigo da hora 09 que a falha não pode apagar (o DELETE dela é desfeito).
    $this->pdo->exec('INSERT INTO psf_monitor_rollups (bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram) '
        . "VALUES ('2026-10-24 09:00:00', 'request', 'OLD x', 1, 0, 1, 1, '[]')");

    // PDO de teste: lança no INSERT em psf_monitor_rollups da 2ª hora processada.
    $failing = new class ('sqlite:' . \PSF::config('db', 'default', 'database')) extends PDO {
        public int $inserts = 0;

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (stripos($query, 'INSERT INTO') !== false && str_contains($query, 'psf_monitor_rollups')
                && ++$this->inserts === 2) {
                throw new PDOException('falha simulada');
            }
            return parent::prepare($query, $options);
        }
    };
    $failing->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    expect(fn () => rollupMake($failing)->run(rollupAt('2026-10-24 11:10:00')))
        ->toThrow(PDOException::class, 'falha simulada');

    expect($failing->inTransaction())->toBeFalse();
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 09:00:00');
    expect(array_column(rollupRows($this->pdo), 'metric_key', 'bucket_hour'))->toBe([
        '2026-10-24 08:00:00' => 'GET users/{id:int}',
        '2026-10-24 09:00:00' => 'OLD x',
    ]);

    // Nova execução sem falha reagrega a hora 08 e continua da hora 09.
    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 1, 'skipped' => 0]);
    expect(rollupRows($this->pdo))->toHaveCount(3);
});

it('handles an hour with no rows and an empty database', function () {
    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 0, 'rows' => 0, 'watermark' => null, 'reaggregated' => 0, 'skipped' => 0]);
    expect(rollupWatermark($this->pdo))->toBeFalse();

    // Com watermark e sem linhas: as horas vazias avançam o watermark.
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 09:00:00')");
    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 2, 'rows' => 0, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    expect(rollupRows($this->pdo))->toBe([]);
});

it('truncates a long metric_key', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00', route: str_repeat('é', 191));

    rollupMake()->run(rollupAt('2026-10-24 11:06:00'));

    $key = rollupRows($this->pdo)[0]['metric_key'];
    expect(mb_strlen($key))->toBe(191);
    expect($key)->toStartWith('GET éé');
});

it('merges groups whose metric_key collides after truncation', function () {
    $base = str_repeat('a', 190);
    rollupInsert($this->pdo, '2026-10-24 10:05:00', route: $base . 'X', ms: 40, weight: 1.0);
    rollupInsert($this->pdo, '2026-10-24 10:10:00', route: $base . 'Y', status: 500, ms: 3000, weight: 2.0);
    rollupInsert($this->pdo, '2026-10-24 10:15:00', route: $base . 'Y', ms: 700, weight: 10.0);

    expect(rollupMake()->run(rollupAt('2026-10-24 11:06:00')))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);

    $rows = rollupRows($this->pdo);
    expect($rows)->toHaveCount(1);
    $r = $rows[0];
    expect($r['metric_key'])->toBe(mb_substr('GET ' . $base, 0, 191));
    expect((float) $r['hits'])->toBe(13.0);
    expect((float) $r['errors'])->toBe(2.0);
    expect((float) $r['sum_ms'])->toBe(40.0 + 6000.0 + 7000.0);
    expect((float) $r['max_ms'])->toBe(3000.0);
    expect($r['last_ts'])->toBe('2026-10-24 10:15:00');
    expect(json_decode($r['histogram'], true))->toBe([0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 10.0, 0.0, 2.0, 0.0]);
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 11:00:00');
});

it('keeps the latest last_ts and the max of max_ms across three colliding routes', function () {
    $base = str_repeat('b', 190);
    rollupInsert($this->pdo, '2026-10-24 10:50:00', route: $base . 'X', ms: 5, weight: 1.0);
    rollupInsert($this->pdo, '2026-10-24 10:05:00', route: $base . 'Y', ms: 9000, weight: 1.0);
    rollupInsert($this->pdo, '2026-10-24 10:20:00', route: $base . 'Z', ms: 30, weight: 3.0);

    expect(rollupMake()->run(rollupAt('2026-10-24 11:06:00'))['rows'])->toBe(1);

    $r = rollupRows($this->pdo)[0];
    expect((float) $r['hits'])->toBe(5.0);
    expect((float) $r['max_ms'])->toBe(9000.0);
    expect($r['last_ts'])->toBe('2026-10-24 10:50:00');
    expect($r['sample_text'])->toBeNull();
    expect(json_decode($r['histogram'], true))->toBe([1.0, 0.0, 3.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0]);
});

it('keeps long routes that differ before the cut as separate rows', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00', route: 'X' . str_repeat('c', 190));
    rollupInsert($this->pdo, '2026-10-24 10:06:00', route: 'Y' . str_repeat('c', 190));
    rollupInsert($this->pdo, '2026-10-24 10:07:00', route: 'short', method: 'POST');

    expect(rollupMake()->run(rollupAt('2026-10-24 11:06:00'))['rows'])->toBe(3);
    expect(array_map(fn ($r) => (float) $r['hits'], rollupRows($this->pdo)))->toBe([1.0, 1.0, 1.0]);
});

it('rejects maxHours below 1', function (int $max) {
    rollupInsert($this->pdo, '2026-10-24 10:05:00');

    expect(fn () => rollupMake()->run(rollupAt('2026-10-24 11:06:00'), $max))
        ->toThrow(InvalidArgumentException::class);
    expect(rollupRows($this->pdo))->toBe([]);
})->with([0, -1]);

it('propagates a watermark read error without writing anything', function () {
    rollupInsert($this->pdo, '2026-10-24 08:30:00');
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 09:00:00')");

    expect(fn () => rollupMake(monitorFailingPdo('SELECT "value" FROM "psf_monitor_state"'))->run(rollupAt('2026-10-24 11:10:00')))
        ->toThrow(PDOException::class, 'gone away');
    expect(rollupRows($this->pdo))->toBe([]);
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 09:00:00');
});

it('picks up a late row of an already aggregated hour on the next run', function () {
    rollupInsert($this->pdo, '2026-10-24 10:30:00', ms: 10);
    expect(rollupMake()->run(rollupAt('2026-10-24 11:06:00'))['hours'])->toBe(1);

    // Request iniciado às 10:58 e gravado depois do rollup das 11:05.
    rollupInsert($this->pdo, '2026-10-24 10:58:00', ms: 30);
    rollupInsert($this->pdo, '2026-10-24 11:20:00', ms: 5);

    expect(rollupMake()->run(rollupAt('2026-10-24 12:06:00')))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 12:00:00', 'reaggregated' => 1, 'skipped' => 0]);

    $rows = rollupRows($this->pdo);
    expect(array_column($rows, 'bucket_hour'))->toBe(['2026-10-24 10:00:00', '2026-10-24 11:00:00']);
    expect((float) $rows[0]['hits'])->toBe(2.0);
    expect((float) $rows[0]['sum_ms'])->toBe(40.0);
    expect($rows[0]['last_ts'])->toBe('2026-10-24 10:58:00');
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 12:00:00');
});

it('loses a late row older than the re-aggregation window (documented limit)', function () {
    rollupInsert($this->pdo, '2026-10-24 08:30:00');
    rollupMake()->run(rollupAt('2026-10-24 11:06:00'));
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 11:00:00');

    // Hora 08 está fora de [watermark − 2 h, watermark) = [09:00, 11:00).
    rollupInsert($this->pdo, '2026-10-24 08:45:00');
    expect(rollupMake()->run(rollupAt('2026-10-24 11:30:00'))['reaggregated'])->toBe(0);

    $rows = rollupRows($this->pdo);
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['hits'])->toBe(1.0);
});

it('does not count the overlap toward maxHours and never moves the watermark back', function () {
    foreach (['07', '08', '09', '10'] as $h) {
        rollupInsert($this->pdo, "2026-10-24 {$h}:30:00");
    }
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 09:00:00')");

    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00'), 1))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 10:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    expect(array_column(rollupRows($this->pdo), 'bucket_hour'))
        ->toBe(['2026-10-24 07:00:00', '2026-10-24 08:00:00', '2026-10-24 09:00:00']);
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 10:00:00');
});

it('does not wipe an overlap hour whose raw rows are gone', function () {
    rollupInsert($this->pdo, '2026-10-24 07:30:00', ms: 5);
    rollupInsert($this->pdo, '2026-10-24 09:30:00', ms: 10);
    rollupInsert($this->pdo, '2026-10-24 10:30:00', ms: 20);
    rollupMake()->run(rollupAt('2026-10-24 11:06:00'));
    $before = rollupRows($this->pdo);
    expect($before)->toHaveCount(3);

    // Brutos da hora 09 apagados; a hora 10 continua com brutos.
    $this->pdo->exec("DELETE FROM psf_monitor_requests WHERE ts >= '2026-10-24 09:00:00' AND ts < '2026-10-24 10:00:00'");

    expect(rollupMake()->run(rollupAt('2026-10-24 11:30:00'))['reaggregated'])->toBe(1);
    expect(rollupRows($this->pdo))->toBe($before);
});

it('does not re-aggregate hours older than one day', function () {
    rollupInsert($this->pdo, '2026-10-22 09:30:00');
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-22 10:00:00')");

    expect(rollupMake()->run(rollupAt('2026-10-24 11:10:00'), 1))
        ->toBe(['hours' => 1, 'rows' => 0, 'watermark' => '2026-10-22 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    expect(rollupRows($this->pdo))->toBe([]);
});

/** PDO que lança `PDOException` com a SQLSTATE dada no INSERT de rollup da 2ª hora processada. */
function rollupIntegrityPdo(string $sqlState): PDO
{
    $pdo = new class ('sqlite:' . \PSF::config('db', 'default', 'database')) extends PDO {
        public int $inserts = 0;
        public string $sqlState = '';

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (stripos($query, 'INSERT INTO') !== false && str_contains($query, 'psf_monitor_rollups')
                && ++$this->inserts === 2) {
                throw monitorPdoException("SQLSTATE[{$this->sqlState}]: falha simulada", $this->sqlState);
            }
            return parent::prepare($query, $options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqlState = $sqlState;
    return $pdo;
}

it('skips an hour that fails with an integrity violation and keeps going', function () {
    foreach (['08', '09', '10'] as $h) {
        rollupInsert($this->pdo, "2026-10-24 {$h}:30:00");
    }
    $pdo = rollupIntegrityPdo('23000');

    expect(rollupMake($pdo)->run(rollupAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 1]);
    expect($pdo->inTransaction())->toBeFalse();
    expect(array_column(rollupRows($this->pdo), 'bucket_hour'))
        ->toBe(['2026-10-24 08:00:00', '2026-10-24 10:00:00']);
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 11:00:00');
    // Brutos da hora pulada ficam até o prune.
    expect((int) $this->pdo->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn())->toBe(3);
});

it('counts a skipped hour toward maxHours', function () {
    foreach (['08', '09', '10'] as $h) {
        rollupInsert($this->pdo, "2026-10-24 {$h}:30:00");
    }

    expect(rollupMake(rollupIntegrityPdo('23505'))->run(rollupAt('2026-10-24 11:10:00'), 2))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 10:00:00', 'reaggregated' => 0, 'skipped' => 1]);
});

it('still propagates non-integrity errors and leaves the watermark before the failing hour', function () {
    foreach (['08', '09', '10'] as $h) {
        rollupInsert($this->pdo, "2026-10-24 {$h}:30:00");
    }
    $pdo = rollupIntegrityPdo('HY000');

    expect(fn () => rollupMake($pdo)->run(rollupAt('2026-10-24 11:10:00')))
        ->toThrow(PDOException::class, 'falha simulada');
    expect($pdo->inTransaction())->toBeFalse();
    expect(rollupWatermark($this->pdo))->toBe('2026-10-24 09:00:00');
    expect(array_column(rollupRows($this->pdo), 'bucket_hour'))->toBe(['2026-10-24 08:00:00']);
});

it('merges keys that differ only in case or trailing spaces', function () {
    rollupInsert($this->pdo, '2026-10-24 10:05:00', route: '/Users', ms: 10);
    rollupInsert($this->pdo, '2026-10-24 10:06:00', route: '/users ', ms: 30);

    expect(rollupMake()->run(rollupAt('2026-10-24 11:06:00'))['rows'])->toBe(1);

    $rows = rollupRows($this->pdo);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['metric_key'])->toBe('GET /Users');
    expect((float) $rows[0]['hits'])->toBe(2.0);
    expect((float) $rows[0]['sum_ms'])->toBe(40.0);
});

it('propagates an integrity violation raised while writing the watermark', function () {
    rollupInsert($this->pdo, '2026-10-24 10:30:00');
    $pdo = monitorFailingPdo('UPDATE "psf_monitor_state"', 'SQLSTATE[23000]: falha no watermark', '23000');

    expect(fn () => rollupMake($pdo)->run(rollupAt('2026-10-24 11:10:00')))
        ->toThrow(PDOException::class, 'falha no watermark');
    expect($pdo->inTransaction())->toBeFalse();
    expect(rollupRows($this->pdo))->toBe([]);
    expect(rollupWatermark($this->pdo))->toBeFalse();
});

/** Invariante: todo bucket_hour gravado é hora cheia. */
function rollupAssertHourAligned(PDO $pdo): void
{
    foreach (array_column(rollupRows($pdo), 'bucket_hour') as $bh) {
        expect(substr((string) $bh, 14))->toBe('00:00');
    }
}

function rollupHitsTotal(PDO $pdo): float
{
    return (float) $pdo->query("SELECT COALESCE(SUM(hits), 0) FROM psf_monitor_rollups WHERE metric = 'request'")->fetchColumn();
}

it('starts the re-aggregation window on a whole hour when the watermark is ~1 day behind', function () {
    foreach (['08', '09', '10', '11', '12'] as $h) {
        rollupInsert($this->pdo, "2026-10-24 {$h}:10:00");
    }
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 11:00:00')");

    $result = rollupMake()->run(rollupAt('2026-10-25 10:30:00'), 1);

    expect($result['reaggregated'])->toBe(0);
    rollupAssertHourAligned($this->pdo);
    expect(array_column(rollupRows($this->pdo), 'bucket_hour'))->toBe(['2026-10-24 11:00:00']);
    expect(rollupHitsTotal($this->pdo))->toBe(1.0);
});

it('never writes a non-hourly bucket when catching up a ~23 h backlog', function () {
    $t = rollupAt('2026-10-24 08:10:00');
    $end = rollupAt('2026-10-25 10:30:00');
    for (; $t < $end; $t = $t->modify('+1 hour')) {
        rollupInsert($this->pdo, $t->format('Y-m-d H:i:s'));
    }
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2026-10-24 11:00:00')");

    $result = rollupMake()->run($end);

    expect($result['watermark'])->toBe('2026-10-25 10:00:00');
    rollupAssertHourAligned($this->pdo);
    $raw = (int) $this->pdo->query(
        "SELECT COUNT(*) FROM psf_monitor_requests WHERE ts >= '2026-10-24 11:00:00' AND ts < '2026-10-25 10:00:00'"
    )->fetchColumn();
    expect(rollupHitsTotal($this->pdo))->toBe((float) $raw);
});

it('keeps the same spelling for colliding keys whatever order the driver returns the groups', function () {
    // A ordem das linhas do GROUP BY varia por driver (o PostgreSQL devolvia `GET /users ` primeiro).
    $merge = new ReflectionMethod(Rollup::class, 'mergeByKey');
    $record = fn (string $key, float $hits) => [
        'metric' => 'request', 'metric_key' => $key, 'hits' => $hits, 'errors' => 0.0, 'sum_ms' => $hits,
        'max_ms' => 1.0, 'histogram' => Histogram::encode(Histogram::empty()), 'sample_text' => null, 'last_ts' => null,
    ];

    foreach ([['GET /users ', 'GET /Users', 'GET /USERS'], ['GET /USERS', 'GET /users ', 'GET /Users']] as $order) {
        $merged = $merge->invoke(rollupMake(), array_map(fn ($k) => $record($k, 1.0), $order));
        expect($merged)->toHaveCount(1);
        expect($merged[0]['metric_key'])->toBe('GET /USERS');
        expect($merged[0]['hits'])->toBe(3.0);
    }
});
