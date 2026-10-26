<?php

use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Pruner;
use Psf\Monitor\Rollup;
use Psf\Monitor\Watermark;

/*
| Rollup, Watermark e Pruner em MySQL e PostgreSQL reais. Mesmo roteiro de
| tests/Integration/Monitor/{Rollup,RollupMetrics,RollupExternal,Watermark,Pruner}Test.php.
*/

function drvRollupMake(?PDO $pdo = null): Rollup
{
    return new Rollup($pdo ?? driverPdo(), driverDialect());
}

function drvSetup(string $driver): PDO
{
    driverUse($driver, ['enabled' => true]);
    (new Installer())->install();
    return driverPdo();
}

it('aggregates one complete hour weighted by sample_weight', function (string $driver) {
    $pdo = drvSetup($driver);
    drvRequest($pdo, '2026-10-24 10:05:00', ms: 40, weight: 1.0);
    drvRequest($pdo, '2026-10-24 10:20:00', ms: 700, weight: 10.0);
    drvRequest($pdo, '2026-10-24 10:40:00', status: 500, ms: 3000, weight: 1.0);
    drvRequest($pdo, '2026-10-24 10:41:00', status: 404, ms: 1, weight: 0.5, method: 'POST');

    $result = drvRollupMake()->run(driverAt('2026-10-24 11:06:00'));

    expect($result)->toBe(['hours' => 1, 'rows' => 2, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    [$get, $post] = drvRollupRows($pdo);
    expect($get['bucket_hour'])->toBe('2026-10-24 10:00:00')
        ->and($get['metric'])->toBe('request')
        ->and($get['metric_key'])->toBe('GET users/{id:int}')
        ->and((float) $get['hits'])->toBe(12.0)
        ->and((float) $get['errors'])->toBe(1.0)
        ->and((float) $get['sum_ms'])->toBe(10040.0)
        ->and((float) $get['max_ms'])->toBe(3000.0)
        ->and($get['last_ts'])->toBe('2026-10-24 10:40:00')
        ->and($get['sample_text'])->toBeNull()
        ->and(json_decode($get['histogram'], true))->toBe([0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 10.0, 0.0, 1.0, 0.0]);
    expect($post['metric_key'])->toBe('POST users/{id:int}')
        ->and((float) $post['hits'])->toBe(0.5)
        ->and((float) $post['errors'])->toBe(0.0)
        ->and(json_decode($post['histogram'], true))->toBe([0.5, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]);
    expect(drvWatermark($pdo))->toBe('2026-10-24 11:00:00');
})->with('drivers');

it('aggregates queries, exceptions and external calls in the same hour', function (string $driver) {
    $pdo = drvSetup($driver);
    drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['ts' => '2026-10-24 10:05:00', 'sql_hash' => 'A', 'sql_text' => 'SELECT primeiro', 'duration_ms' => 20]));
    drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['ts' => '2026-10-24 10:30:00', 'sql_hash' => 'A', 'sql_text' => 'SELECT segundo', 'duration_ms' => 300, 'sample_weight' => 4.0]));
    drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['ts' => '2026-10-24 10:10:00', 'sql_hash' => 'B', 'sql_text' => 'SELECT b', 'duration_ms' => 5]));
    drvInsert($pdo, 'psf_monitor_exceptions', drvExceptionRow(['ts' => '2026-10-24 10:07:00', 'exception_class' => 'RuntimeException', 'message' => 'primeira ç']));
    drvInsert($pdo, 'psf_monitor_exceptions', drvExceptionRow(['ts' => '2026-10-24 10:50:00', 'exception_class' => 'RuntimeException', 'message' => 'segunda', 'sample_weight' => 2.5]));
    foreach ([[200, 10.0, 1.0], [404, 20.0, 1.0], [503, 2000.0, 2.0], [null, 50.0, 0.25]] as [$status, $ms, $w]) {
        drvInsert($pdo, 'psf_monitor_external', drvExternalRow(['ts' => '2026-10-24 10:15:00', 'status' => $status, 'duration_ms' => $ms, 'sample_weight' => $w]));
    }
    drvInsert($pdo, 'psf_monitor_external', drvExternalRow(['ts' => '2026-10-24 10:16:00', 'kind' => 'mail', 'target' => 'smtp.exemplo.com', 'status' => 200]));

    expect(drvRollupMake()->run(driverAt('2026-10-24 11:06:00'))['rows'])->toBe(5);

    [$a, $b] = drvRollupRows($pdo, 'query');
    expect([$a['metric_key'], (float) $a['hits'], (float) $a['sum_ms'], (float) $a['max_ms'], $a['sample_text'], $a['last_ts']])
        ->toBe(['A', 5.0, 1220.0, 300.0, 'SELECT primeiro', '2026-10-24 10:30:00']);
    expect([$b['metric_key'], $b['sample_text']])->toBe(['B', 'SELECT b']);

    [$e] = drvRollupRows($pdo, 'exception');
    expect([$e['metric_key'], (float) $e['hits'], (float) $e['errors'], $e['sample_text'], $e['last_ts']])
        ->toBe(['RuntimeException', 3.5, 3.5, 'primeira ç', '2026-10-24 10:50:00']);

    [$http, $mail] = drvRollupRows($pdo, 'external');
    expect($http['metric_key'])->toBe('http:api.exemplo.com')
        ->and((float) $http['hits'])->toBe(4.25)
        ->and((float) $http['errors'])->toBe(2.25)
        ->and((float) $http['sum_ms'])->toBe(10.0 + 20.0 + 4000.0 + 12.5)
        ->and((float) $http['max_ms'])->toBe(2000.0)
        ->and($http['sample_text'])->toBeNull();
    expect([$mail['metric_key'], (float) $mail['errors']])->toBe(['mail:smtp.exemplo.com', 0.0]);
})->with('drivers');

