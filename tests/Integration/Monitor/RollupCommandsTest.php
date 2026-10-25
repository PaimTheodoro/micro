<?php

use Psf\Monitor\Command\MonitorInstall;
use Psf\Monitor\Command\MonitorMaintain;
use Psf\Monitor\Command\MonitorPrune;
use Psf\Monitor\Command\MonitorRollup;
use Psf\Monitor\Command\MonitorStatus;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

function rcInsertRaw(string $id, string $modify): void
{
    $ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d H:i:s');
    Connection::open('default')->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES (:r, :ts, 'GET', '/x', 200, 10, 0, 'test', 1)"
    )->execute([':r' => $id, ':ts' => $ts]);
}

function rcInsertAggregate(string $modify): void
{
    $ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d H:i:00');
    Connection::open('default')->prepare(
        'INSERT INTO psf_monitor_rollups (bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram) '
        . "VALUES (:h, 'request', 'GET /velho', 1, 0, 10, 10, '[]')"
    )->execute([':h' => $ts]);
}

function rcRollupCount(): int
{
    return (int) Connection::open('default')->query('SELECT COUNT(*) FROM psf_monitor_rollups')->fetchColumn();
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
    (new Installer())->install();
});

it('runs the rollup and reports progress', function () {
    rcInsertRaw('a', '-3 hours');
    $tester = new CommandTester(new MonitorRollup());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())
        ->toMatch('/Horas agregadas: [1-9]\d*/')
        ->toMatch('/Registros gravados: [1-9]\d*/')
        ->toMatch('/Rollup até: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
    expect(rcRollupCount())->toBeGreaterThan(0);
});

it('rejects an invalid --max-hours', function ($value) {
    rcInsertRaw('a', '-3 hours');
    $tester = new CommandTester(new MonitorRollup());

    expect($tester->execute(['--max-hours' => $value]))->toBe(1);
    expect($tester->getDisplay())->toContain('max-hours')->not->toContain('Stack trace');
    expect(rcRollupCount())->toBe(0);
    expect(rcRawCount())->toBe(1);
})->with(['0', 'abc', '-2', '1.5', '10001', '99999999999999999999']);

it('maintain runs rollup then prune', function () {
    rcInsertRaw('old', '-30 days');
    rcInsertAggregate('-100 days');
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())
        ->toMatch('/Horas agregadas: [1-9]\d*/')
        ->toMatch('/Linhas apagadas: [1-9]\d*/')
        ->toContain('Agregados apagados: 1');
    $pdo = Connection::open('default');
    expect((int) $pdo->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn())->toBe(0);
    expect(rcRollupCount())->toBeGreaterThan(0);
    expect((int) $pdo->query("SELECT COUNT(*) FROM psf_monitor_rollups WHERE metric_key = 'GET /velho'")->fetchColumn())->toBe(0);
});

it('maintain refuses invalid retention before writing anything', function ($key) {
    monitorUseConfig(['enabled' => true, 'retention' => [$key => 0]]);
    (new Installer())->install();
    rcInsertRaw('a', '-3 hours');
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute([]))->toBe(1);
    expect($tester->getDisplay())->toContain("retention.{$key} inválido");
    expect(rcRollupCount())->toBe(0);
})->with(['aggregate_days', 'raw_days']);

it('prune keeps its existing output and adds the aggregates line', function () {
    rcInsertRaw('old', '-10 days');
    rcInsertRaw('new', 'now');
    $tester = new CommandTester(new MonitorPrune());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())->toContain('Linhas apagadas: 1')->toContain('Agregados apagados: 0');
    expect(strpos($tester->getDisplay(), 'Linhas apagadas'))->toBeLessThan(strpos($tester->getDisplay(), 'Agregados apagados'));
});

it('prune refuses an invalid retention.aggregate_days and deletes nothing', function () {
    monitorUseConfig(['enabled' => true, 'retention' => ['aggregate_days' => 'abc']]);
    (new Installer())->install();
    rcInsertRaw('old', '-10 days');
    rcInsertAggregate('-100 days');
    $tester = new CommandTester(new MonitorPrune());

    expect($tester->execute([]))->toBe(1);
    expect($tester->getDisplay())->toContain('retention.aggregate_days inválido');
    expect((int) Connection::open('default')->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn())->toBe(1);
    expect(rcRollupCount())->toBe(1);
});

