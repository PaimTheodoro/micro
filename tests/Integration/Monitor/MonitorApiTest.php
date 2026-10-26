<?php

use Psf\Monitor\Api\MonitorApi;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Monitor;
use Psf\Monitor\Reader;
use Psf\Monitor\Rollup;
use Psf\Database\Dialect\DialectFactory;

/** PDO que falha em qualquer acesso: prova que nada foi consultado. */
function mapiExplodingPdo(string $message = 'boom'): PDO
{
    return new class ($message) extends PDO {
        public int $calls = 0;

        public function __construct(private string $msg)
        {
            parent::__construct('sqlite::memory:');
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->calls++;
            throw new PDOException($this->msg);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $this->calls++;
            throw new PDOException($this->msg);
        }

        public function exec(string $statement): int|false
        {
            $this->calls++;
            throw new PDOException($this->msg);
        }
    };
}

function mapiSeed(PDO $pdo, DateTimeImmutable $now): void
{
    $ts = $now->modify('-3 hours')->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO psf_monitor_requests (request_id, ts, method, route, status, duration_ms, memory_kb, env, sample_weight) '
        . "VALUES ('req-12345678', :ts, 'GET', 'users/{id:int}', 200, 120, 0, 'test', 1)"
    )->execute([':ts' => $ts]);
    $pdo->prepare(
        'INSERT INTO psf_monitor_queries (request_id, ts, sql_hash, sql_text, duration_ms, connection_name, sample_weight) '
        . "VALUES ('req-12345678', :ts, 'h1', 'SELECT 1', 30, 'default', 1)"
    )->execute([':ts' => $ts]);
    $pdo->prepare(
        'INSERT INTO psf_monitor_exceptions (request_id, ts, exception_class, message, file, line_no, trace_hash, trace, sample_weight) '
        . "VALUES ('req-12345678', :ts, 'RuntimeException', 'x', 'f.php', 1, 'th', 't', 1)"
    )->execute([':ts' => $ts]);
    $pdo->prepare(
        'INSERT INTO psf_monitor_external (request_id, ts, kind, target, duration_ms, status, sample_weight) '
        . "VALUES ('req-12345678', :ts, 'http', 'api.com', 50, 200, 1)"
    )->execute([':ts' => $ts]);
}

beforeEach(function () {
    monitorUseConfig(['enabled' => true, 'api' => ['enabled' => true]]);
    (new Installer())->install();
    $this->pdo = Connection::open('default');
    $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    mapiSeed($this->pdo, $this->now);
    (new Rollup($this->pdo, DialectFactory::fromConfig(\PSF::config('db', 'default'))))->run($this->now);
    $this->api = new MonitorApi(new Reader('default', $this->pdo));
});

afterEach(function () {
    Config::reset();
    Monitor::reset();
});

it('is disabled by default', function () {
    monitorUseConfig(['enabled' => true]);
    $spy = mapiExplodingPdo();
    $api = new MonitorApi(new Reader('default', $spy));

    foreach (['routes', 'queries', 'exceptions', 'external', 'throughput', 'request', 'nada'] as $endpoint) {
        expect($api->handle($endpoint, ['id' => 'req-12345678']))->toBe(['API do monitor desabilitada', [], 404]);
    }
    expect($spy->calls)->toBe(0);
});

it('serves every endpoint with the controller response shape', function (string $endpoint, array $params) {
    $res = $this->api->handle($endpoint, $params);

    expect($res)->toHaveCount(3);
    expect($res[0])->toBe('');
    expect($res[1])->toBeArray()->not->toBeEmpty();
    expect($res[2])->toBe(200);
})->with([
    ['routes', []],
    ['routes', ['orderBy' => 'hits', 'limit' => '5']],
    ['queries', ['limit' => 10]],
    ['exceptions', []],
    ['external', ['orderBy' => 'avgMs']],
    ['throughput', []],
    ['request', ['id' => 'req-12345678']],
]);

it('returns the request detail', function () {
    [, $data] = $this->api->handle('request', ['id' => 'req-12345678']);
    expect($data)->toHaveKeys(['request', 'queries', 'exceptions', 'external']);
    expect($data['queries'])->toHaveCount(1);
});

it('defaults the range to the last 24 hours', function () {
    // Linha de 3 h atrás entra; com from/to explícitos fora dela, não.
    expect($this->api->handle('routes', [])[1])->toHaveCount(1);

    $to   = $this->now->modify('-5 hours')->format('c');
    $from = $this->now->modify('-10 hours')->format('c');
    expect($this->api->handle('routes', ['from' => $from, 'to' => $to])[1])->toBe([]);

    $old = $this->now->modify('-30 hours')->format('c');
    $mid = $this->now->modify('-26 hours')->format('c');
    expect($this->api->handle('routes', ['from' => $old, 'to' => $mid])[1])->toBe([]);
});

