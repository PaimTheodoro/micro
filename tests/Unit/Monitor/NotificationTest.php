<?php

use Psf\Helper\ConfigKeys;
use Psf\Http\Cors;
use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Config;
use Psf\Monitor\Monitor;
use Psf\Monitor\Notification\Channels\{DiscordNotifier, EmailNotifier, SlackNotifier, TeamsNotifier, WebhookNotifier};
use Psf\Monitor\Notification\{Dispatcher, Notification, Rules, Throttle};
use Psf\Monitor\Sinks\SinkInterface;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-notify-' . uniqid();
    mkdir($this->dir);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    $this->received = new ArrayObject();
    $this->finished = 0;
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

/** Liga o monitor com notificador `callback` que guarda as notificações em $this->received. */
function notifyStart(array $monitor, array $when = [], array $extra = []): void
{
    $self = test();
    $entry = ['callback' => function (Notification $n) use ($self) {
        $self->received->append($n);
    }, 'when' => $when] + $extra;

    Config::fake(array_replace_recursive(['enabled' => true, 'sample' => ['normal' => 0.0], 'notifiers' => ['teste' => $entry]], $monitor));
    Monitor::reset();
    Monitor::useSink(new class implements SinkInterface {
        public function write(array $batch): void
        {
        }
    });
    Monitor::useBreaker(new CircuitBreaker('notify', 3, 300, null, $self->dir));
    Monitor::useFatalProvider(fn () => null);
    Monitor::useFinisher(function () use ($self) {
        $self->finished++;
    });
    Dispatcher::useThrottle(new Throttle(null, $self->dir));
    Monitor::boot('GET');
}

// ─── ConfigKeys / aliases legados ────────────────────────────────────────────

it('converts snake_case keys to camelCase and keeps the rest', function () {
    expect(ConfigKeys::camelKey('raw_days'))->toBe('rawDays')
        ->and(ConfigKeys::camelKey('rawDays'))->toBe('rawDays')
        ->and(ConfigKeys::camelKey('X-Api_Key'))->toBe('X-Api_Key')
        ->and(ConfigKeys::camelize(['a_b' => ['c_d' => 1]]))->toBe(['aB' => ['cD' => 1]]);
});

it('prefers the camelCase key when both spellings are given', function () {
    expect(ConfigKeys::camelize(['raw_days' => 1, 'rawDays' => 2]))->toBe(['rawDays' => 2])
        ->and(ConfigKeys::camelize(['raw_days' => 1, 'rawDays' => 2]))->toBe(['rawDays' => 2]);
});

it('does not touch skipped subtrees', function () {
    expect(ConfigKeys::camelize(['notifiers' => ['a_b' => 1], 'c_d' => 2], ['notifiers']))
        ->toBe(['notifiers' => ['a_b' => 1], 'cD' => 2]);
});

it('accepts legacy snake_case monitor settings and lookups', function () {
    Config::fake(['enabled' => true, 'slow_ms' => ['query' => 50], 'retention' => ['raw_days' => 3]]);

    expect(Config::get('slowMs.query'))->toBe(50)
        ->and(Config::get('slow_ms.query'))->toBe(50)
        ->and(Config::get('retention.rawDays'))->toBe(3)
        ->and(Config::get('retention.raw_days'))->toBe(3);
});

it('accepts legacy CORS keys', function () {
    $result = Cors::resolve(
        ['origins' => ['https://a.com'], 'max_age' => 99, 'expose_headers' => ['X-Id']],
        'https://a.com', 'OPTIONS', 'GET'
    );

    expect($result['headers']['Access-Control-Max-Age'])->toBe('99')
        ->and($result['headers']['Access-Control-Expose-Headers'])->toBe('X-Id');
});

// ─── Rules ───────────────────────────────────────────────────────────────────

function notifyFacts(array $over = []): array
{
    return $over + [
        'status' => 200, 'route' => '/v1/pagamentos/{id:int}', 'method' => 'GET', 'env' => 'prd',
        'hasException' => false, 'hasFatal' => false, 'hasExternalError' => false,
        'slowRequest' => false, 'slowQuery' => false, 'slowExternal' => false,
    ];
}

it('uses status >= 500 and fatal by default', function () {
    expect(Rules::matches([], notifyFacts(['status' => 500])))->toBeTrue()
        ->and(Rules::matches([], notifyFacts(['status' => 404])))->toBeFalse()
        ->and(Rules::matches([], notifyFacts(['hasFatal' => true])))->toBeTrue()
        ->and(Rules::matches(['environments' => ['prd']], notifyFacts(['status' => 503])))->toBeTrue();
});

