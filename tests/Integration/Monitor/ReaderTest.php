<?php

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Connection;
use Psf\Monitor\Histogram;
use Psf\Monitor\Installer;
use Psf\Monitor\Reader;
use Psf\Monitor\Rollup;

function readerAt(string $ts, string $tz = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($ts, new DateTimeZone($tz));
}

/** Insere um registro de rollup direto na tabela (valores conhecidos). */
function readerRollup(
    PDO $pdo,
    string $hour,
    string $metric,
    string $key,
    float $hits,
    float $sumMs = 0.0,
    float $maxMs = 0.0,
    float $errors = 0.0,
    ?array $histogram = null,
    ?string $sample = null,
    ?string $lastTs = null,
    ?string $rawHistogram = null,
): void {
    $pdo->prepare(
        'INSERT INTO psf_monitor_rollups (bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram, sample_text, last_ts) '
        . 'VALUES (:h, :m, :k, :hits, :e, :s, :x, :hist, :t, :l)'
    )->execute([
        ':h' => $hour, ':m' => $metric, ':k' => $key, ':hits' => $hits, ':e' => $errors,
        ':s' => $sumMs, ':x' => $maxMs,
        ':hist' => $rawHistogram ?? Histogram::encode($histogram ?? Histogram::empty()),
        ':t' => $sample, ':l' => $lastTs,
    ]);
}

function readerRawRequest(PDO $pdo, string $requestId, string $ts, string $route, int $status, float $ms, float $weight = 1.0, string $method = 'GET'): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES (:r, :ts, :m, :route, :s, :d, 0, 'test', :w)"
    )->execute([':r' => $requestId, ':ts' => $ts, ':m' => $method, ':route' => $route, ':s' => $status, ':d' => $ms, ':w' => $weight]);
}

function readerRawQuery(PDO $pdo, string $requestId, string $ts, string $hash, string $text, float $ms, float $weight = 1.0): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) '
        . "VALUES (:r, :ts, :h, :t, :d, 'default', :w)"
    )->execute([':r' => $requestId, ':ts' => $ts, ':h' => $hash, ':t' => $text, ':d' => $ms, ':w' => $weight]);
}

function readerRawException(PDO $pdo, string $requestId, string $ts, string $class, string $message, float $weight = 1.0): void
{
    $pdo->prepare(
        'INSERT INTO psf_monitor_exceptions (request_id, ts, exception_class, message, file, line_no, trace_hash, trace, sample_weight) '
        . "VALUES (:r, :ts, :c, :m, 'f.php', 7, 'th', '#0 f.php(7)', :w)"
    )->execute([':r' => $requestId, ':ts' => $ts, ':c' => $class, ':m' => $message, ':w' => $weight]);
}

/**
 * Cenário de rotas: A em 2 horas (histogramas conhecidos), B, C, D e uma com 0 hits.
 * sum_ms: A 6400, B 5000, D 1000, C 100, zero 0 | avg: D 1000, A 800, B 500, C 1, zero 0.
 */
function readerSeedRoutes(PDO $pdo): void
{
    readerRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET users/{id:int}', 4, 400, 200, 1, [1, 1, 0, 2, 0, 0, 0, 0, 0, 0]);
    readerRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET users/{id:int}', 4, 6000, 6000, 0, [0, 0, 0, 0, 3, 0, 0, 0, 0, 1]);
    readerRollup($pdo, '2026-10-24 10:00:00', 'request', 'POST orders', 10, 5000, 900, 2, [0, 0, 0, 0, 0, 10, 0, 0, 0, 0]);
    readerRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET health', 100, 100, 5, 5, [100, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    readerRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET big', 1, 1000, 1000, 0, [0, 0, 0, 0, 0, 0, 1, 0, 0, 0]);
    readerRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET zero', 0, 0, 0, 0);
    // Outras métricas não podem aparecer em routes().
    readerRollup($pdo, '2026-10-24 10:00:00', 'query', 'hash', 999, 99999, 999);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
    $this->reader = new Reader('default', $this->pdo);
    $this->from = readerAt('2026-10-24 00:00:00');
    $this->to = readerAt('2026-10-25 00:00:00');
});

