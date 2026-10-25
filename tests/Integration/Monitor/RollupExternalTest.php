<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Rollup;

function extRollup(PDO $pdo): Rollup
{
    return new Rollup($pdo, DialectFactory::fromConfig(\PSF::config('db', 'default')));
}

function extNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-24 11:06:00', new DateTimeZone('UTC'));
}

function extInsert(PDO $pdo, string $ts, string $kind, string $target, float $ms, ?int $status, float $weight = 1.0): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_external (request_id, ts, kind, target, duration_ms, status, sample_weight) '
        . "VALUES ('x', :ts, :k, :t, :d, :s, :w)"
    )->execute([':ts' => $ts, ':k' => $kind, ':t' => $target, ':d' => $ms, ':s' => $status, ':w' => $weight]);
}

/** @return array<int, array<string, mixed>> */
function extRows(PDO $pdo, string $metric = 'external'): array
{
    return $pdo->query(
        'SELECT bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram, sample_text, last_ts '
        . "FROM psf_monitor_rollups WHERE metric = '{$metric}' ORDER BY metric_key"
    )->fetchAll(PDO::FETCH_ASSOC);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
});

it('aggregates external calls by kind and target', function () {
    extInsert($this->pdo, '2026-10-24 10:05:00', 'http', 'api.exemplo.com', 20, 200, 1.0);
    extInsert($this->pdo, '2026-10-24 10:06:00', 'http', 'api.exemplo.com', 300, null, 4.0);
    extInsert($this->pdo, '2026-10-24 10:07:00', 'http', 'api.exemplo.com', 100, 503, 1.0);
    extInsert($this->pdo, '2026-10-24 10:08:00', 'http', 'api.exemplo.com', 10, 404, 1.0);
    extInsert($this->pdo, '2026-10-24 10:09:00', 'mail', 'smtp.exemplo.com', 50, 200, 2.0);

    extRollup($this->pdo)->run(extNow());

    [$http, $mail] = extRows($this->pdo);
    expect($http['metric_key'])->toBe('http:api.exemplo.com');
    expect((float) $http['hits'])->toBe(7.0);
    expect((float) $http['errors'])->toBe(5.0);
    expect((float) $http['sum_ms'])->toBe(1330.0);
    expect((float) $http['max_ms'])->toBe(300.0);
    expect($http['last_ts'])->toBe('2026-10-24 10:08:00');
    expect($http['sample_text'])->toBeNull();
    expect(array_sum(json_decode($http['histogram'], true)))->toBe(7.0);
    expect($mail['metric_key'])->toBe('mail:smtp.exemplo.com');
    expect((float) $mail['hits'])->toBe(2.0);
    expect((float) $mail['errors'])->toBe(0.0);
    expect((float) $mail['sum_ms'])->toBe(100.0);
});

it('is idempotent when reprocessed', function () {
    extInsert($this->pdo, '2026-10-24 10:05:00', 'http', 'a.com', 20, 200);
    extRollup($this->pdo)->run(extNow());
    $first = extRows($this->pdo);
    extRollup($this->pdo)->run(extNow()->modify('+1 hour'));
    expect(extRows($this->pdo))->toBe($first);
});

it('keeps the other metrics alongside external', function () {
    extInsert($this->pdo, '2026-10-24 10:05:00', 'http', 'a.com', 20, 200);
    $this->pdo->exec(
        "INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) "
        . "VALUES ('q', '2026-10-24 10:05:00', 'H', 'SELECT 1', 5, 'default', 1)"
    );
    extRollup($this->pdo)->run(extNow());
    expect(extRows($this->pdo, 'query'))->toHaveCount(1);
    expect(extRows($this->pdo, 'external'))->toHaveCount(1);
});

it('truncates long keys and merges collisions', function () {
    $a = str_repeat('x', 200) . 'a';
    $b = str_repeat('x', 200) . 'b';
    extInsert($this->pdo, '2026-10-24 10:05:00', 'http', $a, 10, 200, 1.0);
    extInsert($this->pdo, '2026-10-24 10:06:00', 'http', $b, 20, 500, 2.0);

    extRollup($this->pdo)->run(extNow());

    $rows = extRows($this->pdo);
    expect($rows)->toHaveCount(1);
    expect(mb_strlen($rows[0]['metric_key']))->toBe(191);
    expect((float) $rows[0]['hits'])->toBe(3.0);
    expect((float) $rows[0]['errors'])->toBe(2.0);
    expect((float) $rows[0]['sum_ms'])->toBe(50.0);
});
