<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Histogram;
use Psf\Monitor\Installer;
use Psf\Monitor\Reader;
use Psf\Monitor\Rollup;

function rdxRollup(PDO $pdo, string $hour, string $key, float $hits, float $sumMs, float $maxMs, float $errors = 0.0, ?array $hist = null, string $metric = 'external'): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_rollups (bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram, sample_text, last_ts) '
        . 'VALUES (:h, :m, :k, :hits, :e, :s, :x, :hist, NULL, NULL)'
    )->execute([
        ':h' => $hour, ':m' => $metric, ':k' => $key, ':hits' => $hits, ':e' => $errors, ':s' => $sumMs, ':x' => $maxMs,
        ':hist' => Histogram::encode($hist ?? Histogram::empty()),
    ]);
}

function rdxRaw(PDO $pdo, string $requestId, string $ts, string $kind, string $target, float $ms, ?int $status, float $weight = 1.0): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_external (request_id, ts, kind, target, duration_ms, status, sample_weight) '
        . 'VALUES (:r, :ts, :k, :t, :d, :s, :w)'
    )->execute([':r' => $requestId, ':ts' => $ts, ':k' => $kind, ':t' => $target, ':d' => $ms, ':s' => $status, ':w' => $weight]);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
    $this->reader = new Reader('default', $this->pdo);
    $this->from = new DateTimeImmutable('2026-10-24 00:00:00', new DateTimeZone('UTC'));
    $this->to = new DateTimeImmutable('2026-10-25 00:00:00', new DateTimeZone('UTC'));
});

function rdxSeed(PDO $pdo): void
{
    rdxRollup($pdo, '2026-10-24 10:00:00', 'http:api.com', 4, 400, 200, 1, [1, 1, 0, 2, 0, 0, 0, 0, 0, 0]);
    rdxRollup($pdo, '2026-10-24 11:00:00', 'http:api.com', 4, 6000, 6000, 0, [0, 0, 0, 0, 3, 0, 0, 0, 0, 1]);
    rdxRollup($pdo, '2026-10-24 10:00:00', 'mail:smtp.com', 10, 5000, 900, 2, [0, 0, 0, 0, 0, 10, 0, 0, 0, 0]);
    rdxRollup($pdo, '2026-10-24 11:00:00', 'http:::1', 100, 100, 5, 5, [100, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    rdxRollup($pdo, '2026-10-24 10:00:00', 'GET x', 999, 99999, 999, 0.0, null, 'request');
}

it('ranks external calls by total time with merged percentiles', function () {
    rdxSeed($this->pdo);

    $items = $this->reader->external($this->from, $this->to);
    expect(array_column($items, 'key'))->toBe(['http:api.com', 'mail:smtp.com', 'http:::1']);

    $a = $items[0];
    $merged = [1, 1, 0, 2, 3, 0, 0, 0, 0, 1];
    expect(array_keys($a))->toBe(['key', 'kind', 'target', 'hits', 'errors', 'errorRate', 'avgMs', 'maxMs', 'p50Ms', 'p95Ms', 'p99Ms']);
    expect($a['kind'])->toBe('http');
    expect($a['target'])->toBe('api.com');
    expect($a['hits'])->toBe(8.0);
    expect($a['errors'])->toBe(1.0);
    expect($a['errorRate'])->toBe(0.125);
    expect($a['avgMs'])->toBe(800.0);
    expect($a['maxMs'])->toBe(6000.0);
    expect($a['p95Ms'])->toBe(Histogram::percentile($merged, 95, 6000.0));
    expect($a['p95Ms'])->toBeGreaterThan(0.0);
});

it('splits kind and target at the first colon only', function () {
    rdxSeed($this->pdo);
    $v6 = $this->reader->external($this->from, $this->to)[2];
    expect($v6['kind'])->toBe('http');
    expect($v6['target'])->toBe('::1');
});

it('respects range, limit and a whitelisted orderBy', function () {
    rdxSeed($this->pdo);

    expect(array_column($this->reader->external($this->from, $this->to, 1), 'key'))->toBe(['http:api.com']);
    expect(array_column($this->reader->external($this->from, $this->to, 0), 'key'))->toHaveCount(1);
    expect(array_column($this->reader->external($this->from, $this->to, 10, 'hits'), 'key')[0])->toBe('http:::1');
    expect(array_column($this->reader->external($this->from, $this->to, 10, 'errors'), 'key')[0])->toBe('http:::1');
    expect(array_column($this->reader->external($this->from, $this->to, 10, 'maxMs'), 'key')[0])->toBe('http:api.com');
    expect(array_column($this->reader->external($this->from, $this->to, 10, 'DROP TABLE x'), 'key')[0])->toBe('http:api.com');

    $only10 = $this->reader->external($this->from, new DateTimeImmutable('2026-10-24 11:00:00', new DateTimeZone('UTC')));
    expect(array_column($only10, 'key'))->toBe(['mail:smtp.com', 'http:api.com']);
    expect($only10[1]['hits'])->toBe(4.0);
});

it('returns an empty list when from >= to', function () {
    rdxSeed($this->pdo);
    expect($this->reader->external($this->to, $this->from))->toBe([]);
    expect($this->reader->external($this->from, $this->from))->toBe([]);
});

it('includes raw external rows in request() ordered by id', function () {
    $this->pdo->exec(
        "INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) "
        . "VALUES ('r1', '2026-10-24 10:00:00', 'GET', 'x', 200, 5, 0, 'test', 1)"
    );
    rdxRaw($this->pdo, 'r1', '2026-10-24 10:00:01', 'http', 'a.com', 10, 200);
    rdxRaw($this->pdo, 'other', '2026-10-24 10:00:01', 'http', 'z.com', 10, 200);
    rdxRaw($this->pdo, 'r1', '2026-10-24 10:00:00', 'mail', 'smtp.com', 20, null);

    $detail = $this->reader->request('r1');
    expect($detail['external'])->toHaveCount(2);
    expect(array_column($detail['external'], 'target'))->toBe(['a.com', 'smtp.com']);
    expect($this->reader->request('inexistente'))->toBeNull();
});

it('reads end to end from Rollup::run', function () {
    rdxRaw($this->pdo, 'r', '2026-10-24 10:05:00', 'http', 'api.com', 20, 200, 1.0);
    rdxRaw($this->pdo, 'r', '2026-10-24 10:06:00', 'http', 'api.com', 100, 503, 3.0);
    $rollup = new Rollup($this->pdo, DialectFactory::fromConfig(\PSF::config('db', 'default')));
    $rollup->run(new DateTimeImmutable('2026-10-24 11:06:00', new DateTimeZone('UTC')));

    $items = $this->reader->external($this->from, $this->to);
    expect($items)->toHaveCount(1);
    expect($items[0]['key'])->toBe('http:api.com');
    expect($items[0]['hits'])->toBe(4.0);
    expect($items[0]['errors'])->toBe(3.0);
    expect($items[0]['avgMs'])->toBe(80.0);
    expect($items[0]['maxMs'])->toBe(100.0);
});