it('ranks routes by total time with merged percentiles', function () {
    readerSeedRoutes($this->pdo);

    $routes = $this->reader->routes($this->from, $this->to);
    expect(array_column($routes, 'key'))->toBe(['GET users/{id:int}', 'POST orders', 'GET big', 'GET health', 'GET zero']);

    $a = $routes[0];
    $merged = [1, 1, 0, 2, 3, 0, 0, 0, 0, 1];
    expect(array_keys($a))->toBe(['key', 'method', 'route', 'hits', 'errors', 'error_rate', 'avg_ms', 'max_ms', 'p50_ms', 'p95_ms', 'p99_ms']);
    expect($a['method'])->toBe('GET');
    expect($a['route'])->toBe('users/{id:int}');
    expect($a['hits'])->toBe(8.0);
    expect($a['errors'])->toBe(1.0);
    expect($a['error_rate'])->toBe(0.125);
    expect($a['avg_ms'])->toBe(800.0);
    expect($a['max_ms'])->toBe(6000.0);
    expect($a['p50_ms'])->toBe(Histogram::percentile($merged, 50, 6000.0));
    expect($a['p95_ms'])->toBe(Histogram::percentile($merged, 95, 6000.0));
    expect($a['p99_ms'])->toBe(Histogram::percentile($merged, 99, 6000.0));
    expect($a['p95_ms'])->toBeGreaterThan(0.0);

    $zero = $routes[4];
    expect($zero['avg_ms'])->toBe(0.0);
    expect($zero['error_rate'])->toBe(0.0);
    expect($zero['p95_ms'])->toBe(0.0);

    $one = $this->reader->routes($this->from, $this->to, 1);
    expect($one)->toHaveCount(1);
    expect($one[0]['key'])->toBe('GET users/{id:int}');
});

it('orders by the whitelisted column only', function () {
    readerSeedRoutes($this->pdo);

    $keys = fn (string $order) => array_column($this->reader->routes($this->from, $this->to, 20, $order), 'key');

    expect($keys('hits'))->toBe(['GET health', 'POST orders', 'GET users/{id:int}', 'GET big', 'GET zero']);
    expect($keys('errors'))->toBe(['GET health', 'POST orders', 'GET users/{id:int}', 'GET big', 'GET zero']);
    expect($keys('max_ms'))->toBe(['GET users/{id:int}', 'GET big', 'POST orders', 'GET health', 'GET zero']);
    expect($keys('avg_ms'))->toBe(['GET big', 'GET users/{id:int}', 'POST orders', 'GET health', 'GET zero']);
    expect($keys('sum_ms; DROP TABLE psf_monitor_rollups'))->toBe($keys('sum_ms'));
    expect($keys('qualquer'))->toBe($keys('sum_ms'));

    expect((int) $this->pdo->query('SELECT COUNT(*) FROM psf_monitor_rollups')->fetchColumn())->toBe(7);
});

it('respects the half-open hour range', function () {
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'request', 'GET x', 1, 10, 10);
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'request', 'GET x', 2, 20, 10);
    readerRollup($this->pdo, '2026-10-24 12:00:00', 'request', 'GET x', 4, 40, 10);

    $hits = fn (string $from, string $to) => array_sum(array_column(
        $this->reader->routes(readerAt($from), readerAt($to)),
        'hits'
    ));

    expect($hits('2026-10-24 10:30:00', '2026-10-24 12:00:00'))->toBe(3.0);
    expect($hits('2026-10-24 10:30:00', '2026-10-24 12:00:01'))->toBe(7.0);
    expect($hits('2026-10-24 11:00:00', '2026-10-24 13:00:00'))->toBe(6.0);
    expect($hits('2026-10-24 11:59:59', '2026-10-24 12:00:00'))->toBe(2.0);
    expect($this->reader->routes(readerAt('2026-10-24 09:00:00'), readerAt('2026-10-24 10:00:00')))->toBe([]);
});