it('is idempotent, re-aggregates the last 2 hours and caps the work per run', function (string $driver) {
    $pdo = drvSetup($driver);
    foreach (['06', '07', '08', '09', '10'] as $h) {
        drvRequest($pdo, "2026-10-24 {$h}:30:00");
    }
    $now = driverAt('2026-10-24 11:10:00');

    expect(drvRollupMake()->run($now, 2))->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 08:00:00', 'reaggregated' => 0, 'skipped' => 0]);
    expect(drvRollupMake()->run($now, 2))->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 10:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    expect(drvRollupMake()->run($now, 2))->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    $first = drvRollupRows($pdo);
    expect($first)->toHaveCount(5);

    // Linha atrasada de uma hora já agregada (dentro da janela de 2 h) converge.
    drvRequest($pdo, '2026-10-24 10:58:00', ms: 30);
    expect(drvRollupMake()->run($now, 2))->toBe(['hours' => 0, 'rows' => 0, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 2, 'skipped' => 0]);
    $rows = drvRollupRows($pdo);
    expect((float) end($rows)['hits'])->toBe(2.0)->and(end($rows)['last_ts'])->toBe('2026-10-24 10:58:00');

    // Recalcular do zero dá o mesmo resultado.
    $pdo->exec('DELETE FROM psf_monitor_state');
    $pdo->exec('DELETE FROM psf_monitor_rollups');
    drvRollupMake()->run($now);
    expect(drvRollupRows($pdo))->toBe($rows);
})->with('drivers');

it('merges keys that differ only in case or trailing spaces and truncates long keys', function (string $driver) {
    $pdo = drvSetup($driver);
    drvRequest($pdo, '2026-10-24 10:05:00', route: '/Users', ms: 10);
    drvRequest($pdo, '2026-10-24 10:06:00', route: '/users ', ms: 30);
    drvRequest($pdo, '2026-10-24 10:07:00', route: str_repeat('é', 190) . 'X', ms: 1);
    drvRequest($pdo, '2026-10-24 10:08:00', route: str_repeat('é', 190) . 'Y', ms: 2);

    expect(drvRollupMake()->run(driverAt('2026-10-24 11:06:00')))
        ->toBe(['hours' => 1, 'rows' => 2, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 0]);

    $rows = drvRollupRows($pdo);
    $byKey = array_column($rows, null, 'metric_key');
    expect($byKey['GET /Users'])->not->toBeNull();
    expect((float) $byKey['GET /Users']['hits'])->toBe(2.0)
        ->and((float) $byKey['GET /Users']['sum_ms'])->toBe(40.0);
    $long = 'GET ' . str_repeat('é', 187);
    expect(mb_strlen($long))->toBe(191);
    expect((float) $byKey[$long]['hits'])->toBe(2.0);
})->with('drivers');

