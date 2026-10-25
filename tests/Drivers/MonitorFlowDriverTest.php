<?php

use Psf\Database\Connect;
use Psf\Database\Create;
use Psf\Database\Delete;
use Psf\Database\Read;
use Psf\Database\Update;
use Psf\Http\HttpResponseException;
use Psf\Monitor\AutoMigrator;
use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Installer;
use Psf\Monitor\Monitor;
use Psf\Monitor\Reader;

/*
| Fluxo completo do Monitor (boot → ganchos → flush) gravando pelo DatabaseSink
| real, com os shims Create/Read/Update/Delete instrumentados numa tabela
| descartável, o scrubber de mensagens contra erros REAIS dos drivers, o
| auto_migrate e o circuit breaker com banco inalcançável.
*/

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-drivers-flow-' . uniqid('', true);
    mkdir($this->dir);
    $this->log = $this->dir . '/error.log';
    $this->previousLog = ini_set('error_log', $this->log);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
});

afterEach(function () {
    if (!isset($this->dir)) {
        return; // suíte pulada (sem PSF_DRIVERS): o beforeEach não rodou
    }
    ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
    $_SERVER = $this->server;
    foreach (glob($this->dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

/** Reinicia o monitor com breaker/finisher de teste e faz o boot. */
function flowBoot(?AutoMigrator $migrator = null, int $failures = 3): CircuitBreaker
{
    Monitor::reset();
    $breaker = new CircuitBreaker('drivers-' . uniqid('', true), $failures, 300, null, test()->dir);
    Monitor::useBreaker($breaker);
    Monitor::useFatalProvider(fn () => null);
    Monitor::useFinisher(function () {
    });
    if ($migrator !== null) {
        Monitor::useAutoMigrator($migrator);
    }
    Monitor::boot('GET');
    return $breaker;
}

/** Linhas do error_log redirecionado que vêm do monitor. */
function flowLogLines(): array
{
    $raw = @file_get_contents(test()->log);
    return $raw === false ? [] : array_values(array_filter(explode("\n", $raw), fn ($l) => str_contains($l, '[PSF][monitor]')));
}

/** Tabela descartável da aplicação (fora do monitor), no banco do teste. */
function flowCreateAppTable(string $driver): void
{
    $id = $driver === 'mysql' ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'BIGSERIAL PRIMARY KEY';
    Connect::getConnection('default')->exec(
        "CREATE TABLE fake_users (id {$id}, name VARCHAR(100) NOT NULL, email VARCHAR(191) NOT NULL UNIQUE, age INT NULL)"
    );
    Connect::listTables('default', true);
}

it('runs the whole flow through the real sink and reads it back', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 1.0]]);
    (new Installer())->install();

    $breaker = flowBoot();
    Monitor::route('users/{id:int}', 'GET', 2);
    Monitor::user(['id' => 42]);
    Monitor::queryEnd(Monitor::queryStart(), "SELECT * FROM users WHERE email = 'ana@x.com' AND id = 5", 'default');
    Monitor::exception(new RuntimeException('falhou ' . str_repeat('ã', 600)));
    $result = Monitor::trackExternal('http', 'api.exemplo.com', fn () => 'ok', fn () => 503);
    Monitor::external('HTTP ', '', 12.5, null);
    expect($result)->toBe('ok');
    $id = Monitor::requestId();

    Monitor::flush(500, 0.0);

    expect($breaker->isOpen())->toBeFalse();
    expect(flowLogLines())->toBe([]);
    $pdo = driverPdo();
    $req = $pdo->query('SELECT * FROM psf_monitor_requests')->fetch(PDO::FETCH_ASSOC);
    expect($req['request_id'])->toBe($id)
        ->and($req['route'])->toBe('users/{id:int}')
        ->and((int) $req['version'])->toBe(2)
        ->and($req['user_id'])->toBe('42')
        ->and((int) $req['status'])->toBe(500)
        ->and((float) $req['sample_weight'])->toBe(1.0);

    $q = $pdo->query('SELECT sql_text, sql_hash, connection_name FROM psf_monitor_queries')->fetch(PDO::FETCH_ASSOC);
    expect($q['sql_text'])->not->toContain('ana@x.com')->toContain('?')
        ->and(strlen($q['sql_hash']))->toBe(40)
        ->and($q['connection_name'])->toBe('default');

    $e = $pdo->query('SELECT exception_class, message, trace FROM psf_monitor_exceptions')->fetch(PDO::FETCH_ASSOC);
    expect($e['exception_class'])->toBe('RuntimeException')
        ->and(mb_strlen($e['message']))->toBe(500)
        ->and($e['trace'])->not->toBe('');

    $x = $pdo->query('SELECT kind, target, status FROM psf_monitor_external ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect(array_column($x, 'kind'))->toBe(['http', 'http'])
        ->and(array_column($x, 'target'))->toBe(['api.exemplo.com', '(invalido)'])
        ->and((int) $x[0]['status'])->toBe(503)
        ->and($x[1]['status'])->toBeNull();

    $detail = (new Reader())->request($id);
    expect(array_map('count', $detail))->toBe(['request' => count($req), 'queries' => 1, 'exceptions' => 1, 'external' => 2]);
})->with('drivers');

it('stores a fractional sampler weight', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 0.3]]);
    (new Installer())->install();

    flowBoot();
    Monitor::flush(200, 0.0);

    expect((string) driverPdo()->query('SELECT sample_weight FROM psf_monitor_requests')->fetchColumn())->toBe('3.3333');
})->with('drivers');