it('returns empty for reversed or empty ranges and clamps limit', function () {
    readerSeedRoutes($this->pdo);
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'exception', 'E', 1, sample: 'm', lastTs: '2026-10-24 10:01:00');

    $t = readerAt('2026-10-24 10:30:00');
    foreach ([[$this->to, $this->from], [$t, $t]] as [$from, $to]) {
        expect($this->reader->routes($from, $to))->toBe([]);
        expect($this->reader->slowQueries($from, $to))->toBe([]);
        expect($this->reader->exceptions($from, $to))->toBe([]);
        expect($this->reader->throughput($from, $to))->toBe([]);
    }

    expect($this->reader->routes($this->from, $this->to, 0))->toHaveCount(1);
    expect($this->reader->routes($this->from, $this->to, -5))->toHaveCount(1);
    expect($this->reader->routes($this->from, $this->to, 1_000_000))->toHaveCount(5);

    // Teto de 1000 (e lotes de IN acima de 500 chaves).
    $this->pdo->beginTransaction();
    for ($i = 0; $i < 1001; $i++) {
        readerRollup($this->pdo, '2026-10-24 13:00:00', 'request', sprintf('GET r%04d', $i), 1, 1, 1, 0, [1, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    }
    $this->pdo->commit();

    $many = $this->reader->routes(readerAt('2026-10-24 13:00:00'), readerAt('2026-10-24 14:00:00'), 1_000_000, 'hits');
    expect($many)->toHaveCount(1000);
    expect(array_filter($many, fn ($r) => $r['p50_ms'] <= 0.0))->toBe([]);
});

it('converts time zones to UTC', function () {
    readerRollup($this->pdo, '2026-01-01 10:00:00', 'request', 'GET x', 2, 20, 10);
    readerRollup($this->pdo, '2026-01-01 11:00:00', 'request', 'GET x', 1, 10, 10);

    $from = readerAt('2026-01-01 08:00:00', 'America/Sao_Paulo'); // 11:00 UTC
    $to   = readerAt('2026-01-01 09:00:00', 'America/Sao_Paulo'); // 12:00 UTC

    $routes = $this->reader->routes($from, $to);
    expect($routes)->toHaveCount(1);
    expect($routes[0]['hits'])->toBe(1.0);

    expect(array_column($this->reader->throughput($from, $to), 'hour'))->toBe(['2026-01-01 11:00:00']);
});

it('lists slow queries with their sample sql', function () {
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'query', 'hashA', 2, 100, 60, 0, [0, 0, 1, 1, 0, 0, 0, 0, 0, 0], 'SELECT velho');
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'query', 'hashA', 1, 300, 300, 0, [0, 0, 0, 0, 0, 1, 0, 0, 0, 0], 'SELECT novo');
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'query', 'hashB', 5, 50, 20, 0, [3, 2, 0, 0, 0, 0, 0, 0, 0, 0], 'SELECT b');
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'request', 'GET x', 1, 99999, 10);

    $queries = $this->reader->slowQueries($this->from, $this->to);

    expect(array_column($queries, 'sql_hash'))->toBe(['hashA', 'hashB']);
    $a = $queries[0];
    expect(array_keys($a))->toBe(['sql_hash', 'sql', 'hits', 'avg_ms', 'max_ms', 'p95_ms']);
    expect($a['sql'])->toBe('SELECT novo');
    expect($a['hits'])->toBe(3.0);
    expect($a['avg_ms'])->toBe(400 / 3);
    expect($a['max_ms'])->toBe(300.0);
    expect($a['p95_ms'])->toBe(Histogram::percentile([0, 0, 1, 1, 0, 1, 0, 0, 0, 0], 95, 300.0));
    expect($queries[1]['sql'])->toBe('SELECT b');

    expect($this->reader->slowQueries($this->from, $this->to, 1))->toHaveCount(1);
});

