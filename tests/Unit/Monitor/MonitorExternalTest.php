<?php

use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Config;
use Psf\Monitor\Monitor;
use Psf\Monitor\Sampler;
use Psf\Monitor\Sinks\SinkInterface;

/** Sink espião: guarda os lotes recebidos. */
function monitorExternalSpySink(): SinkInterface
{
    return new class implements SinkInterface {
        public array $batches = [];

        public function write(array $batch): void
        {
            $this->batches[] = $batch;
        }
    };
}

/** Liga o monitor com as settings dadas, injeta sink/breaker e faz o boot. */
function monitorExternalStart(array $settings): void
{
    Config::fake(array_replace_recursive(['enabled' => true], $settings));
    Monitor::reset();
    Monitor::useSink(test()->sink);
    Monitor::useBreaker(new CircuitBreaker('test', 3, 300, null, test()->dir));
    Monitor::useFatalProvider(fn () => null);
    Monitor::useFinisher(function () {
    });
    Monitor::boot('GET');
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-monitor-external-' . uniqid('', true);
    mkdir($this->dir);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    $this->sink = monitorExternalSpySink();
});

afterEach(function () {
    Monitor::reset();
    Config::reset();
    $_SERVER = $this->server;
    foreach (glob($this->dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('records an external call with the safe target', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::external('http', 'api.exemplo.com', 120.5, 200);
    Monitor::flush(200, 0.0);

    expect($this->sink->batches)->toHaveCount(1);
    $batch   = $this->sink->batches[0];
    $request = $batch['requests'][0];

    expect($batch['external'])->toHaveCount(1);
    $row = $batch['external'][0];
    expect(array_keys($row))->toBe(['request_id', 'ts', 'kind', 'target', 'duration_ms', 'status', 'sample_weight'])
        ->and($row['request_id'])->toBe($request['request_id'])
        ->and($row['kind'])->toBe('http')
        ->and($row['target'])->toBe('api.exemplo.com')
        ->and($row['duration_ms'])->toBe(120.5)
        ->and($row['status'])->toBe(200)
        ->and($row['sample_weight'])->toBe($request['sample_weight'])
        ->and($row['ts'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('does not add the external key when there are no external calls', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::flush(200, 0.0);

    expect($this->sink->batches[0])->not->toHaveKey('external');
});

it('keeps a request with a slow external call even if dropped', function () {
    monitorExternalStart(['sample' => ['normal' => 0.0], 'maxEventsPerRequest' => 1]);

    Monitor::external('http', 'rapido.exemplo.com', 10.0, 200);
    Monitor::externalEnd(hrtime(true) - 1_500_000_000, 'http', 'lento.exemplo.com', 200);
    Monitor::flush(200, 0.99);

    expect($this->sink->batches)->toHaveCount(1);
    $batch = $this->sink->batches[0];
    expect($batch['requests'][0]['sample_weight'])->toBe(1.0)
        ->and($batch['requests'][0]['dropped_events'])->toBe(1)
        ->and($batch['external'])->toHaveCount(1)
        ->and($batch['external'][0]['target'])->toBe('rapido.exemplo.com');
});

it('keeps queries and externals in separate budgets', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0], 'maxEventsPerRequest' => 1]);

    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1');
    Monitor::external('mail', 'smtp.exemplo.com', 5.0, 200);
    Monitor::external('mail', 'smtp.exemplo.com', 5.0, 200);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1)
        ->and($events['external'])->toHaveCount(1)
        ->and($events['dropped'])->toBe(1);
});

it('trackExternal returns the original result and records it', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    $result = Monitor::trackExternal('storage', 'r2/putobject', fn () => 'ok', fn ($r) => $r === 'ok' ? 200 : 500);

    expect($result)->toBe('ok');
    $events = Monitor::events()['external'];
    expect($events)->toHaveCount(1)
        ->and($events[0]['kind'])->toBe('storage')
        ->and($events[0]['target'])->toBe('r2/putobject')
        ->and($events[0]['status'])->toBe(200)
        ->and($events[0]['duration_ms'])->toBeGreaterThanOrEqual(0.0)
        ->and($events[0]['ts'])->toBeInt();
});

it('trackExternal returns null, false and objects untouched', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);
    $obj = new stdClass();

    expect(Monitor::trackExternal('http', 'a.com', fn () => null))->toBeNull()
        ->and(Monitor::trackExternal('http', 'a.com', fn () => false))->toBeFalse()
        ->and(Monitor::trackExternal('http', 'a.com', fn () => $obj))->toBe($obj);
    expect(Monitor::events()['external'])->toHaveCount(3);
    expect(array_column(Monitor::events()['external'], 'status'))->toBe([200, 200, 200]);
});

it('trackExternal records 200 on success without statusFrom and null for an explicit null', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::trackExternal('mail', 'smtp.exemplo.com', fn () => true);
    Monitor::trackExternal('http', 'a.com', fn () => 'x', fn () => null);

    expect(array_column(Monitor::events()['external'], 'status'))->toBe([200, null]);
});

it('trackExternal converts integer-like statusFrom values', function (mixed $value, ?int $expected) {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::trackExternal('http', 'a.com', fn () => 'x', fn () => $value);

    expect(Monitor::events()['external'][0]['status'])->toBe($expected);
})->with([
    'int'             => [503, 503],
    'string digits'   => ['200', 200],
    'float integral'  => [404.0, 404],
    'float fraction'  => [200.5, null],
    'string signed'   => ['-1', null],
    'string spaced'   => [' 200', null],
    'string text'     => ['ok', null],
    'empty string'    => ['', null],
    'huge string'     => [str_repeat('9', 30), null],
    'bool'            => [true, null],
    'array'           => [[200], null],
    'NaN'             => [NAN, null],
]);

