<?php

use Psf\Monitor\Api\MonitorApi;
use Psf\Monitor\Command\MonitorInstall;
use Psf\Monitor\Command\MonitorMaintain;
use Psf\Monitor\Command\MonitorPrune;
use Psf\Monitor\Command\MonitorRollup;
use Psf\Monitor\Command\MonitorStatus;
use Psf\Monitor\Histogram;
use Psf\Monitor\Installer;
use Psf\Monitor\Reader;
use Psf\Monitor\Rollup;
use Symfony\Component\Console\Tester\CommandTester;

/*
| Reader, MonitorApi e comandos monitor:* em MySQL e PostgreSQL reais. Mesmo
| roteiro de tests/Integration/Monitor/{Reader,ReaderExternal,MonitorApi,Commands,RollupCommands}Test.php.
*/

function drvReaderSetup(string $driver, array $monitor = []): PDO
{
    driverUse($driver, ['enabled' => true] + $monitor);
    (new Installer())->install();
    return driverPdo();
}

/** Mesmo cenário de readerSeedRoutes() (tests/Integration/Monitor/ReaderTest.php). */
function drvSeedRoutes(PDO $pdo): void
{
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET users/{id:int}', 4, 400, 200, 1, [1, 1, 0, 2, 0, 0, 0, 0, 0, 0]);
    drvRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET users/{id:int}', 4, 6000, 6000, 0, [0, 0, 0, 0, 3, 0, 0, 0, 0, 1]);
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'POST orders', 10, 5000, 900, 2, [0, 0, 0, 0, 0, 10, 0, 0, 0, 0]);
    drvRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET health', 100, 100, 5, 5, [100, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    drvRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET big', 1, 1000, 1000, 0, [0, 0, 0, 0, 0, 0, 1, 0, 0, 0]);
    drvRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET zero', 0, 0, 0, 0);
    drvRollup($pdo, '2026-10-24 10:00:00', 'query', 'hash', 999, 99999, 999);
}

it('ranks routes with merged percentiles and every whitelisted order', function (string $driver) {
    $pdo = drvReaderSetup($driver);
    drvSeedRoutes($pdo);
    $reader = new Reader('default', $pdo);
    $from = driverAt('2026-10-24 00:00:00');
    $to = driverAt('2026-10-25 00:00:00');

    $routes = $reader->routes($from, $to);
    expect(array_column($routes, 'key'))->toBe(['GET users/{id:int}', 'POST orders', 'GET big', 'GET health', 'GET zero']);
    $a = $routes[0];
    $merged = [1, 1, 0, 2, 3, 0, 0, 0, 0, 1];
    expect(array_keys($a))->toBe(['key', 'method', 'route', 'hits', 'errors', 'errorRate', 'avgMs', 'maxMs', 'p50Ms', 'p95Ms', 'p99Ms']);
    expect([$a['method'], $a['route']])->toBe(['GET', 'users/{id:int}']);
    expect($a['hits'])->toBe(8.0)
        ->and($a['errors'])->toBe(1.0)
        ->and($a['errorRate'])->toBe(0.125)
        ->and($a['avgMs'])->toBe(800.0)
        ->and($a['maxMs'])->toBe(6000.0)
        ->and($a['p50Ms'])->toBe(Histogram::percentile($merged, 50, 6000.0))
        ->and($a['p95Ms'])->toBe(Histogram::percentile($merged, 95, 6000.0))
        ->and($a['p99Ms'])->toBe(Histogram::percentile($merged, 99, 6000.0));
    foreach ($routes as $r) {
        foreach (['hits', 'errors', 'errorRate', 'avgMs', 'maxMs', 'p50Ms', 'p95Ms', 'p99Ms'] as $k) {
            expect($r[$k])->toBeFloat();
        }
    }
    expect($routes[4]['avgMs'])->toBe(0.0)->and($routes[4]['errorRate'])->toBe(0.0);

    $keys = fn (string $order) => array_column($reader->routes($from, $to, 20, $order), 'key');
    expect($keys('hits'))->toBe(['GET health', 'POST orders', 'GET users/{id:int}', 'GET big', 'GET zero']);
    expect($keys('errors'))->toBe(['GET health', 'POST orders', 'GET users/{id:int}', 'GET big', 'GET zero']);
    expect($keys('maxMs'))->toBe(['GET users/{id:int}', 'GET big', 'POST orders', 'GET health', 'GET zero']);
    expect($keys('avgMs'))->toBe(['GET big', 'GET users/{id:int}', 'POST orders', 'GET health', 'GET zero']);
    expect($keys('sum_ms; DROP TABLE psf_monitor_rollups'))->toBe($keys('sum_ms'));
    expect(driverCount($pdo, 'psf_monitor_rollups'))->toBe(7);
    expect($reader->routes($from, $to, 1))->toHaveCount(1);
})->with('drivers');

