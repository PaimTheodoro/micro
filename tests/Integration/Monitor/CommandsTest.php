<?php

use Psf\Monitor\Command\MonitorInstall;
use Psf\Monitor\Command\MonitorPrune;
use Psf\Monitor\Command\MonitorStatus;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    monitorUseConfig(['enabled' => true]);
});

it('installs the tables', function () {
    $tester = new CommandTester(new MonitorInstall());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())->toContain('Tabelas do monitor instaladas.');
    expect((new Installer())->isInstalled())->toBeTrue();
});

it('reports the status', function () {
    $tester = new CommandTester(new MonitorStatus());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())->toContain('Banco: default')->toContain('Instalado: não')->toContain('Monitor ligado: sim');

    (new Installer())->install();
    $tester->execute([]);
    expect($tester->getDisplay())->toContain('Instalado: sim');
});

it('prunes old rows', function () {
    (new Installer())->install();
    $pdo = Connection::open('default');
    $stmt = $pdo->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES (:r, :ts, :m, :route, 200, 10, 0, 'test', 1)"
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $stmt->execute([':r' => 'old', ':ts' => $now->modify('-10 days')->format('Y-m-d H:i:s'), ':m' => 'GET', ':route' => '/x']);
    $stmt->execute([':r' => 'new', ':ts' => $now->format('Y-m-d H:i:s'), ':m' => 'GET', ':route' => '/x']);

    $tester = new CommandTester(new MonitorPrune());

    expect($tester->execute([]))->toBe(0);
    expect($tester->getDisplay())->toContain('Linhas apagadas: 1');
});

function commandsInsertOld(): void
{
    (new Installer())->install();
    $pdo = Connection::open('default');
    $ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 days')->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES ('old', :ts, 'GET', '/x', 200, 10, 0, 'test', 1)"
    )->execute([':ts' => $ts]);
}

it('refuses an invalid retention.raw_days and deletes nothing', function ($value) {
    monitorUseConfig(['enabled' => true, 'retention' => ['raw_days' => $value]]);
    commandsInsertOld();

    $tester = new CommandTester(new MonitorPrune());

    expect($tester->execute([]))->toBe(1);
    expect($tester->getDisplay())->toContain('retention.raw_days inválido');
    $count = Connection::open('default')->query('SELECT COUNT(*) FROM psf_monitor_requests')->fetchColumn();
    expect((int) $count)->toBe(1);
})->with([0, 'abc', -3, null]);

it('prunes with the monitor disabled and keeps the new row', function () {
    monitorUseConfig(['enabled' => false]);
    commandsInsertOld();
    $pdo = Connection::open('default');
    $pdo->exec("INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) VALUES ('new', '"
        . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s') . "', 'GET', '/x', 200, 10, 0, 'test', 1)");

    $tester = new CommandTester(new MonitorPrune());

    expect($tester->execute(['-e' => 'default']))->toBe(0);
    expect($tester->getDisplay())->toContain('Linhas apagadas: 1');
    expect($pdo->query('SELECT request_id FROM psf_monitor_requests')->fetchAll(PDO::FETCH_COLUMN))->toBe(['new']);
});

it('accepts -e default in status', function () {
    $tester = new CommandTester(new MonitorStatus());

    expect($tester->execute(['-e' => 'default']))->toBe(0);
    expect($tester->getDisplay())->toContain('Banco: default');
});

it('fails with a clear message for an unknown database', function () {
    foreach ([new MonitorInstall(), new MonitorStatus(), new MonitorPrune()] as $command) {
        $tester = new CommandTester($command);

        expect($tester->execute(['-e' => 'nao_existe']))->toBe(1);
        expect($tester->getDisplay())->toContain('nao_existe')->not->toContain('Stack trace');
    }
});

it('registers in a console application', function () {
    $app = new Application();
    $app->addCommands([new MonitorInstall(), new MonitorStatus(), new MonitorPrune()]);

    expect($app->has('monitor:install'))->toBeTrue();
    expect($app->has('monitor:status'))->toBeTrue();
    expect($app->has('monitor:prune'))->toBeTrue();
});