it('matches each trigger', function (array $when, array $facts, bool $expected) {
    expect(Rules::matches($when, notifyFacts($facts)))->toBe($expected);
})->with([
    'statuses'       => [['statuses' => [429]], ['status' => 429], true],
    'statuses miss'  => [['statuses' => [429]], ['status' => 500], false],
    'exceptions'     => [['exceptions' => true], ['hasException' => true], true],
    'slowRequest'    => [['slowRequest' => true], ['slowRequest' => true], true],
    'slowQuery'      => [['slowQuery' => true], ['slowQuery' => true], true],
    'externalErrors' => [['externalErrors' => true], ['hasExternalError' => true], true],
    'slowExternal'   => [['slowExternal' => true], ['slowExternal' => true], true],
    'only explicit'  => [['slowQuery' => true], ['status' => 500], false],
]);

it('applies route, method and environment filters', function (array $when, bool $expected) {
    expect(Rules::matches($when + ['statusGte' => 500], notifyFacts(['status' => 500])))->toBe($expected);
})->with([
    'routes hit'       => [['routes' => ['/v1/pagamentos/*']], true],
    'routes miss'      => [['routes' => ['/v1/usuarios/*']], false],
    'ignoreRoutes'     => [['ignoreRoutes' => ['/v1/pagamentos/*']], false],
    'methods miss'     => [['methods' => ['POST']], false],
    'methods hit'      => [['methods' => ['get']], true],
    'environment miss' => [['environments' => ['dev']], false],
]);

// ─── Throttle ────────────────────────────────────────────────────────────────

it('throttles equal notifications and reports how many were suppressed', function () {
    $now = 1000;
    $throttle = new Throttle(function () use (&$now) {
        return $now;
    }, $this->dir);

    expect($throttle->acquire('k', 60))->toBe(0)
        ->and($throttle->acquire('k', 60))->toBeNull()
        ->and($throttle->acquire('k', 60))->toBeNull()
        ->and($throttle->acquire('outra', 60))->toBe(0);

    $now = 1061;
    expect($throttle->acquire('k', 60))->toBe(2)
        ->and($throttle->acquire('k', 0))->toBe(0);
});

// ─── Monitor + Dispatcher ────────────────────────────────────────────────────

it('notifies a 500 with its exception, regardless of sampling', function () {
    notifyStart(['release' => '1.2.3']);
    Monitor::route('/v1/usuarios/{id:int}', 'GET', 1);
    Monitor::exception(new RuntimeException('boom'));
    Monitor::flush(500, 0.99);

    expect($this->received)->toHaveCount(1);
    $n = $this->received[0];
    expect($n->status)->toBe(500)
        ->and($n->route)->toBe('/v1/usuarios/{id:int}')
        ->and($n->release)->toBe('1.2.3')
        ->and($n->reasons)->toContain('status', 'exception')
        ->and($n->exceptions[0]['class'])->toBe('RuntimeException')
        ->and($n->exceptions[0]['message'])->toBe('boom')
        ->and($n->fingerprint)->toBe($n->exceptions[0]['traceHash'])
        ->and($n->title())->toContain('500 GET /v1/usuarios/{id:int} — RuntimeException')
        ->and($n->toArray()['requestId'])->toBe($n->requestId)
        ->and($this->finished)->toBe(1);
});

it('does not notify a healthy request nor finish its response', function () {
    notifyStart([]);
    Monitor::flush(200, 0.99);

    expect($this->received)->toHaveCount(0)->and($this->finished)->toBe(0);
});

it('notifies even when the breaker is open', function () {
    notifyStart([]);
    $breaker = new CircuitBreaker('open', 1, 300, null, $this->dir);
    $breaker->recordFailure();
    Monitor::useBreaker($breaker);
    Monitor::flush(500, 0.0);

    expect($this->received)->toHaveCount(1);
});

it('notifies slow queries and failed external calls when asked', function () {
    notifyStart(['slowMs' => ['query' => 0]], ['slowQuery' => true, 'externalErrors' => true]);
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1', 'default');
    Monitor::external('http', 'api.exemplo.com', 12.0, null);
    Monitor::flush(200, 0.99);

    expect($this->received)->toHaveCount(1)
        ->and($this->received[0]->reasons)->toContain('slowQuery', 'externalError')
        ->and($this->received[0]->externalErrors[0]['target'])->toBe('api.exemplo.com');
});

it('throttles repeated notifications and tells how many were suppressed', function () {
    notifyStart([], [], ['throttleSeconds' => 60]);
    Monitor::route('/x', 'GET', 0);
    Monitor::flush(500, 0.0);

    foreach ([1, 2] as $_) {
        Monitor::reset();
        Config::fake(['enabled' => true, 'notifiers' => ['teste' => ['callback' => fn (Notification $n) => $this->received->append($n), 'throttleSeconds' => 60]]]);
        Monitor::useSink(new class implements SinkInterface {
            public function write(array $batch): void
            {
            }
        });
        Monitor::useFatalProvider(fn () => null);
        Monitor::useFinisher(fn () => null);
        Dispatcher::useThrottle(new Throttle(null, $this->dir));
        Monitor::boot('GET');
        Monitor::route('/x', 'GET', 0);
        Monitor::flush(500, 0.0);
    }

    expect($this->received)->toHaveCount(1);
});