it('instruments Create, Read, Update and Delete on the real driver', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 1.0]]);
    (new Installer())->install();
    flowCreateAppTable($driver);
    flowBoot();

    expect(Create::exe('fake_users', ['name' => 'Ana', 'email' => 'ana@x.com'])->getResult())->toBe('1');
    $batch = Create::batch('fake_users', array_map(fn ($i) => ['name' => "u{$i}", 'email' => "u{$i}@x.com", 'age' => $i], range(1, 501)));
    expect($batch->getResult())->toBe(501);

    $read = (new Read())->exe('fake_users', 'WHERE id = :id', 'id=1');
    expect($read->getResult())->toHaveCount(1)
        ->and($read->getResult()[0]['email'])->toBe('ana@x.com');

    $update = Update::exe('fake_users', ['name' => 'Ana Maria'], 'WHERE id = :id', 'id=1');
    expect($update->getResult())->toBeTrue()->and($update->getRowCount())->toBe(1);

    $delete = Delete::exe('fake_users', 'WHERE id = :id', 'id=2');
    expect($delete->getResult())->toBeTrue()->and($delete->getRowCount())->toBe(1);

    // Erro de execução portável (subquery com mais de uma linha): registrado uma vez, com a exceção.
    expect(fn () => (new Read())->exe('fake_users', 'WHERE id = (SELECT id FROM fake_users)'))
        ->toThrow(HttpResponseException::class);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(7)
        ->and($events['exceptions'])->toHaveCount(1)
        ->and($events['exceptions'][0]['throwable'])->toBeInstanceOf(PDOException::class);

    Monitor::flush(200, 0.0);

    $pdo = driverPdo();
    $texts = $pdo->query('SELECT sql_text FROM psf_monitor_queries ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    expect($texts)->toHaveCount(7);
    foreach (['INSERT', 'INSERT', 'INSERT', 'SELECT', 'UPDATE', 'DELETE', 'SELECT'] as $i => $prefix) {
        expect(strtoupper(ltrim($texts[$i])))->toStartWith($prefix);
        expect($texts[$i])->toContain('fake_users')->not->toContain('ana@x.com');
    }
    expect(driverCount($pdo, 'psf_monitor_exceptions'))->toBe(1);
    expect((string) Connect::getConnection('default')->query('SELECT name FROM fake_users WHERE id = 1')->fetchColumn())->toBe('Ana Maria');
})->with('drivers');