it('lists exceptions by class with last occurrence and message', function () {
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'exception', 'RuntimeException', 2, errors: 2, sample: 'antiga', lastTs: '2026-10-24 10:50:00');
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'exception', 'RuntimeException', 1, errors: 1, sample: 'nova', lastTs: '2026-10-24 11:05:00');
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'exception', 'PDOException', 5, errors: 5, sample: 'banco', lastTs: '2026-10-24 10:10:00');

    $exceptions = $this->reader->exceptions($this->from, $this->to);

    expect($exceptions)->toBe([
        ['exception_class' => 'PDOException', 'hits' => 5.0, 'last_ts' => '2026-10-24 10:10:00', 'message' => 'banco'],
        ['exception_class' => 'RuntimeException', 'hits' => 3.0, 'last_ts' => '2026-10-24 11:05:00', 'message' => 'nova'],
    ]);

    // Só a hora 10: a mensagem passa a ser a daquela hora.
    $only10 = $this->reader->exceptions($this->from, readerAt('2026-10-24 11:00:00'));
    expect($only10[1]['message'])->toBe('antiga');
    expect($only10[1]['last_ts'])->toBe('2026-10-24 10:50:00');
});

it('builds the hourly throughput series in ascending order', function () {
    readerRollup($this->pdo, '2026-10-24 12:00:00', 'request', 'GET a', 4, 40, 10);
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'request', 'GET a', 1, 100, 100, 1);
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'request', 'GET b', 3, 20, 10);
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'request', 'GET a', 0, 0, 0);
    readerRollup($this->pdo, '2026-10-24 11:00:00', 'query', 'h', 50, 500, 10);

    expect($this->reader->throughput($this->from, $this->to))->toBe([
        ['hour' => '2026-10-24 10:00:00', 'hits' => 4.0, 'errors' => 1.0, 'avg_ms' => 30.0],
        ['hour' => '2026-10-24 11:00:00', 'hits' => 0.0, 'errors' => 0.0, 'avg_ms' => 0.0],
        ['hour' => '2026-10-24 12:00:00', 'hits' => 4.0, 'errors' => 0.0, 'avg_ms' => 10.0],
    ]);
});

it('survives a corrupted histogram', function () {
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'request', 'GET x', 2, 20, 15, rawHistogram: 'oops');
    readerRollup($this->pdo, '2026-10-24 10:00:00', 'query', 'h', 2, 20, 15, rawHistogram: '[1,2]', sample: 'SELECT 1');

    $routes = $this->reader->routes($this->from, $this->to);
    expect($routes[0]['p95_ms'])->toBe(0.0);
    expect($routes[0]['p50_ms'])->toBe(0.0);
    expect($routes[0]['avg_ms'])->toBe(10.0);
    expect($this->reader->slowQueries($this->from, $this->to)[0]['p95_ms'])->toBe(0.0);
});

it('returns the raw detail of one request', function () {
    readerRawRequest($this->pdo, 'abc', '2026-10-24 10:00:00', 'users', 500, 120);
    readerRawRequest($this->pdo, 'outro', '2026-10-24 10:00:01', 'users', 200, 5);
    readerRawQuery($this->pdo, 'abc', '2026-10-24 10:00:00', 'h1', 'SELECT primeiro', 10);
    readerRawQuery($this->pdo, 'outro', '2026-10-24 10:00:00', 'h9', 'SELECT alheio', 1);
    readerRawQuery($this->pdo, 'abc', '2026-10-24 10:00:00', 'h2', 'SELECT segundo', 20);
    readerRawException($this->pdo, 'abc', '2026-10-24 10:00:00', 'RuntimeException', 'falhou');

    $detail = $this->reader->request('abc');

    expect(array_keys($detail))->toBe(['request', 'queries', 'exceptions', 'external']);
    expect($detail['request']['request_id'])->toBe('abc');
    expect((int) $detail['request']['status'])->toBe(500);
    expect(array_column($detail['queries'], 'sql_text'))->toBe(['SELECT primeiro', 'SELECT segundo']);
    expect($detail['exceptions'])->toHaveCount(1);
    expect($detail['exceptions'][0]['message'])->toBe('falhou');

    expect($this->reader->request('naoexiste'))->toBeNull();
    expect($this->reader->request(''))->toBeNull();
});