it('clamps huge durations of external calls and queries', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::external('http', 'a.com', 1e12, 200);
    Monitor::externalEnd(hrtime(true) - 200_000_000_000_000, 'http', 'b.com', 200);
    Monitor::queryEnd(hrtime(true) - 200_000_000_000_000, 'SELECT 1');
    Monitor::flush(200, 0.0);

    $batch = $this->sink->batches[0];
    expect(array_column($batch['external'], 'duration_ms'))->toBe([99999999.99, 99999999.99])
        ->and($batch['queries'][0]['duration_ms'])->toBe(99999999.99)
        ->and($batch['requests'][0]['sample_weight'])->toBe(1.0);
});

it('trackExternal rethrows the original exception exactly once recorded with null status', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);
    $original = new RuntimeException('falha de transporte');
    $statusCalls = 0;

    try {
        Monitor::trackExternal('http', 'api.exemplo.com', function () use ($original) {
            throw $original;
        }, function () use (&$statusCalls) {
            $statusCalls++;
            return 200;
        });
        $this->fail('Esperava a exceção original');
    } catch (RuntimeException $e) {
        expect($e)->toBe($original);
    }

    $events = Monitor::events()['external'];
    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBeNull()
        ->and($statusCalls)->toBe(0);
});

it('trackExternal swallows a throwing statusFrom', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    $result = Monitor::trackExternal('http', 'api.exemplo.com', fn () => 42, function () {
        throw new LogicException('statusFrom quebrado');
    });

    expect($result)->toBe(42);
    $events = Monitor::events()['external'];
    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBeNull();
});

it('trackExternal records null for a non-numeric status', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::trackExternal('http', 'api.exemplo.com', fn () => 'x', fn () => '2OO');

    expect(Monitor::events()['external'][0]['status'])->toBeNull();
});

it('is a no-op when disabled and trackExternal still runs the call', function () {
    Config::fake(['enabled' => false]);
    Monitor::reset();
    $ran = 0;

    expect(Monitor::externalStart())->toBeNull();
    Monitor::external('http', 'a.com', 10.0, 200);
    Monitor::externalEnd(hrtime(true), 'http', 'a.com', 200);
    $result = Monitor::trackExternal('http', 'a.com', function () use (&$ran) {
        $ran++;
        return 'resultado';
    }, fn () => 200);

    expect($result)->toBe('resultado')
        ->and($ran)->toBe(1)
        ->and(Monitor::events())->toBe([]);

    $e = new RuntimeException('x');
    expect(fn () => Monitor::trackExternal('http', 'a.com', fn () => throw $e))->toThrow(RuntimeException::class, 'x');
});

it('bounds memory for a 5000 call loop', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    for ($i = 0; $i < 5000; $i++) {
        Monitor::external('http', 'api.exemplo.com', 1.0, 200);
    }

    expect(Monitor::events()['external'])->toHaveCount(200)
        ->and(Monitor::events()['dropped'])->toBe(4800);

    Monitor::flush(200, 0.0);

    $batch = $this->sink->batches[0];
    expect($batch['external'])->toHaveCount(200)
        ->and($batch['requests'][0]['dropped_events'])->toBe(4800);
});

it('never throws from the hooks', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    Monitor::external('ftp', 'a.com', 10.0, 200);
    Monitor::external('', 'a.com', 10.0, 200);
    Monitor::external('http', 'a.com', -1.0, 200);
    Monitor::external('http', 'a.com', NAN, 200);
    Monitor::external('http', 'a.com', INF, 200);
    Monitor::externalEnd(null, 'http', 'a.com');
    expect(Monitor::trackExternal('ftp', 'a.com', fn () => 'ok'))->toBe('ok');

    expect(Monitor::events()['external'])->toBe([]);

    Monitor::external('HTTP', '', 1.0);
    Monitor::external('mail', "\xff\xfe", 1.0);
    Monitor::external('storage', str_repeat('é', 300), 1.0);

    $events = Monitor::events()['external'];
    expect($events)->toHaveCount(3)
        ->and($events[0]['kind'])->toBe('http')
        ->and($events[0]['target'])->toBe('(invalido)')
        ->and($events[1]['target'])->toBe('(invalido)')
        ->and(mb_strlen($events[2]['target']))->toBe(191);

    Monitor::flush(200, 0.0);
    expect($this->sink->batches[0]['external'])->toHaveCount(3);
});

it('exposes the external key in events', function () {
    monitorExternalStart(['sample' => ['normal' => 1.0]]);

    expect(Monitor::events())->toBe(['queries' => [], 'exceptions' => [], 'external' => [], 'dropped' => 0]);
});

it('keeps a request with a slow external call in the sampler', function () {
    Config::fake(['enabled' => true, 'sample' => ['normal' => 0.0]]);

    expect(Sampler::weight(200, 10, false, 0.0, 0.99, 1000.0))->toBe(1.0)
        ->and(Sampler::weight(200, 10, false, 0.0, 0.99, 999.0))->toBeNull()
        ->and(Sampler::weight(200, 10, false, 0.0, 0.99))->toBeNull();

    Config::fake(['enabled' => true, 'sample' => ['normal' => 0.0], 'slowMs' => ['external' => 5000]]);
    expect(Sampler::weight(200, 10, false, 0.0, 0.99, 1000.0))->toBeNull()
        ->and(Sampler::weight(200, 10, false, 0.0, 0.99, 5000.0))->toBe(1.0);
});

it('has the new config defaults', function () {
    Config::fake([]);

    expect(Config::get('slowMs.external'))->toBe(1000)
        ->and(Config::get('api.enabled'))->toBeFalse()
        ->and(Config::get('slowMs.request'))->toBe(1000)
        ->and(Config::get('slowMs.query'))->toBe(200);
});