it('scrubs row values from real driver error messages', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 1.0]]);
    (new Installer())->install();
    flowCreateAppTable($driver);
    flowBoot();
    Create::exe('fake_users', ['name' => 'Jane', 'email' => "jane.o'segredo@x.com", 'age' => 98765]);

    $messages = [];
    $attempts = [
        'duplicate key'   => fn () => Create::exe('fake_users', ['name' => 'Outra', 'email' => "jane.o'segredo@x.com"]),
        'not null'        => fn () => Create::exe('fake_users', ['name' => null, 'email' => 'nulo.segredo@x.com', 'age' => 7]),
        'invalid integer' => fn () => Create::exe('fake_users', ['name' => 'Int', 'email' => 'int.segredo@x.com', 'age' => 'segredo-abc']),
    ];
    foreach ($attempts as $label => $attempt) {
        try {
            $attempt();
            $this->fail("Esperava PDOException ({$label})");
        } catch (PDOException $e) {
            $messages[$label] = $e->getMessage();
            Monitor::exception($e);
        }
    }
    // O próprio Update registra a exceção (e responde 500 via explodeException).
    expect(fn () => Update::exe('fake_users', ['age' => 'segredo-upd'], 'WHERE id = :id', 'id=1'))
        ->toThrow(HttpResponseException::class);

    // As mensagens reais trazem os valores (senão o teste não provaria nada).
    expect(implode("\n", $messages))->toContain('segredo');

    Monitor::flush(500, 0.0);

    $rows = driverPdo()->query('SELECT exception_class, message, trace FROM psf_monitor_exceptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect($rows)->toHaveCount(4);
    foreach ($rows as $row) {
        expect($row['exception_class'])->toBe('PDOException')
            ->and($row['message'])->toContain('SQLSTATE[')
            ->and($row['message'])->not->toContain('segredo')
            ->and($row['message'])->not->toContain('Jane')
            ->and($row['message'])->not->toContain('98765')
            ->and($row['trace'])->not->toContain('segredo');
    }
})->with('drivers');

it('installs the schema at runtime with auto_migrate (non-prd, file lock) and writes the batch', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);

    $breaker = flowBoot(new AutoMigrator(new Installer(), 'dev', $this->dir));
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1');
    Monitor::exception(new RuntimeException('x'));
    Monitor::external('http', 'api.exemplo.com', 10.0, 200);
    Monitor::flush(200, 0.0);

    $pdo = driverPdo();
    expect(driverTables($pdo))->toContain(...Installer::TABLES);
    foreach (['psf_monitor_requests', 'psf_monitor_queries', 'psf_monitor_exceptions', 'psf_monitor_external'] as $table) {
        expect(driverCount($pdo, $table))->toBe(1);
    }
    expect($breaker->isOpen())->toBeFalse();
    expect(flowLogLines())->toBe([]);
    expect(glob($this->dir . '/psf-monitor-*.lock'))->toHaveCount(1);

    // prd recusa e abre o breaker com uma linha de log.
    $pdo->exec('DROP TABLE psf_monitor_external');
    $breaker = flowBoot(new AutoMigrator(new Installer(), 'prd', $this->dir));
    Monitor::external('http', 'api.exemplo.com', 10.0, 200);
    Monitor::flush(200, 0.0);
    expect($breaker->isOpen())->toBeTrue();
    expect(flowLogLines())->toHaveCount(1);
    expect(flowLogLines()[0])->toContain('prd');
    expect(driverCount($pdo, 'psf_monitor_requests'))->toBe(1);
})->with('drivers');

it('opens the breaker against an unreachable port without throwing', function (string $driver) {
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 1.0], 'connect_timeout' => 1], ['db' => ['default' => ['port' => 1]]]);

    $breaker = null;
    for ($i = 0; $i < 3; $i++) {
        $breaker ??= flowBoot();
        Monitor::reset();
        Monitor::useBreaker($breaker);
        Monitor::useFatalProvider(fn () => null);
        Monitor::useFinisher(function () {
        });
        Monitor::boot('GET');
        Monitor::flush(200, 0.0);
    }

    expect($breaker->isOpen())->toBeTrue();
    $lines = flowLogLines();
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('PDOException');

    // Aberto: o próximo flush nem tenta conectar.
    $t = microtime(true);
    Monitor::reset();
    Monitor::useBreaker($breaker);
    Monitor::boot('GET');
    Monitor::flush(200, 0.0);
    expect(microtime(true) - $t)->toBeLessThan(0.5);
})->with('drivers');

it('honours the connect timeout against a host that does not answer', function (string $driver) {
    // 10.255.255.1: endereço não roteável (SYN sem resposta); o connect_timeout limita a espera.
    driverUse($driver, ['enabled' => true, 'sample' => ['normal' => 1.0], 'connect_timeout' => 1], ['db' => ['default' => ['host' => '10.255.255.1']]]);
    $breaker = flowBoot(null, 1);

    $t = microtime(true);
    Monitor::flush(200, 0.0);
    $elapsed = microtime(true) - $t;

    expect($breaker->isOpen())->toBeTrue();
    // libpq trata timeouts < 2 s como 2 s; folga para o agendador.
    expect($elapsed)->toBeLessThan(4.5);
})->with('drivers');
