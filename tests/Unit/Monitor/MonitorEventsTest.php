<?php

use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Config;
use Psf\Monitor\Monitor;
use Psf\Monitor\Sinks\SinkInterface;

/** Sink espião: guarda os lotes recebidos. */
function monitorEventsSpySink(): SinkInterface
{
    return new class implements SinkInterface {
        public array $batches = [];
        public int $calls = 0;

        public function write(array $batch): void
        {
            $this->calls++;
            $this->batches[] = $batch;
        }
    };
}

/** Liga o monitor com as settings dadas, injeta sink/breaker e um provider de fatal vazio. */
function monitorEventsStart(array $settings): void
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
    $this->dir = sys_get_temp_dir() . '/psf-monitor-events-' . uniqid();
    mkdir($this->dir);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    $this->sink = monitorEventsSpySink();
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

it('records queries and normalizes them at flush', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);

    Monitor::queryEnd(Monitor::queryStart(), "SELECT * FROM t WHERE id = 5 AND n = 'bob'", 'default');
    Monitor::flush(200, 0.0);

    expect($this->sink->batches)->toHaveCount(1);
    $batch = $this->sink->batches[0];
    $request = $batch['requests'][0];

    expect($batch['queries'])->toHaveCount(1)
        ->and($batch)->not->toHaveKey('exceptions');

    $q = $batch['queries'][0];
    expect($q['sql_text'])->toBe('SELECT * FROM t WHERE id = ? AND n = ?')
        ->and($q['sql_hash'])->toBe(sha1('SELECT * FROM t WHERE id = ? AND n = ?'))
        ->and($q['connection_name'])->toBe('default')
        ->and($q['request_id'])->toBe($request['request_id'])
        ->and($q['sample_weight'])->toBe($request['sample_weight'])
        ->and($q['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($q['ts'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('keeps a request whose query was slow even if the query was dropped', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0], 'maxEventsPerRequest' => 1]);

    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1');
    Monitor::queryEnd(hrtime(true) - 450_000_000, 'SELECT 2');
    Monitor::flush(200, 0.99);

    expect($this->sink->batches)->toHaveCount(1);
    $batch = $this->sink->batches[0];
    expect($batch['requests'][0]['sample_weight'])->toBe(1.0)
        ->and($batch['requests'][0]['dropped_events'])->toBe(1)
        ->and($batch['queries'])->toHaveCount(1);
});

it('keeps a request with an exception and writes it', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0]]);

    $e = new RuntimeException('boom');
    Monitor::exception($e);
    Monitor::exception($e);

    $events = Monitor::events();
    expect($events['exceptions'])->toHaveCount(1)
        ->and($events['exceptions'][0]['throwable'])->toBe($e);

    Monitor::flush(200, 0.99);

    expect($this->sink->batches)->toHaveCount(1);
    $batch = $this->sink->batches[0];
    expect($batch['requests'][0]['sample_weight'])->toBe(1.0)
        ->and($batch['exceptions'])->toHaveCount(1)
        ->and($batch)->not->toHaveKey('queries');

    $row = $batch['exceptions'][0];
    expect($row['exception_class'])->toBe('RuntimeException')
        ->and($row['message'])->toBe('boom')
        ->and($row['trace_hash'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($row['trace'])->not->toBe('')
        ->and($row['request_id'])->toBe($batch['requests'][0]['request_id'])
        ->and($row['sample_weight'])->toBe(1.0)
        ->and($row['ts'])->toBe($batch['requests'][0]['ts']);
});

it('records a fatal error and forces status 500', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0]]);
    Monitor::useFatalProvider(fn () => [
        'type' => E_ERROR,
        'message' => "x\nStack trace:\n#0 y('s')",
        'file' => '/f.php',
        'line' => 3,
    ]);

    Monitor::flush(200, 0.99);

    expect($this->sink->batches)->toHaveCount(1);
    $batch = $this->sink->batches[0];
    expect($batch['requests'][0]['status'])->toBe(500)
        ->and($batch['exceptions'])->toHaveCount(1);

    $row = $batch['exceptions'][0];
    expect($row['exception_class'])->toBe('FatalError')
        ->and($row['file'])->toBe('/f.php')
        ->and($row['line_no'])->toBe(3)
        ->and(json_encode($row))->not->toContain("'s'");
});

it('ignores non-fatal errors from the fatal provider', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);
    Monitor::useFatalProvider(fn () => [
        'type' => E_WARNING,
        'message' => 'apenas um aviso',
        'file' => '/f.php',
        'line' => 3,
    ]);

    Monitor::flush(200, 0.0);

    $batch = $this->sink->batches[0];
    expect($batch['requests'][0]['status'])->toBe(200)
        ->and($batch)->not->toHaveKey('exceptions');
});

it('does not normalize or describe before the response is released', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT * FROM t WHERE id = 5');
    Monitor::exception(new RuntimeException('boom'));

    $atFinish = null;
    Monitor::useFinisher(function () use (&$atFinish) {
        $atFinish = Monitor::events();
    });

    Monitor::flush(200, 0.0);

    expect($atFinish['queries'][0]['sql'])->toBe('SELECT * FROM t WHERE id = 5')
        ->and($atFinish['exceptions'][0])->toHaveKey('throwable')
        ->and($atFinish['exceptions'][0])->not->toHaveKey('trace_hash')
        ->and($this->sink->batches[0]['queries'][0]['sql_text'])->toBe('SELECT * FROM t WHERE id = ?');
});