it('orders avg_ms without integer division', function (string $driver) {
    $pdo = drvReaderSetup($driver);
    // Com divisão inteira as duas médias virariam 2 e o desempate por chave poria `GET a` primeiro.
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET a', 5, 12, 3);   // 2.4
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET b', 4, 10, 3);   // 2.5
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET c', 3, 1, 1);    // 0.333...

    $routes = (new Reader('default', $pdo))->routes(driverAt('2026-10-24 00:00:00'), driverAt('2026-10-25 00:00:00'), 20, 'avgMs');

    expect(array_column($routes, 'key'))->toBe(['GET b', 'GET a', 'GET c']);
    expect(array_column($routes, 'avgMs'))->toBe([2.5, 2.4, 1 / 3]);
})->with('drivers');

it('respects ranges, time zones, limits and IN chunks', function (string $driver) {
    $pdo = drvReaderSetup($driver);
    $reader = new Reader('default', $pdo);
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET x', 1, 10, 10);
    drvRollup($pdo, '2026-10-24 11:00:00', 'request', 'GET x', 2, 20, 10);
    drvRollup($pdo, '2026-10-24 12:00:00', 'request', 'GET x', 4, 40, 10);

    $hits = fn (string $from, string $to) => array_sum(array_column($reader->routes(driverAt($from), driverAt($to)), 'hits'));
    expect($hits('2026-10-24 10:30:00', '2026-10-24 12:00:00'))->toBe(3.0);
    expect($hits('2026-10-24 10:30:00', '2026-10-24 12:00:01'))->toBe(7.0);
    expect($hits('2026-10-24 11:59:59', '2026-10-24 12:00:00'))->toBe(2.0);
    expect($reader->routes(driverAt('2026-10-24 12:00:00'), driverAt('2026-10-24 10:00:00')))->toBe([]);
    $sp = $reader->routes(driverAt('2026-10-24 08:00:00', 'America/Sao_Paulo'), driverAt('2026-10-24 09:00:00', 'America/Sao_Paulo'));
    expect($sp[0]['hits'])->toBe(2.0);

    $pdo->beginTransaction();
    for ($i = 0; $i < 1001; $i++) {
        drvRollup($pdo, '2026-10-24 13:00:00', 'request', sprintf('GET r%04d', $i), 1, 1, 1, 0, [1, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    }
    $pdo->commit();
    $many = $reader->routes(driverAt('2026-10-24 13:00:00'), driverAt('2026-10-24 14:00:00'), 1_000_000, 'hits');
    expect($many)->toHaveCount(1000);
    expect(array_filter($many, fn ($r) => $r['p50Ms'] <= 0.0))->toBe([]);
    expect($reader->routes(driverAt('2026-10-24 13:00:00'), driverAt('2026-10-24 14:00:00'), -5))->toHaveCount(1);
})->with('drivers');

it('lists slow queries, exceptions, external calls and the throughput series', function (string $driver) {
    $pdo = drvReaderSetup($driver);
    $reader = new Reader('default', $pdo);
    $from = driverAt('2026-10-24 00:00:00');
    $to = driverAt('2026-10-25 00:00:00');
    drvRollup($pdo, '2026-10-24 10:00:00', 'query', 'hashA', 2, 100, 60, 0, [0, 0, 1, 1, 0, 0, 0, 0, 0, 0], 'SELECT velho');
    drvRollup($pdo, '2026-10-24 11:00:00', 'query', 'hashA', 1, 300, 300, 0, [0, 0, 0, 0, 0, 1, 0, 0, 0, 0], 'SELECT novo');
    drvRollup($pdo, '2026-10-24 11:00:00', 'query', 'hashB', 5, 50, 20, 0, [3, 2, 0, 0, 0, 0, 0, 0, 0, 0], 'SELECT b');
    drvRollup($pdo, '2026-10-24 10:00:00', 'exception', 'RuntimeException', 2, errors: 2, sample: 'antiga', lastTs: '2026-10-24 10:50:00');
    drvRollup($pdo, '2026-10-24 11:00:00', 'exception', 'RuntimeException', 1, errors: 1, sample: 'nova ção', lastTs: '2026-10-24 11:05:00');
    drvRollup($pdo, '2026-10-24 10:00:00', 'exception', 'PDOException', 5, errors: 5, sample: 'banco', lastTs: '2026-10-24 10:10:00');
    drvRollup($pdo, '2026-10-24 10:00:00', 'external', 'http:2001:db8::1', 4, 400, 300, 1, [0, 0, 0, 0, 4, 0, 0, 0, 0, 0]);
    drvRollup($pdo, '2026-10-24 10:00:00', 'external', 'mail:smtp.exemplo.com', 1, 50, 50);
    drvRollup($pdo, '2026-10-24 12:00:00', 'request', 'GET a', 4, 40, 10);
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET a', 1, 100, 100, 1);
    drvRollup($pdo, '2026-10-24 10:00:00', 'request', 'GET b', 3, 20, 10);

    $q = $reader->slowQueries($from, $to);
    expect(array_column($q, 'sqlHash'))->toBe(['hashA', 'hashB'])
        ->and($q[0]['sql'])->toBe('SELECT novo')
        ->and($q[0]['hits'])->toBe(3.0)
        ->and($q[0]['avgMs'])->toBe(400 / 3)
        ->and($q[0]['p95Ms'])->toBe(Histogram::percentile([0, 0, 1, 1, 0, 1, 0, 0, 0, 0], 95, 300.0));

    expect($reader->exceptions($from, $to))->toBe([
        ['exceptionClass' => 'PDOException', 'hits' => 5.0, 'lastTs' => '2026-10-24 10:10:00', 'message' => 'banco'],
        ['exceptionClass' => 'RuntimeException', 'hits' => 3.0, 'lastTs' => '2026-10-24 11:05:00', 'message' => 'nova ção'],
    ]);

    $ext = $reader->external($from, $to);
    expect(array_column($ext, 'key'))->toBe(['http:2001:db8::1', 'mail:smtp.exemplo.com'])
        ->and([$ext[0]['kind'], $ext[0]['target'], $ext[0]['errorRate']])->toBe(['http', '2001:db8::1', 0.25]);

    expect($reader->throughput($from, $to))->toBe([
        ['hour' => '2026-10-24 10:00:00', 'hits' => 4.0, 'errors' => 1.0, 'avgMs' => 30.0],
        ['hour' => '2026-10-24 12:00:00', 'hits' => 4.0, 'errors' => 0.0, 'avgMs' => 10.0],
    ]);
})->with('drivers');

it('works end to end after a rollup run, including the raw request detail', function (string $driver) {
    $pdo = drvReaderSetup($driver);
    drvRequest($pdo, '2026-10-24 10:05:00', 'users/{id:int}', 200, 40, id: 'r1');
    drvRequest($pdo, '2026-10-24 10:20:00', 'users/{id:int}', 200, 700, 10.0, id: 'r2');
    drvRequest($pdo, '2026-10-24 11:40:00', 'users/{id:int}', 500, 3000, id: 'r3');
    drvRequest($pdo, '2026-10-24 11:10:00', 'orders', 201, 20, 1.0, 'POST', 'r4');
    drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['request_id' => 'r1', 'ts' => '2026-10-24 10:05:00', 'sql_hash' => 'qa', 'sql_text' => 'SELECT * FROM users WHERE id = ?', 'duration_ms' => 30]));
    drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['request_id' => 'r3', 'ts' => '2026-10-24 11:40:00', 'sql_hash' => 'qa', 'sql_text' => 'SELECT * FROM users WHERE id = ?', 'duration_ms' => 250]));
    drvInsert($pdo, 'psf_monitor_exceptions', drvExceptionRow(['request_id' => 'r3', 'ts' => '2026-10-24 11:40:00', 'message' => 'boom']));
    drvInsert($pdo, 'psf_monitor_external', drvExternalRow(['request_id' => 'r3', 'ts' => '2026-10-24 11:40:00', 'status' => null]));

    (new Rollup($pdo, driverDialect()))->run(driverAt('2026-10-24 13:00:00'));

    $reader = new Reader();
    $from = driverAt('2026-10-24 10:00:00');
    $to = driverAt('2026-10-24 12:00:00');
    $users = $reader->routes($from, $to)[0];
    expect($users['key'])->toBe('GET users/{id:int}')
        ->and($users['hits'])->toBe(12.0)
        ->and($users['errorRate'])->toBe(1 / 12)
        ->and($users['avgMs'])->toBe((40 + 7000 + 3000) / 12);
    expect($reader->slowQueries($from, $to)[0]['avgMs'])->toBe(140.0);
    expect($reader->external($from, $to)[0]['errors'])->toBe(1.0);
    expect($reader->throughput($from, $to))->toBe([
        ['hour' => '2026-10-24 10:00:00', 'hits' => 11.0, 'errors' => 0.0, 'avgMs' => 640.0],
        ['hour' => '2026-10-24 11:00:00', 'hits' => 2.0, 'errors' => 1.0, 'avgMs' => 1510.0],
    ]);

    $detail = $reader->request('r3');
    expect(array_keys($detail))->toBe(['request', 'queries', 'exceptions', 'external']);
    // Linhas como o driver devolve: tipos variam, valores batem depois do cast.
    expect((int) $detail['request']['status'])->toBe(500)
        ->and((float) $detail['request']['durationMs'])->toBe(3000.0)
        ->and($detail['queries'][0]['sqlText'])->toBe('SELECT * FROM users WHERE id = ?')
        ->and($detail['exceptions'][0]['message'])->toBe('boom')
        ->and($detail['external'][0]['status'])->toBeNull();
    expect($reader->request('naoexiste'))->toBeNull();
})->with('drivers');