it('status shows the rollup watermark', function () {
    $tester = new CommandTester(new MonitorStatus());
    $tester->execute([]);
    expect($tester->getDisplay())->toContain('Rollup até: nunca');

    rcInsertRaw('a', '-3 hours');
    (new CommandTester(new MonitorRollup()))->execute([]);
    $tester->execute([]);
    expect($tester->getDisplay())->toMatch('/Rollup até: \d{4}-\d{2}-\d{2} /');
});

it('registers the new commands', function () {
    $app = new Application();
    $app->addCommands([
        new MonitorInstall(), new MonitorStatus(), new MonitorPrune(), new MonitorRollup(), new MonitorMaintain(),
    ]);

    expect($app->has('monitor:rollup'))->toBeTrue();
    expect($app->has('monitor:maintain'))->toBeTrue();
});

function rcRawCount(): int
{
    return (int) Connection::open('default')->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn();
}

it('maintain rejects an invalid --max-hours and touches nothing', function ($value) {
    rcInsertRaw('a', '-30 days');
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute(['--max-hours' => $value]))->toBe(1);
    expect($tester->getDisplay())->toContain('max-hours')->not->toContain('Stack trace');
    expect(rcRollupCount())->toBe(0);
    expect(rcRawCount())->toBe(1);
})->with(['0', 'abc', '99999999999999999999', '10001']);

it('rollup and maintain fail clearly for an unknown database', function () {
    foreach ([new MonitorRollup(), new MonitorMaintain()] as $command) {
        $tester = new CommandTester($command);

        expect($tester->execute(['-e' => 'nao_existe']))->toBe(1);
        expect($tester->getDisplay())->toContain('nao_existe')->not->toContain('Stack trace');
    }
});

it('maintain still prunes when the rollup fails and exits 1', function () {
    rcInsertRaw('old', '-30 days');
    rcInsertRaw('new', '-1 hour');
    // Sem a tabela de estado o rollup falha ao gravar o watermark (a leitura vê "sem watermark").
    Connection::open('default')->exec('DROP TABLE psf_monitor_state');
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute([]))->toBe(1);
    $display = $tester->getDisplay();
    expect($display)
        ->toContain('Falha ao agregar o monitor')
        ->toContain('Linhas apagadas: 1')
        ->toContain('Agregados apagados:')
        ->not->toContain('Horas agregadas:')
        ->not->toContain('Stack trace');
    expect(rcRawCount())->toBe(1);
});

it('maintain reports both failures when rollup and prune fail', function () {
    rcInsertRaw('old', '-30 days');
    $pdo = Connection::open('default');
    $pdo->exec('DROP TABLE psf_monitor_state');
    $pdo->exec("CREATE TRIGGER rc_no_delete BEFORE DELETE ON psf_monitor_requests BEGIN SELECT RAISE(ABORT, 'boom'); END");
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute([]))->toBe(1);
    expect($tester->getDisplay())
        ->toContain('Falha ao agregar o monitor')
        ->toContain('Falha ao limpar o monitor')
        ->toContain('boom')
        ->not->toContain('Linhas apagadas')
        ->not->toContain('Stack trace');
    expect(rcRawCount())->toBe(1);
});

it('maintain exits 1 after the rollup lines when the prune fails', function () {
    rcInsertRaw('old', '-30 days');
    Connection::open('default')->exec(
        "CREATE TRIGGER rc_no_delete BEFORE DELETE ON psf_monitor_requests BEGIN SELECT RAISE(ABORT, 'boom'); END"
    );
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute([]))->toBe(1);
    expect($tester->getDisplay())
        ->toContain('Horas agregadas:')->toContain('Registros gravados:')->toContain('Rollup até:')
        ->toContain('Falha')->not->toContain('Linhas apagadas')->not->toContain('Stack trace');
    expect(rcRollupCount())->toBeGreaterThan(0);
});