it('exposes raw query events', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1', 'replica');

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1)
        ->and($events['queries'][0]['sql'])->toBe('SELECT 1')
        ->and($events['queries'][0]['connection'])->toBe('replica')
        ->and($events['queries'][0]['ts'])->toBeInt()
        ->and($events['dropped'])->toBe(0);

    Monitor::reset();
    expect(Monitor::events())->toBe(['queries' => [], 'exceptions' => [], 'external' => [], 'dropped' => 0]);
});

it('is a no-op for queryStart/queryEnd/exception when disabled', function () {
    Config::fake(['enabled' => false]);
    Monitor::reset();

    expect(Monitor::queryStart())->toBeNull();
    Monitor::queryEnd(null, 'SELECT 1');
    Monitor::queryEnd(hrtime(true), 'SELECT 1');
    Monitor::exception(new RuntimeException('x'));

    expect(Monitor::events())->toBe([]);
});

it('works without boot when enabled', function () {
    Config::fake(['enabled' => true]);
    Monitor::reset();

    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1');
    Monitor::exception(new RuntimeException('x'));

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1)
        ->and($events['exceptions'])->toHaveCount(1);
});

it('never throws from the new hooks', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);

    $huge = new class (str_repeat('m', 2_000_000)) extends RuntimeException {
    };

    Monitor::queryEnd(123, "\xff\xfe");
    Monitor::exception($huge);
    Monitor::flush(200, 0.0);

    $batch = $this->sink->batches[0];
    expect($batch['queries'])->toHaveCount(1)
        ->and(mb_check_encoding($batch['queries'][0]['sql_text'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($batch['exceptions'][0]['message']))->toBeLessThanOrEqual(500);
});

it('tolerates a failing fatal provider and keeps the request row', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);
    Monitor::useFatalProvider(fn () => throw new RuntimeException('provider quebrado'));
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1');

    Monitor::flush(200, 0.0);

    $batch = $this->sink->batches[0];
    expect($batch['requests'])->toHaveCount(1)
        ->and($batch['requests'][0]['status'])->toBe(200)
        ->and($batch['queries'])->toHaveCount(1);
});

it('cuts the normalized sql to 2000 characters', function () {
    monitorEventsStart(['sample' => ['normal' => 1.0]]);
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT ' . str_repeat('col_a, ', 1000) . 'x FROM t');

    Monitor::flush(200, 0.0);

    $q = $this->sink->batches[0]['queries'][0];
    expect(mb_strlen($q['sql_text']))->toBeLessThanOrEqual(2000)
        ->and($q['sql_hash'])->toBe(sha1($q['sql_text']));
});

it('writes the fatal even when 20 exceptions are already stored', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0]]);
    for ($i = 0; $i < 20; $i++) {
        Monitor::exception(new RuntimeException("e$i"));
    }
    Monitor::useFatalProvider(fn () => ['type' => E_ERROR, 'message' => 'morreu', 'file' => '/f.php', 'line' => 3]);

    Monitor::flush(200, 0.99);

    $batch = $this->sink->batches[0];
    $classes = array_column($batch['exceptions'], 'exception_class');
    expect($batch['requests'][0]['status'])->toBe(500)
        ->and($batch['requests'][0]['dropped_events'])->toBe(0)
        ->and($batch['exceptions'])->toHaveCount(21)
        ->and($classes)->toContain('FatalError');
});

it('counts distinct exceptions past the cap as dropped and ignores repeats', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0]]);
    $same = new RuntimeException('repetida');
    Monitor::exception($same);
    Monitor::exception($same);

    expect(Monitor::events()['exceptions'])->toHaveCount(1)
        ->and(Monitor::events()['dropped'])->toBe(0);

    // Sem guardar referência: as descartadas são liberadas e o id pode ser reaproveitado.
    for ($i = 0; $i < 24; $i++) {
        Monitor::exception(new RuntimeException("e$i"));
    }

    expect(Monitor::events()['exceptions'])->toHaveCount(20)
        ->and(Monitor::events()['dropped'])->toBe(5);
});

it('honours storeExceptionMessage when writing exceptions and fatals', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0], 'storeExceptionMessage' => false]);
    Monitor::useFatalProvider(fn () => ['type' => E_ERROR, 'message' => 'fatal secreto', 'file' => '/f.php', 'line' => 1]);

    Monitor::exception(new RuntimeException('segredo', 0, new LogicException('segredo interno')));
    Monitor::flush(200, 0.99);

    $rows = $this->sink->batches[0]['exceptions'];
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['message'])->toBe('')
        ->and($rows[1]['message'])->toBe('')
        ->and(json_encode($rows))->not->toContain('segredo')
        ->and(json_encode($rows))->not->toContain('secreto');
});

it('scrubs PDO messages written at flush', function () {
    monitorEventsStart(['sample' => ['normal' => 0.0]]);

    Monitor::exception(new PDOException("SQLSTATE[23000]: Duplicate entry 'jane@x.com' for key 'users.email'"));
    Monitor::flush(200, 0.99);

    expect(json_encode($this->sink->batches[0]['exceptions']))->not->toContain('jane@x.com');
});