it('serves MonitorApi end to end and refuses hostile parameters', function (string $driver) {
    $pdo = drvReaderSetup($driver, ['api' => ['enabled' => true]]);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $ts = $now->modify('-3 hours')->format('Y-m-d H:i:s');
    drvRequest($pdo, $ts, 'users/{id:int}', 200, 120, id: 'req-12345678');
    drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['request_id' => 'req-12345678', 'ts' => $ts]));
    drvInsert($pdo, 'psf_monitor_exceptions', drvExceptionRow(['request_id' => 'req-12345678', 'ts' => $ts]));
    drvInsert($pdo, 'psf_monitor_external', drvExternalRow(['request_id' => 'req-12345678', 'ts' => $ts]));
    (new Rollup($pdo, driverDialect()))->run($now);
    $api = new MonitorApi();

    foreach (['routes', 'queries', 'exceptions', 'external', 'throughput'] as $endpoint) {
        [$msg, $data, $status] = $api->handle($endpoint, []);
        expect([$msg, $status])->toBe(['', 200]);
        expect($data)->toHaveCount(1);
    }
    foreach (['sumMs', 'hits', 'errors', 'maxMs', 'avgMs'] as $order) {
        expect($api->handle('routes', ['orderBy' => $order, 'limit' => '5'])[2])->toBe(200);
    }
    [, $detail, $status] = $api->handle('request', ['id' => 'req-12345678']);
    expect($status)->toBe(200)->and($detail['request']['requestId'])->toBe('req-12345678');
    expect($api->handle('request', ['id' => 'inexistente-123'])[2])->toBe(404);
    expect($api->handle('routes', ['order_by' => 'sum_ms; DROP TABLE psf_monitor_rollups'])[2])->toBe(422);
    expect($api->handle('routes', ['from' => 'ontem'])[2])->toBe(422);
    expect($api->handle('routes', ['limit' => '99999'])[2])->toBe(422);
    expect($api->handle('request', ['id' => "x' OR '1'='1"])[2])->toBe(422);
    expect(driverCount($pdo, 'psf_monitor_rollups'))->toBeGreaterThan(0);
})->with('drivers');