it('skips an hour that fails with a real integrity violation and keeps going', function (string $driver) {
    $pdo = drvSetup($driver);
    drvRequest($pdo, '2026-10-24 08:30:00');
    drvRequest($pdo, '2026-10-24 10:30:00');
    if ($driver === 'mysql') {
        // utf8mb4_unicode_ci (collation do Phinx) é insensível a acento. O GROUP BY já usa a
        // collation da coluna, então a colisão real vem do corte em 191: as rotas diferem só
        // depois do corte e, antes dele, só por acento (o mergeByKey não funde acentos).
        drvRequest($pdo, '2026-10-24 09:10:00', route: str_repeat('é', 187) . 'X');
        drvRequest($pdo, '2026-10-24 09:20:00', route: str_repeat('e', 187) . 'Y');
    } else {
        // Collation determinística: a colisão é provocada por um trigger com SQLSTATE 23505.
        $pdo->exec("CREATE FUNCTION psf_drv_boom() RETURNS trigger AS \$\$ BEGIN
            IF NEW.metric_key = 'GET /boom' THEN RAISE EXCEPTION 'colisao simulada' USING ERRCODE = 'unique_violation'; END IF;
            RETURN NEW; END \$\$ LANGUAGE plpgsql");
        $pdo->exec('CREATE TRIGGER psf_drv_boom BEFORE INSERT ON psf_monitor_rollups FOR EACH ROW EXECUTE FUNCTION psf_drv_boom()');
        drvRequest($pdo, '2026-10-24 09:10:00', route: '/ok');
        drvRequest($pdo, '2026-10-24 09:20:00', route: '/boom');
    }
    $rollupPdo = driverPdo();

    expect(drvRollupMake($rollupPdo)->run(driverAt('2026-10-24 11:10:00')))
        ->toBe(['hours' => 2, 'rows' => 2, 'watermark' => '2026-10-24 11:00:00', 'reaggregated' => 0, 'skipped' => 1]);
    expect($rollupPdo->inTransaction())->toBeFalse();
    expect(array_column(drvRollupRows($pdo), 'bucket_hour'))->toBe(['2026-10-24 08:00:00', '2026-10-24 10:00:00']);
    expect(drvWatermark($pdo))->toBe('2026-10-24 11:00:00');
    expect(driverCount($pdo, 'psf_monitor_requests'))->toBe(4);

    // A hora pulada conta em maxHours; a conexão continua utilizável depois do erro.
    $pdo->exec('DELETE FROM psf_monitor_state');
    $pdo->exec('DELETE FROM psf_monitor_rollups');
    expect(drvRollupMake($rollupPdo)->run(driverAt('2026-10-24 11:10:00'), 2))
        ->toBe(['hours' => 1, 'rows' => 1, 'watermark' => '2026-10-24 10:00:00', 'reaggregated' => 0, 'skipped' => 1]);
})->with('drivers');

it('writes the watermark as an upsert inside and outside transactions', function (string $driver) {
    $pdo = drvSetup($driver);
    $w = new Watermark($pdo, driverDialect());

    expect($w->read())->toBeNull();
    $w->write('2026-01-02 03:00:00');
    $w->write('2026-01-02 03:00:00'); // mesmo valor (MySQL: rowCount 0 no UPDATE)
    $w->write('2026-01-02 04:00:00');
    expect($w->read())->toBe('2026-01-02 04:00:00')
        ->and(driverCount($pdo, 'psf_monitor_state'))->toBe(1);

    $pdo->beginTransaction();
    $w->write('2026-01-02 05:00:00');
    expect($pdo->inTransaction())->toBeTrue();
    $pdo->rollBack();
    expect($w->read())->toBe('2026-01-02 04:00:00');

    $pdo->exec('DELETE FROM psf_monitor_state');
    $pdo->beginTransaction();
    $w->write('2026-01-02 06:00:00');
    $pdo->commit();
    expect($w->read())->toBe('2026-01-02 06:00:00');

    $pdo->exec("UPDATE psf_monitor_state SET value = 'lixo'");
    expect($w->read())->toBeNull();
    $pdo->exec('DROP TABLE psf_monitor_state');
    expect($w->read())->toBeNull();
})->with('drivers');

// Só PostgreSQL: no MySQL (REPEATABLE READ) o UPDATE sem linha já trava a lacuna da PK e o
// INSERT concorrente espera o commit; a corrida não acontece dentro da transação.
it('survives a concurrent insert of the watermark inside an open transaction', function (string $driver) {
    drvSetup($driver);
    [$dsn, $options] = Connection::parameters(\PSF::config('db', 'default'), 5);
    $cfg = \PSF::config('db', 'default');

    // Outra conexão insere a chave entre o SELECT e o INSERT do Watermark (corrida real).
    $racing = new class ($dsn, $cfg['username'], $cfg['password'], $options) extends PDO {
        public bool $raced = false;

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (!$this->raced && str_starts_with($query, 'INSERT INTO') && str_contains($query, 'psf_monitor_state')) {
                $this->raced = true;
                driverPdo()->exec("INSERT INTO psf_monitor_state (name, value) VALUES ('rollup_watermark', '2000-01-01 00:00:00')");
            }
            return parent::prepare($query, $options);
        }
    };

    $racing->beginTransaction();
    (new Watermark($racing, driverDialect()))->write('2026-01-02 03:00:00');
    expect($racing->raced)->toBeTrue();
    // A transação de quem chamou continua utilizável (PostgreSQL aborta a transação no erro).
    $racing->exec("UPDATE psf_monitor_state SET value = value WHERE name = 'x'");
    $racing->commit();

    expect((new Watermark(driverPdo(), driverDialect()))->read())->toBe('2026-01-02 03:00:00');
})->with(['pgsql']);