it('contains a failing notifier and still runs the others, logging only the class', function () {
    $log = tempnam($this->dir, 'log');
    $previous = ini_set('error_log', $log);

    notifyStart([], [], ['callback' => function () {
        throw new RuntimeException('https://hooks.exemplo.com/SEGREDO');
    }]);
    Config::fake([
        'enabled' => true,
        'notifiers' => [
            'quebrado' => ['callback' => function () {
                throw new RuntimeException('https://hooks.exemplo.com/SEGREDO');
            }],
            'ok' => ['callback' => function (Notification $n) {
                $this->received->append($n);
            }],
        ],
    ]);
    Monitor::flush(500, 0.0);
    ini_set('error_log', (string) $previous);

    $logged = (string) file_get_contents($log);
    expect($this->received)->toHaveCount(1)
        ->and($logged)->toContain("notificador 'quebrado' falhou: RuntimeException")
        ->and($logged)->not->toContain('SEGREDO');
});

it('ignores disabled and invalid entries', function () {
    Config::fake(['enabled' => true, 'notifiers' => [
        'off' => ['enabled' => false, 'callback' => fn () => null],
        'lixo' => 'texto',
    ]]);

    expect(array_keys(Dispatcher::entries()))->toBe([]);
});

// ─── Canais ──────────────────────────────────────────────────────────────────

function notifySample(): Notification
{
    return new Notification(
        ['status', 'exception'], 'req-12345678', '/v1/x', 'POST', 500, 123.4, 'prd', '1.0', '7',
        [['class' => 'PDOException', 'message' => 'falhou', 'file' => '/app/X.php', 'line' => 10, 'traceHash' => 'abc', 'fatal' => false]],
        [], 'abc', new DateTimeImmutable('2026-10-26T10:00:00Z')
    );
}

it('posts the signed JSON payload with the webhook channel', function () {
    $sent = [];
    $channel = new WebhookNotifier(
        ['webhookUrl' => 'https://h.exemplo.com/x', 'secret' => 's3', 'headers' => ['X-Token' => 't']],
        function ($url, $body, $headers, $timeout) use (&$sent) {
            $sent = compact('url', 'body', 'headers', 'timeout');

            return 204;
        }
    );
    $channel->notify(notifySample());

    $payload = json_decode($sent['body'], true);
    expect($payload['requestId'])->toBe('req-12345678')
        ->and($payload['exceptions'][0]['class'])->toBe('PDOException')
        ->and($sent['headers'])->toContain('X-Token: t', 'X-Psf-Signature: sha256=' . hash_hmac('sha256', $sent['body'], 's3'))
        ->and($sent['timeout'])->toBe(3);
});

it('builds the chat payloads', function () {
    $capture = function (string $class, array $options = []) {
        $body = '';
        (new $class(['webhookUrl' => 'https://h/x'] + $options, function ($u, $b) use (&$body) {
            $body = $b;

            return 200;
        }))->notify(notifySample());

        return json_decode($body, true);
    };

    expect($capture(SlackNotifier::class, ['mention' => '<!here>'])['text'])->toStartWith('<!here> ')->toContain('PDOException')
        ->and($capture(TeamsNotifier::class)['@type'])->toBe('MessageCard')
        ->and($capture(DiscordNotifier::class)['content'])->toContain('500 POST /v1/x');
});

it('does not leak the webhook URL when the send fails', function () {
    $channel = new SlackNotifier(['webhookUrl' => 'https://h.exemplo.com/SEGREDO'], fn () => 500);

    try {
        $channel->notify(notifySample());
        $this->fail('deveria lançar');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('SEGREDO')->toContain('500');
    }
});

it('sends one e-mail per recipient and reports failures', function () {
    $calls = [];
    $channel = new EmailNotifier(['to' => ['a@x.com', ['Bia', 'b@x.com']], 'subjectPrefix' => '[API] '], function ($from, $to, $subject, $content) use (&$calls) {
        $calls[] = [$to, $subject, $content];

        return count($calls) === 1;
    });

    expect(fn () => $channel->notify(notifySample()))->toThrow(RuntimeException::class, '1 de 2');
    expect($calls)->toHaveCount(2)
        ->and($calls[1][0])->toBe(['Bia', 'b@x.com'])
        ->and($calls[0][1])->toStartWith('[API] [prd] 500')
        ->and($calls[0][2]['html'])->toContain('PDOException');
});