it('runs monitor:install, status, rollup, maintain and prune', function (string $driver) {
    driverUse($driver, ['enabled' => true]);

    $install = new CommandTester(new MonitorInstall());
    expect($install->execute([]))->toBe(0);
    expect($install->getDisplay())->toContain('Tabelas do monitor instaladas.');
    expect($install->execute([]))->toBe(0);

    $status = new CommandTester(new MonitorStatus());
    expect($status->execute([]))->toBe(0);
    expect($status->getDisplay())->toContain('Instalado: sim')->toContain('Rollup até: nunca');

    $pdo = driverPdo();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    drvRequest($pdo, $now->modify('-3 hours')->format('Y-m-d H:i:s'), id: 'recente');
    drvRequest($pdo, $now->modify('-30 days')->format('Y-m-d H:i:s'), id: 'velho');
    drvRollup($pdo, $now->modify('-100 days')->format('Y-m-d H:00:00'), 'request', 'GET /velho', 1);

    $rollup = new CommandTester(new MonitorRollup());
    expect($rollup->execute(['--max-hours' => '0']))->toBe(1);
    expect($rollup->execute(['--max-hours' => '2']))->toBe(0);
    expect($rollup->getDisplay())->toContain('Horas agregadas: 2');

    $maintain = new CommandTester(new MonitorMaintain());
    expect($maintain->execute([]))->toBe(0);
    expect($maintain->getDisplay())
        ->toMatch('/Horas agregadas: [1-9]\d*/')
        ->toContain('Linhas apagadas: 1')
        ->toContain('Agregados apagados: 1');
    expect($pdo->query('SELECT request_id FROM psf_monitor_requests')->fetchAll(PDO::FETCH_COLUMN))->toBe(['recente']);

    $status->execute(['-e' => 'default']);
    expect($status->getDisplay())->toMatch('/Rollup até: \d{4}-\d{2}-\d{2} \d{2}:00:00/');

    drvRequest($pdo, $now->modify('-30 days')->format('Y-m-d H:i:s'), id: 'velho2');
    $prune = new CommandTester(new MonitorPrune());
    expect($prune->execute([]))->toBe(0);
    expect($prune->getDisplay())->toContain('Linhas apagadas: 1');
    expect($prune->execute(['-e' => 'nao_existe']))->toBe(1);
})->with('drivers');