it('propagates database failures as PDOException', function () {
    $reader = new Reader('default', new PDO('sqlite::memory:'));
    $reader->routes($this->from, $this->to);
})->throws(PDOException::class);

it('works end to end after a rollup run', function () {
    readerRawRequest($this->pdo, 'r1', '2026-10-24 10:05:00', 'users/{id:int}', 200, 40);
    readerRawRequest($this->pdo, 'r2', '2026-10-24 10:20:00', 'users/{id:int}', 200, 700, 10.0);
    readerRawRequest($this->pdo, 'r3', '2026-10-24 11:40:00', 'users/{id:int}', 500, 3000);
    readerRawRequest($this->pdo, 'r4', '2026-10-24 11:10:00', 'orders', 201, 20, 1.0, 'POST');
    readerRawQuery($this->pdo, 'r1', '2026-10-24 10:05:00', 'qa', 'SELECT * FROM users WHERE id = ?', 30);
    readerRawQuery($this->pdo, 'r3', '2026-10-24 11:40:00', 'qa', 'SELECT * FROM users WHERE id = ?', 250);
    readerRawQuery($this->pdo, 'r4', '2026-10-24 11:10:00', 'qb', 'INSERT INTO orders VALUES (?)', 5);
    readerRawException($this->pdo, 'r3', '2026-10-24 11:40:00', 'RuntimeException', 'boom');

    $rollup = new Rollup($this->pdo, DialectFactory::fromConfig(\PSF::config('db', 'default')));
    $rollup->run(readerAt('2026-10-24 13:00:00'));

    // Conexão aberta sob demanda a partir de Config::database().
    $reader = new Reader();
    $from = readerAt('2026-10-24 10:00:00');
    $to = readerAt('2026-10-24 12:00:00');

    $routes = $reader->routes($from, $to);
    expect(array_column($routes, 'key'))->toBe(['GET users/{id:int}', 'POST orders']);
    $users = $routes[0];
    expect($users['hits'])->toBe(12.0);
    expect($users['errors'])->toBe(1.0);
    expect($users['error_rate'])->toBe(1 / 12);
    expect($users['avg_ms'])->toBe((40 + 7000 + 3000) / 12);
    expect($users['max_ms'])->toBe(3000.0);
    expect($users['p50_ms'])->toBe(Histogram::percentile([0, 0, 1, 0, 0, 0, 10, 0, 1, 0], 50, 3000.0));
    expect($users['p99_ms'])->toBeLessThanOrEqual(3000.0);

    $queries = $reader->slowQueries($from, $to);
    expect(array_column($queries, 'sql_hash'))->toBe(['qa', 'qb']);
    expect($queries[0]['hits'])->toBe(2.0);
    expect($queries[0]['avg_ms'])->toBe(140.0);
    expect($queries[0]['sql'])->toBe('SELECT * FROM users WHERE id = ?');

    expect($reader->exceptions($from, $to))->toBe([
        ['exception_class' => 'RuntimeException', 'hits' => 1.0, 'last_ts' => '2026-10-24 11:40:00', 'message' => 'boom'],
    ]);

    expect($reader->throughput($from, $to))->toBe([
        ['hour' => '2026-10-24 10:00:00', 'hits' => 11.0, 'errors' => 0.0, 'avg_ms' => 640.0],
        ['hour' => '2026-10-24 11:00:00', 'hits' => 2.0, 'errors' => 1.0, 'avg_ms' => 1510.0],
    ]);

    expect($reader->request('r3')['exceptions'][0]['exception_class'])->toBe('RuntimeException');
});
