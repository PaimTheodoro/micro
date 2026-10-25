<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Pruner;

function prunerNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
}

function prunerInsert(PDO $pdo, DateTimeImmutable $ts, string $id): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES (:r, :ts, :m, :route, 200, 10, 0, 'test', 1)"
    );
    $stmt->execute([':r' => $id, ':ts' => $ts->format('Y-m-d H:i:s'), ':m' => 'GET', ':route' => '/x']);
}

function prunerMake(int $batch = 5000): Pruner
{
    $dialect = DialectFactory::fromConfig(\PSF::config('db', 'default'));
    return new Pruner(Connection::open('default'), $dialect, $batch, 0);
}

/** @return array<int, string> */
function prunerRemaining(PDO $pdo): array
{
    return $pdo->query('SELECT request_id FROM psf_monitor_requests ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
});

it('deletes only rows older than raw_days', function () {
    $now = prunerNow();
    prunerInsert($this->pdo, $now->modify('-10 days'), 'a');
    prunerInsert($this->pdo, $now->modify('-8 days'), 'b');
    prunerInsert($this->pdo, $now->modify('-1 day'), 'c');

    expect(prunerMake()->pruneRaw($now, 7))->toBe(2);
    expect(prunerRemaining($this->pdo))->toBe(['c']);
});

it('deletes in batches', function () {
    $now = prunerNow();
    for ($i = 0; $i < 5; $i++) {
        prunerInsert($this->pdo, $now->modify('-10 days'), "old{$i}");
    }
    prunerInsert($this->pdo, $now->modify('-1 day'), 'new');

    expect(prunerMake(2)->pruneRaw($now, 7))->toBe(5);
    expect(prunerRemaining($this->pdo))->toBe(['new']);
});

it('never deletes past the rollup watermark', function () {
    $now = prunerNow();
    $this->pdo->prepare("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', :v)")
        ->execute([':v' => $now->modify('-9 days')->format('Y-m-d H:i:s')]);
    prunerInsert($this->pdo, $now->modify('-10 days'), 'a');
    prunerInsert($this->pdo, $now->modify('-8 days'), 'b');
    prunerInsert($this->pdo, $now->modify('-1 day'), 'c');

    expect(prunerMake()->pruneRaw($now, 7))->toBe(1);
    expect(prunerRemaining($this->pdo))->toBe(['b', 'c']);
});

it('works without a state table row', function () {
    $now = prunerNow();
    prunerInsert($this->pdo, $now->modify('-8 days'), 'b');

    expect(prunerMake()->pruneRaw($now, 7))->toBe(1);
    expect(prunerRemaining($this->pdo))->toBe([]);
});

it('ignores an invalid watermark and a missing state table', function () {
    $now = prunerNow();
    $this->pdo->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', 'lixo')");
    prunerInsert($this->pdo, $now->modify('-8 days'), 'b');
    expect(prunerMake()->pruneRaw($now, 7))->toBe(1);

    $this->pdo->exec('DROP TABLE psf_monitor_state');
    prunerInsert($this->pdo, $now->modify('-8 days'), 'c');
    expect(prunerMake()->pruneRaw($now, 7))->toBe(1);
});

it('rejects raw_days below 1 without deleting anything', function (int $days) {
    $now = prunerNow();
    prunerInsert($this->pdo, $now->modify('-10 days'), 'a');

    expect(fn () => prunerMake()->pruneRaw($now, $days))->toThrow(InvalidArgumentException::class);
    expect(prunerRemaining($this->pdo))->toBe(['a']);
})->with([0, -3]);