it('rejects hostile parameters with 422', function (string $endpoint, array $params) {
    $res = $this->api->handle($endpoint, $params);
    expect($res[2])->toBe(422);
    expect($res[1])->toBe([]);
})->with([
    ['routes', ['from' => 'lixo']],
    ['routes', ['to' => '2026-13-45']],
    ['routes', ['to' => '2026-02-30 10:00:00']],
    ['routes', ['from' => 'now']],
    ['routes', ['from' => '-1 day']],
    ['routes', ['from' => '99999-01-01T00:00:00Z']],
    ['routes', ['from' => '2026-10-24T10:00:00Z', 'to' => '2026-10-24T10:00:00Z']],
    ['routes', ['from' => '2026-10-25T10:00:00Z', 'to' => '2026-10-24T10:00:00Z']],
    ['routes', ['limit' => 'abc']],
    ['routes', ['limit' => 0]],
    ['routes', ['limit' => 1001]],
    ['routes', ['limit' => '-1']],
    ['routes', ['limit' => "5\n"]],
    ['routes', ['order_by' => 'sum_ms; DROP TABLE x']],
    ['external', ['orderBy' => 'nada']],
    ['request', ['id' => '']],
    ['request', ['id' => "a'b"]],
    ['request', ['id' => 'curto']],
    ['request', ['id' => "req-12345678\n"]],
    ['request', ['id' => str_repeat('a', 100)]],
    ['request', []],
]);

it('ignores order_by outside routes and external', function () {
    expect($this->api->handle('queries', ['orderBy' => 'lixo'])[2])->toBe(200);
});

it('returns 404 for an unknown request id and an unknown endpoint', function () {
    expect($this->api->handle('request', ['id' => 'inexistente-1']))->toBe(['Requisição não encontrada', [], 404]);
    expect($this->api->handle('foo', []))->toBe(['Endpoint desconhecido', [], 404]);
});

it('does not leak database errors', function () {
    $dsn = 'mysql:host=secret-host;dbname=segredo';
    $api = new MonitorApi(new Reader('default', mapiExplodingPdo("SQLSTATE[HY000] {$dsn} password=hunter2")));

    foreach (['routes', 'queries', 'exceptions', 'external', 'throughput', 'request'] as $endpoint) {
        $res = $api->handle($endpoint, ['id' => 'req-12345678']);
        expect($res)->toBe(['Erro ao consultar o monitor', [], 500]);
        expect(json_encode($res))->not->toContain('secret-host')->not->toContain('hunter2');
    }
});

it('converts time zones to UTC', function () {
    // Mesmo instante em fusos diferentes dá o mesmo resultado.
    $at = $this->now->modify('-3 hours');
    $from = $at->modify('-1 hour');
    $to   = $at->modify('+1 hour');

    $utc = $this->api->handle('routes', ['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z')]);
    $tz  = new DateTimeZone('-03:00');
    $off = $this->api->handle('routes', [
        'from' => $from->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
        'to'   => $to->setTimezone($tz)->format('c'),
    ]);
    $naive = $this->api->handle('routes', ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);

    expect($utc[2])->toBe(200);
    expect($utc[1])->toHaveCount(1);
    expect($off)->toBe($utc);
    expect($naive)->toBe($utc);

    // Interpretado como -03:00 em vez de UTC, a janela deixa de conter a linha.
    $wrong = $this->api->handle('routes', [
        'from' => $from->format('Y-m-d\TH:i:s') . '-03:00',
        'to'   => $to->format('Y-m-d\TH:i:s') . '-03:00',
    ]);
    expect($wrong[1])->toBe([]);
});

it('never throws', function (mixed $value) {
    foreach (['from', 'to', 'limit', 'orderBy', 'id'] as $key) {
        foreach (['routes', 'external', 'request', 'throughput'] as $endpoint) {
            $res = $this->api->handle($endpoint, [$key => $value, 'id' => $key === 'id' ? $value : 'req-12345678']);
            expect($res[2])->toBeIn([200, 404, 422]);
        }
    }
    // Valores presentes porém inválidos viram 422 nos parâmetros usados.
    expect($this->api->handle('routes', ['limit' => $value])[2])->toBe(422);
    expect($this->api->handle('routes', ['from' => $value])[2])->toBe(422);
    expect($this->api->handle('request', ['id' => $value])[2])->toBe(422);
})->with([
    'array'  => [[1, 2]],
    'null'   => [null],
    'object' => [new stdClass()],
    'true'   => [true],
    'false'  => [false],
    'float'  => [1.5],
]);

it('logs only the exception class on the generic 500', function () {
    $log = tempnam(sys_get_temp_dir(), 'psflog');
    $old = ini_set('error_log', $log);
    try {
        $api = new MonitorApi(new Reader('default', mapiExplodingPdo('SQLSTATE[HY000] mysql:host=secret-host;dbname=segredo password=hunter2')));
        $res = $api->handle('routes', []);
    } finally {
        ini_set('error_log', $old === false ? '' : $old);
    }
    $out = (string) file_get_contents($log);
    @unlink($log);

    expect($res)->toBe(['Erro ao consultar o monitor', [], 500]);
    expect($out)->toContain('[PSF][monitor]')->toContain('PDOException')
        ->not->toContain('secret-host')->not->toContain('hunter2')->not->toContain('segredo');
    expect(substr_count($out, '[PSF][monitor]'))->toBe(1);
});

it('still accepts the legacy order_by parameter and snake_case values', function () {
    expect($this->api->handle('routes', ['order_by' => 'avg_ms'])[2])->toBe(200)
        ->and($this->api->handle('routes', ['order_by' => 'sum_ms; DROP TABLE x'])[2])->toBe(422)
        ->and($this->api->handle('routes', ['orderBy' => 'hits', 'order_by' => 'lixo'])[2])->toBe(200);
});

it('returns camelCase keys in the request detail', function () {
    [, $data] = $this->api->handle('request', ['id' => 'req-12345678']);

    expect($data['request'])->toHaveKeys(['requestId', 'durationMs', 'sampleWeight'])
        ->and($data['queries'][0])->toHaveKey('sqlText');
});