it('prunes raw rows in batches up to the watermark and the 4x ceiling, and old aggregates', function (string $driver) {
    $pdo = drvSetup($driver);
    $now = driverAt('2026-10-24 12:00:00');
    $ts = fn (string $mod) => $now->modify($mod)->format('Y-m-d H:i:s');

    foreach (['-40 days' => 'd40', '-20 days' => 'd20', '-8 days' => 'd8', '-1 day' => 'd1'] as $mod => $id) {
        drvRequest($pdo, $ts($mod), id: $id);
        drvInsert($pdo, 'psf_monitor_queries', drvQueryRow(['request_id' => $id, 'ts' => $ts($mod)]));
        drvInsert($pdo, 'psf_monitor_exceptions', drvExceptionRow(['request_id' => $id, 'ts' => $ts($mod)]));
        drvInsert($pdo, 'psf_monitor_external', drvExternalRow(['request_id' => $id, 'ts' => $ts($mod)]));
    }
    for ($i = 0; $i < 7; $i++) {
        drvRequest($pdo, $ts('-30 days'), id: "lote{$i}");
    }
    $remaining = fn (string $table) => $pdo->query("SELECT request_id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    // Watermark atrás do teto: o teto (now − 28 dias) vence; lotes de 2 (TOP/LIMIT do dialeto).
    drvInsert($pdo, 'psf_monitor_state', ['name' => 'rollup_watermark', 'value' => $ts('-60 days')]);
    expect((new Pruner($pdo, driverDialect(), 2, 0))->pruneRaw($now, 7))->toBe(8 + 3);
    expect($remaining('psf_monitor_requests'))->toBe(['d20', 'd8', 'd1']);
    foreach (['psf_monitor_queries', 'psf_monitor_exceptions', 'psf_monitor_external'] as $table) {
        expect($remaining($table))->toBe(['d20', 'd8', 'd1']);
    }

    // Watermark acima do teto: não apaga além dele.
    $pdo->exec('DELETE FROM psf_monitor_state');
    drvInsert($pdo, 'psf_monitor_state', ['name' => 'rollup_watermark', 'value' => $ts('-10 days')]);
    expect((new Pruner($pdo, driverDialect(), 5000, 0))->pruneRaw($now, 7))->toBe(4);
    expect($remaining('psf_monitor_requests'))->toBe(['d8', 'd1']);

    // Sem watermark: corta em rawDays.
    $pdo->exec('DELETE FROM psf_monitor_state');
    expect((new Pruner($pdo, driverDialect(), 1, 0))->pruneRaw($now, 7))->toBe(4);
    expect($remaining('psf_monitor_requests'))->toBe(['d1']);

    foreach (['-100 days' => 'a', '-91 days' => 'b', '-89 days' => 'c'] as $mod => $key) {
        drvRollup($pdo, $now->modify($mod)->format('Y-m-d H:00:00'), 'request', $key, 1);
    }
    expect((new Pruner($pdo, driverDialect(), 5000, 0))->pruneAggregates($now, 90))->toBe(2);
    expect($pdo->query('SELECT metric_key FROM psf_monitor_rollups')->fetchAll(PDO::FETCH_COLUMN))->toBe(['c']);
})->with('drivers');
