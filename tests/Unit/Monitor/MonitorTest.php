<?php

use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Config;
use Psf\Monitor\Monitor;
use Psf\Monitor\Sinks\SinkInterface;

/** Sink espião: guarda os lotes recebidos (ou lança, se configurado). */
function monitorSpySink(?\Throwable $throw = null): SinkInterface
{
    return new class ($throw) implements SinkInterface {
        public array $batches = [];
        public int $calls = 0;

        public function __construct(private ?\Throwable $throw)
        {
        }

        public function write(array $batch): void
        {
            $this->calls++;
            if ($this->throw !== null) {
                throw $this->throw;
            }
            $this->batches[] = $batch;
        }
    };
}

/** Liga o monitor com as settings dadas e injeta sink e breaker de teste. */
function monitorStart(array $settings, ?string $method = 'GET'): void
{
    Config::fake(array_replace_recursive(['enabled' => true], $settings));
    Monitor::reset();
    Monitor::useSink(test()->sink);
    Monitor::useBreaker(new CircuitBreaker('test', 3, 300, null, test()->dir));
    Monitor::boot($method);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-monitor-test-' . uniqid();
    mkdir($this->dir);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    $this->sink = monitorSpySink();
});

afterEach(function () {
    $_SERVER = $this->server;
    foreach (glob($this->dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('is a no-op when disabled', function () {
    Config::fake(['enabled' => false]);
    Monitor::useSink($this->sink);
    Monitor::boot();

    expect(Monitor::requestId())->toBeNull();
    Monitor::flush(200);
    expect($this->sink->calls)->toBe(0)
        ->and(Monitor::snapshot())->toBe([]);
});

it('accepts a valid incoming request id', function () {
    expect(Monitor::resolveRequestId('abc-12345678'))->toBe('abc-12345678');
});

it('replaces hostile request ids', function (string $incoming) {
    expect(Monitor::resolveRequestId($incoming))->toMatch('/^[0-9a-f]{32}$/');
})->with([
    'crlf'       => ["abc\r\nX: y"],
    'curto'      => ['abc'],
    'longo'      => [str_repeat('a', 65)],
    'não ascii'  => ['ábcdefgh12'],
    'newline'    => ["abcdefgh12\n"],
]);

it('uses the incoming X-Request-Id at boot', function () {
    $_SERVER['HTTP_X_REQUEST_ID'] = 'incoming-req-0001';
    monitorStart([]);
    expect(Monitor::requestId())->toBe('incoming-req-0001');
});

it('exposes the request id header only when enabled', function () {
    monitorStart([]);
    expect(Monitor::responseHeaders())->toBe(['X-Request-Id' => Monitor::requestId()]);

    monitorStart(['expose_request_id' => false]);
    expect(Monitor::responseHeaders())->toBe([]);

    Config::fake(['enabled' => false]);
    Monitor::reset();
    expect(Monitor::responseHeaders())->toBe([]);
});

it('writes one request row', function () {
    monitorStart(['sample' => ['normal' => 1.0]], 'GET');
    Monitor::route('users/{id:int}', 'GET', 1);
    Monitor::user((object) ['id' => 42]);
    Monitor::flush(200, 0.0);

    expect($this->sink->batches)->toHaveCount(1);
    $rows = $this->sink->batches[0]['requests'];
    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row['method'])->toBe('GET')
        ->and($row['route'])->toBe('users/{id:int}')
        ->and($row['version'])->toBe(1)
        ->and($row['status'])->toBe(200)
        ->and($row['user_id'])->toBe('42')
        ->and($row['env'])->toBe('dev')
        ->and($row['sample_weight'])->toBe(1.0)
        ->and($row['request_id'])->toBe(Monitor::requestId())
        ->and($row['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($row['memory_kb'])->toBeGreaterThan(0)
        ->and($row['dropped_events'])->toBe(0)
        ->and($row['ts'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('flushes only once per boot', function () {
    monitorStart(['sample' => ['normal' => 1.0]]);
    Monitor::flush(200, 0.0);
    Monitor::flush(200, 0.0);
    expect($this->sink->calls)->toBe(1);
});

it('records unmatched requests with the boot method', function () {
    monitorStart(['sample' => ['normal' => 1.0]], 'POST');
    Monitor::flush(404, 0.0);

    $row = $this->sink->batches[0]['requests'][0];
    expect($row['route'])->toBe('(unmatched)')
        ->and($row['method'])->toBe('POST')
        ->and($row['version'])->toBe(0);
});

it('route and user work without boot', function () {
    Config::fake(['enabled' => true]);
    Monitor::route('users', 'GET', 2);
    Monitor::user(['id' => 5]);

    expect(Monitor::snapshot())->toMatchArray([
        'route' => 'users', 'method' => 'GET', 'version' => 2, 'user_id' => '5',
    ]);
});

it('keeps errors even when sampled out', function () {
    monitorStart(['sample' => ['normal' => 0]]);
    Monitor::flush(500);
    expect($this->sink->batches)->toHaveCount(1)
        ->and($this->sink->batches[0]['requests'][0]['sample_weight'])->toBe(1.0);

    monitorStart(['sample' => ['normal' => 0]]);
    Monitor::flush(200);
    expect($this->sink->batches)->toHaveCount(1);
});

it('skips CORS preflight', function () {
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
    monitorStart(['sample' => ['normal' => 1.0]], 'OPTIONS');
    Monitor::flush(204, 0.0);
    expect($this->sink->calls)->toBe(0);
});

it('truncates the route label', function () {
    monitorStart(['sample' => ['normal' => 1.0]]);
    Monitor::route(str_repeat('a', 300), 'GET', 1);
    Monitor::flush(200, 0.0);
    expect(strlen($this->sink->batches[0]['requests'][0]['route']))->toBe(191);
});

it('resolves the user id defensively', function (array $settings, mixed $auth, ?string $expected) {
    Config::fake(array_replace(['enabled' => true], $settings));
    Monitor::user($auth);
    expect(Monitor::snapshot()['user_id'])->toBe($expected);
})->with([
    'objeto com id'    => [[], (object) ['id' => 42], '42'],
    'array com id'     => [[], ['id' => 7], '7'],
    'objeto sem id'    => [[], (object) ['nome' => 'x'], null],
    'id não escalar'   => [[], ['id' => ['x']], null],
    'id booleano'      => [[], ['id' => true], null],
    'id longo'         => [[], ['id' => str_repeat('9', 100)], str_repeat('9', 64)],
    'store_user false' => [['store_user' => false], ['id' => 7], null],
    'propriedade'      => [['user_id_property' => 'uid'], (object) ['uid' => 'u-1'], 'u-1'],
]);

it('never throws and opens the breaker after repeated sink failures', function () {
    $log = $this->dir . '/error.log';
    $previous = ini_set('error_log', $log);
    $this->sink = monitorSpySink(new \PDOException('SQLSTATE[HY000]: no such table: psf_monitor_requests'));

    try {
        for ($i = 0; $i < 4; $i++) {
            monitorStart(['sample' => ['normal' => 1.0]]);
            Monitor::flush(200, 0.0);
        }
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
    }

    $content = (string) @file_get_contents($log);
    expect($this->sink->calls)->toBe(3)
        ->and(substr_count($content, '[PSF][monitor]'))->toBe(1)
        ->and($content)->toContain('monitor:install');
});

it('sanitizes the method to the column size', function (string $method, string $expected) {
    monitorStart(['sample' => ['normal' => 1.0]], $method);
    Monitor::flush(404, 0.0);
    expect($this->sink->batches[0]['requests'][0]['method'])->toBe($expected);
})->with([
    'longo'        => [str_repeat('PATCH', 10), 'PATCHPATCH'],
    'crlf'         => ["POST\r\nX: y", 'POSTXY'],
    'só símbolos'  => ['!!!-@', 'GET'],
    'minúsculo'    => ['delete', 'DELETE'],
]);

it('sanitizes the method given to route()', function () {
    monitorStart(['sample' => ['normal' => 1.0]]);
    Monitor::route('users', str_repeat('Z', 40), 1);
    Monitor::flush(200, 0.0);
    expect($this->sink->batches[0]['requests'][0]['method'])->toBe(str_repeat('Z', 10));
});

it('cuts env and app_release to the column size', function () {
    monitorUseConfig([], ['environment' => str_repeat('é', 30)]);
    monitorStart(['sample' => ['normal' => 1.0], 'release' => str_repeat('ã', 100)]);
    Monitor::flush(200, 0.0);
    $row = $this->sink->batches[0]['requests'][0];

    expect($row['env'])->toBe(str_repeat('é', 20))
        ->and($row['app_release'])->toBe(str_repeat('ã', 64));
});

it('cuts multibyte route and user id without splitting characters', function () {
    monitorStart(['sample' => ['normal' => 1.0]]);
    Monitor::route('a' . str_repeat('é', 300), 'GET', 1);
    Monitor::user(['id' => 'b' . str_repeat('ç', 100)]);
    Monitor::flush(200, 0.0);
    $row = $this->sink->batches[0]['requests'][0];

    expect(mb_check_encoding($row['route'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($row['route'], 'UTF-8'))->toBe(191)
        ->and(mb_check_encoding($row['user_id'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($row['user_id'], 'UTF-8'))->toBe(64);
});

it('returns null for a misconfigured user_id_property', function (mixed $property) {
    Config::fake(['enabled' => true, 'user_id_property' => $property]);
    Monitor::user(['id' => 7, 0 => 9]);
    expect(Monitor::snapshot()['user_id'])->toBeNull();
})->with([
    'array'  => [['id']],
    'vazio'  => [''],
    'bool'   => [true],
    'null'   => [null],
]);

it('accepts an int user_id_property', function () {
    Config::fake(['enabled' => true, 'user_id_property' => 0]);
    Monitor::user([0 => 9]);
    expect(Monitor::snapshot()['user_id'])->toBe('9');
});

it('never throws when the auth object misbehaves', function () {
    Config::fake(['enabled' => true]);
    $auth = new class {
        public function __isset($name): bool
        {
            return true;
        }

        public function __get($name): mixed
        {
            throw new \RuntimeException('boom');
        }
    };
    Monitor::user($auth);
    expect(Monitor::snapshot()['user_id'])->toBeNull();
});

it('finishes the response only for requests that will be written', function (array $settings, ?string $method, int $status, bool $openBreaker) {
    if ($method === 'OPTIONS') {
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
    }
    monitorStart($settings, $method);
    if ($openBreaker) {
        $breaker = new CircuitBreaker('test', 3, 300, null, $this->dir);
        $breaker->trip();
        Monitor::useBreaker($breaker);
    }
    $finished = 0;
    Monitor::useFinisher(function () use (&$finished) {
        $finished++;
    });

    Monitor::flush($status, 0.99);

    expect($finished)->toBe(0)
        ->and($this->sink->calls)->toBe(0);
})->with([
    'preflight'      => [['sample' => ['normal' => 1.0]], 'OPTIONS', 204, false],
    'fora da amostra' => [['sample' => ['normal' => 0.1]], 'GET', 200, false],
    'breaker aberto' => [['sample' => ['normal' => 1.0]], 'GET', 200, true],
]);

it('finishes the response right before writing a recorded request', function () {
    monitorStart(['sample' => ['normal' => 1.0]]);
    $callsAtFinish = null;
    Monitor::useFinisher(function () use (&$callsAtFinish) {
        $callsAtFinish = $this->sink->calls;
    });

    Monitor::flush(200, 0.0);

    expect($callsAtFinish)->toBe(0)
        ->and($this->sink->calls)->toBe(1);
});

it('never throws from boot and leaves the monitor safely not booted', function () {
    Config::fake(['enabled' => true, 'sample' => ['normal' => 1.0]]);
    Monitor::reset();
    Monitor::useSink($this->sink);
    Monitor::useBreaker(new CircuitBreaker('test', 3, 300, null, $this->dir));
    Monitor::useIdGenerator(function (): string {
        throw new \Exception('sem fonte de entropia');
    });

    Monitor::boot('GET');

    expect(Monitor::requestId())->toBeNull()
        ->and(Monitor::responseHeaders())->toBe([]);

    Monitor::flush(200, 0.0);
    expect($this->sink->calls)->toBe(0);
});

it('never throws from boot when the request id generator emits a warning', function () {
    Config::fake(['enabled' => true, 'sample' => ['normal' => 1.0]]);
    Monitor::reset();
    Monitor::useSink($this->sink);
    Monitor::useIdGenerator(function (): string {
        throw new \ErrorException('Warning convertido pelo handler do host');
    });

    expect(fn () => Monitor::boot('GET'))->not->toThrow(\Throwable::class);
    Monitor::flush(500);
    expect($this->sink->calls)->toBe(0);
});