it('refuses out-of-range retention before any write', function (string $key, $value) {
    monitorUseConfig(['enabled' => true, 'retention' => [$key => $value]]);
    (new Installer())->install();
    rcInsertRaw('old', '-30 days');

    foreach ([new MonitorMaintain(), new MonitorPrune()] as $command) {
        $tester = new CommandTester($command);

        expect($tester->execute([]))->toBe(1);
        expect($tester->getDisplay())->toContain("retention.{$key} inválido")->not->toContain('Stack trace');
    }
    expect(rcRollupCount())->toBe(0);
    expect(rcRawCount())->toBe(1);
})->with([
    ['raw_days', '99999999999999999999'],
    ['raw_days', 36501],
    ['aggregate_days', '99999999999999999999'],
    ['aggregate_days', 36501],
    ['raw_days', "7\n"],
]);

it('accepts the retention ceiling of 36500 days', function () {
    monitorUseConfig(['enabled' => true, 'retention' => ['raw_days' => 36500, 'aggregate_days' => 36500]]);
    (new Installer())->install();
    $tester = new CommandTester(new MonitorMaintain());

    expect($tester->execute([]))->toBe(0);
    expect((new CommandTester(new MonitorPrune()))->execute([]))->toBe(0);
});

it('status prints "nunca" and still succeeds when the tables are missing', function () {
    monitorUseConfig(['enabled' => true]);
    $tester = new CommandTester(new MonitorStatus());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())
        ->toContain('Banco: default')->toContain('Instalado: não')->toContain('Monitor ligado: sim')->toContain('Rollup até: nunca');
});

it('rollup and maintain report skipped hours and still succeed', function () {
    rcInsertRaw('a', '-3 hours');
    // Gatilho do SQLite: RAISE(ABORT) devolve SQLSTATE 23000 (violação de integridade).
    Connection::open('default')->exec(
        "CREATE TRIGGER rc_dup BEFORE INSERT ON psf_monitor_rollups BEGIN SELECT RAISE(ABORT, 'dup'); END"
    );

    foreach ([new MonitorRollup(), new MonitorMaintain()] as $command) {
        Connection::open('default')->exec('DELETE FROM psf_monitor_state');
        $tester = new CommandTester($command);

        expect($tester->execute([]))->toBe(0);
        expect($tester->getDisplay())
            ->toMatch('/Horas puladas: [1-9]\d*/')
            ->toContain('brutas')
            ->toMatch('/Rollup até: \d{4}-/')
            ->not->toContain('Stack trace');
    }
});

it('does not print skipped hours when none were skipped', function () {
    rcInsertRaw('a', '-3 hours');
    $tester = new CommandTester(new MonitorRollup());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())->not->toContain('Horas puladas');
});

it('uses a single retention ceiling for the commands and the pruner', function () {
    expect(\Psf\Monitor\Command\CommandOptions::MAX_RETENTION_DAYS)->toBe(\Psf\Monitor\Pruner::MAX_RAW_DAYS);
});

it('hints at monitor:install when a phase-3 install lacks psf_monitor_external', function (string $class) {
    Connection::open('default')->exec('DROP TABLE psf_monitor_external');
    $tester = new CommandTester(new $class());

    expect($tester->execute([]))->toBe(1);
    expect($tester->getDisplay())->toContain('Tabelas do monitor ausentes ou desatualizadas: rode monitor:install.');
})->with([MonitorRollup::class, MonitorPrune::class, MonitorMaintain::class]);

it('does not print the install hint for unrelated errors', function (string $class) {
    $tester = new CommandTester(new $class());

    expect($tester->execute(['-e' => 'nao-existe']))->toBe(1);
    expect($tester->getDisplay())->not->toContain('rode monitor:install');
})->with([MonitorRollup::class, MonitorPrune::class, MonitorMaintain::class]);

it('does not print the install hint for invalid options', function () {
    $tester = new CommandTester(new MonitorRollup());

    expect($tester->execute(['--max-hours' => 'abc']))->toBe(1);
    expect($tester->getDisplay())->not->toContain('rode monitor:install');
});