it('runs sink, rollup, reader and pruner with emulated and native prepares', function (string $driver, bool $emulate) {
    driverUse($driver, ['enabled' => true], ['db' => ['default' => ['extras' => [PDO::ATTR_EMULATE_PREPARES => $emulate]]]]);
    (new Installer())->install();
    $pdo = driverPdo();
    expect($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBe($emulate);

    (new \Psf\Monitor\Sinks\DatabaseSink())->write([
        'requests' => [drvRequestRow(['request_id' => 'r1', 'ts' => '2026-10-24 10:05:00', 'sample_weight' => 2.5, 'duration_ms' => 40.25])],
        'queries'  => [drvQueryRow(['request_id' => 'r1', 'ts' => '2026-10-24 10:05:00', 'sql_hash' => 'qa'])],
        'external' => [drvExternalRow(['request_id' => 'r1', 'ts' => '2026-10-24 10:05:00', 'status' => null])],
    ]);
    expect((new Rollup($pdo, driverDialect()))->run(driverAt('2026-10-24 11:06:00'))['rows'])->toBe(3);

    // PDO avulso sem entrada em config['db']: o dialeto vem do driver do PDO.
    $reader = new Reader('sem_config', $pdo);
    $routes = $reader->routes(driverAt('2026-10-24 10:00:00'), driverAt('2026-10-24 11:00:00'), 5, 'avgMs');
    expect($routes[0]['hits'])->toBe(2.5);
    // sum_ms é decimal(18,2): 2.5 × 40.25 = 100.625 é gravado como 100.63 (SQLite guardaria 100.625).
    expect($routes[0]['avgMs'])->toEqualWithDelta(40.25, 0.01);
    expect($reader->external(driverAt('2026-10-24 10:00:00'), driverAt('2026-10-24 11:00:00'))[0]['errors'])->toBe(1.0);
    expect($reader->request('r1')['request']['requestId'])->toBe('r1');

    expect((new \Psf\Monitor\Pruner($pdo, driverDialect(), 1, 0))->pruneRaw(driverAt('2026-11-24 12:00:00'), 7))->toBe(3);
})->with('drivers')->with(['emulated' => true, 'native' => false]);