it('prunes queries and exceptions older than the cutoff', function () {
    $now = prunerNow();
    prunerInsert($this->pdo, $now->modify('-10 days'), 'old');
    prunerInsert($this->pdo, $now->modify('-1 day'), 'new');
    foreach (['-10 days' => 'old', '-1 day' => 'new'] as $mod => $rid) {
        $ts = $now->modify($mod)->format('Y-m-d H:i:s');
        $this->pdo->exec("INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) "
            . "VALUES ('{$rid}', '{$ts}', 'h', 'SELECT ?', 1, 'default', 1)");
        $this->pdo->exec("INSERT INTO psf_monitor_exceptions (request_id, ts, exception_class, message, file, line_no, trace_hash, trace, sample_weight) "
            . "VALUES ('{$rid}', '{$ts}', 'E', 'm', 'f', 1, 'h', 't', 1)");
    }

    expect(prunerMake()->pruneRaw($now, 7))->toBe(3);
    foreach (['requests', 'queries', 'exceptions'] as $k) {
        expect($this->pdo->query("SELECT request_id FROM psf_monitor_{$k}")->fetchAll(PDO::FETCH_COLUMN))->toBe(['new']);
    }
});

function prunerInsertRollup(PDO $pdo, DateTimeImmutable $hour, string $key): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_rollups (bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram) '
        . "VALUES (:h, 'route', :k, 1, 0, 1, 1, '[]')"
    )->execute([':h' => $hour->format('Y-m-d H:i:s'), ':k' => $key]);
}

it('prunes aggregates older than aggregate_days', function () {
    $now = prunerNow();
    prunerInsertRollup($this->pdo, $now->modify('-100 days'), 'a');
    prunerInsertRollup($this->pdo, $now->modify('-91 days'), 'b');
    prunerInsertRollup($this->pdo, $now->modify('-89 days'), 'c');

    expect(prunerMake()->pruneAggregates($now, 90))->toBe(2);
    expect($this->pdo->query('SELECT metric_key FROM psf_monitor_rollups')->fetchAll(PDO::FETCH_COLUMN))->toBe(['c']);
});

it('rejects aggregate_days below 1', function (int $days) {
    $now = prunerNow();
    prunerInsertRollup($this->pdo, $now->modify('-100 days'), 'a');

    expect(fn () => prunerMake()->pruneAggregates($now, $days))->toThrow(InvalidArgumentException::class);
    expect((int) $this->pdo->query('SELECT COUNT(*) FROM psf_monitor_rollups')->fetchColumn())->toBe(1);
})->with([0, -3]);

it('does not let a stuck rollup grow the raw tables without bound', function (?string $watermark, array $kept) {
    $now = prunerNow();
    if ($watermark !== null) {
        $this->pdo->prepare("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', :v)")
            ->execute([':v' => $now->modify($watermark)->format('Y-m-d H:i:s')]);
    }
    foreach (['-40 days' => 'd40', '-20 days' => 'd20', '-8 days' => 'd8', '-1 day' => 'd1'] as $mod => $id) {
        prunerInsert($this->pdo, $now->modify($mod), $id);
    }

    prunerMake()->pruneRaw($now, 7);
    expect(prunerRemaining($this->pdo))->toBe($kept);
})->with([
    'watermark behind the ceiling' => ['-60 days', ['d20', 'd8', 'd1']],
    'watermark above the ceiling'  => ['-10 days', ['d8', 'd1']],
    'no watermark'                 => [null, ['d1']],
]);

it('rejects a raw_days above the ceiling before any query', function () {
    monitorUseConfig(['enabled' => true]);

    expect(fn () => prunerMake()->pruneRaw(prunerNow(), 36501))->toThrow(InvalidArgumentException::class, 'raw_days inválido');
    expect(fn () => prunerMake()->pruneRaw(prunerNow(), PHP_INT_MAX))->toThrow(InvalidArgumentException::class);
});

it('propagates a watermark read error without deleting anything', function () {
    $now = prunerNow();
    $this->pdo->prepare("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', :v)")
        ->execute([':v' => $now->modify('-9 days')->format('Y-m-d H:i:s')]);
    prunerInsert($this->pdo, $now->modify('-8 days'), 'b');

    $dialect = DialectFactory::fromConfig(\PSF::config('db', 'default'));
    $pruner  = new Pruner(monitorFailingPdo('psf_monitor_state'), $dialect, 5000, 0);

    expect(fn () => $pruner->pruneRaw($now, 7))->toThrow(PDOException::class, 'gone away');
    expect(prunerRemaining($this->pdo))->toBe(['b']);
});
