<?php

use Psf\Http\HttpResponseException;
use Psf\Http\Router;
use Psf\Monitor\Config;
use Psf\Monitor\Monitor;
use Tests\Fixtures\Controllers\FakeController;

function makeRouter(array $routes, string $method = 'GET'): Router
{
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();

    foreach (['method' => $method, 'routes' => $routes] as $prop => $value) {
        $ref = new ReflectionProperty($router, $prop);
        $ref->setAccessible(true);
        $ref->setValue($router, $value);
    }

    return $router;
}

function route(string $path, string $name = 'ok', int $version = 1, string $method = 'GET'): array
{
    return [
        'class'      => FakeController::class,
        'name'       => $name,
        'attributes' => ['arguments' => ['method' => $method, 'version' => $version, 'path' => $path]],
    ];
}

function callPrivate(Router $router, string $method, ...$args)
{
    $ref = new ReflectionMethod($router, $method);
    $ref->setAccessible(true);

    return $ref->invoke($router, ...$args);
}

beforeEach(function () {
    FakeController::$instances = 0;
    $_GET = [];
});

afterEach(function () {
    Router::$auth = [];
    monitorCleanup();
});

// --- matching ---

it('matches static routes with or without the version prefix', function () {
    $router = makeRouter([route('/ping', 'ok')]);

    expect(callPrivate($router, 'findMatchingRoute', 'v1/ping')['name'])->toBe('ok');
    expect(callPrivate(makeRouter([route('/ping', 'ok')]), 'findMatchingRoute', 'ping')['name'])->toBe('ok');
});

it('matches dynamic routes declared with a leading slash', function () {
    $router = makeRouter([route('/usuarios/{id:int}', 'show')]);

    expect(callPrivate($router, 'findMatchingRoute', 'v1/usuarios/5')['name'])->toBe('show');
});

it('does not match when the typed parameter is invalid', function () {
    $router = makeRouter([route('/usuarios/{id:int}', 'show')]);

    expect(callPrivate($router, 'findMatchingRoute', 'v1/usuarios/abc'))->toBeNull();
});

it('supports a multi-digit version prefix', function () {
    $router = makeRouter([route('/ping', 'ok', 12)]);

    expect(callPrivate($router, 'findMatchingRoute', 'v12/ping')['name'])->toBe('ok');
});

it('supports inline regex that contains slashes and braces', function () {
    $routes = [route('/produtos/{codigo:/^[A-Z]{3}-\d{4}$/}', 'show')];

    expect(callPrivate(makeRouter($routes), 'findMatchingRoute', 'v1/produtos/ABC-1234')['name'])->toBe('show');
    expect(callPrivate(makeRouter($routes), 'findMatchingRoute', 'v1/produtos/abc-1234'))->toBeNull();
});

it('anchors undelimited inline expressions instead of matching substrings', function () {
    $routes = [route('/itens/{n:\d+}', 'show')];

    expect(callPrivate(makeRouter($routes), 'findMatchingRoute', 'v1/itens/42')['name'])->toBe('show');
    expect(callPrivate(makeRouter($routes), 'findMatchingRoute', 'v1/itens/abc1'))->toBeNull();
});

it('treats an invalid regex as no match instead of failing', function () {
    $routes = [route('/x/{v:/[/}', 'show')];

    expect(callPrivate(makeRouter($routes), 'findMatchingRoute', 'v1/x/a'))->toBeNull();
});

it('extracts url parameters by name', function () {
    $route  = route('/usuarios/{id:int}/posts/{slug:slug}', 'show');
    $router = makeRouter([$route]);

    expect(callPrivate($router, 'extractUrlParameters', $route, ['usuarios', '9', 'posts', 'ola-mundo']))
        ->toBe(['id' => '9', 'slug' => 'ola-mundo']);
});

it('does not emit a notice when _url is missing', function () {
    $router = makeRouter([route('/', 'ok')]);

    expect(callPrivate($router, 'requestUrl'))->toBe('');
});

// --- dispatch ---

it('instantiates the controller only once per dispatch', function () {
    $router = makeRouter([]);

    $result = callPrivate($router, 'dispatchController', route('/ping', 'ok'));

    expect($result)->toBe(['ok', null, 200]);
    expect(FakeController::$instances)->toBe(1);
});

it('lets deliberate HTTP responses through instead of wrapping them in a 500', function () {
    $router = makeRouter([]);

    try {
        callPrivate($router, 'dispatchController', route('/x', 'respond'));
        $this->fail('esperava HttpResponseException');
    } catch (HttpResponseException $e) {
        expect($e->getStatus())->toBe(422);
    }
});

it('preserves exceptions that carry an explicit 4xx code', function () {
    $router = makeRouter([]);

    try {
        callPrivate($router, 'dispatchController', route('/x', 'forbidden'));
        $this->fail('esperava Exception');
    } catch (Exception $e) {
        expect($e->getCode())->toBe(403);
        expect($e->getMessage())->toBe('Sem permissão');
    }
});

it('wraps unexpected exceptions in a generic 500 without leaking details', function () {
    $router = makeRouter([]);

    try {
        callPrivate($router, 'dispatchController', route('/x', 'boom'));
        $this->fail('esperava Exception');
    } catch (Exception $e) {
        expect($e->getCode())->toBe(500);
        expect($e->getMessage())->toBe('Erro ao executar a rota.');
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

// --- log ---

it('redacts credentials from the headers handed to the request logger', function () {
    if (!function_exists('getallheaders')) {
        $this->markTestSkipped('getallheaders indisponível');
    }

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer segredo';
    $_SERVER['HTTP_X_TRACE']       = 'abc';

    $headers = callPrivate(makeRouter([]), 'loggableHeaders');

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_TRACE']);

    expect($headers['Authorization'])->toBe('[redacted]');
    expect($headers['X-Trace'])->toBe('abc');
});

// --- monitor ---

it('records the matched route pattern for the monitor', function () {
    Config::fake(['enabled' => true]);
    $_GET['_url'] = 'v1/users/5';

    callPrivate(makeRouter([route('users/{id:int}', 'show')]), 'callMethodRoute');

    $snap = Monitor::snapshot();
    expect($snap['route'])->toBe('users/{id:int}');
    expect($snap['method'])->toBe('GET');
    expect($snap['version'])->toBe(1);
});

it('records (unmatched) for a 404', function () {
    Config::fake(['enabled' => true]);
    $_GET['_url'] = 'v1/nao-existe';

    try {
        callPrivate(makeRouter([route('users/{id:int}', 'show')]), 'callMethodRoute');
        $this->fail('Esperava exceção 404');
    } catch (\Exception $e) {
        expect($e->getCode())->toBe(404);
    }

    expect(Monitor::snapshot()['route'])->toBe('(unmatched)');
});

it('forwards the authenticated user to the monitor', function () {
    monitorUseConfig(['enabled' => true], ['settings' => ['verifyauth' => [\Tests\Fixtures\Controllers\FakeAuth::class, 'verify']]]);
    $_GET['_url'] = 'v1/secure';

    $route = route('secure', 'ok');
    $route['attributes']['arguments']['middlewares'] = ['authentication'];

    callPrivate(makeRouter([$route]), 'callMethodRoute');

    expect(Monitor::snapshot()['user_id'])->toBe('9');
});

it('does not touch the monitor when disabled', function () {
    Config::fake(['enabled' => false]);
    $_GET['_url'] = 'v1/users/5';

    callPrivate(makeRouter([route('users/{id:int}', 'show')]), 'callMethodRoute');

    expect(Monitor::snapshot())->toBe([]);
});

it('records the original exception when the route fails with a 5xx', function () {
    Config::fake(['enabled' => true]);
    $_GET['_url'] = 'v1/boom';

    try {
        callPrivate(makeRouter([route('boom', 'boom')]), 'callMethodRoute');
        $this->fail('Esperava exceção');
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('Erro ao executar a rota.');
        $registradas = Monitor::events()['exceptions'];
        expect($registradas)->toHaveCount(1);
        expect($registradas[0]['throwable'])->toBeInstanceOf(\RuntimeException::class);
        expect($e->getPrevious())->toBe($registradas[0]['throwable']);
    }
});

it('does not record 4xx exceptions', function () {
    Config::fake(['enabled' => true]);
    $_GET['_url'] = 'v1/forbidden';

    try {
        callPrivate(makeRouter([route('forbidden', 'forbidden')]), 'callMethodRoute');
        $this->fail('Esperava exceção');
    } catch (\Exception $e) {
        expect($e->getCode())->toBe(403);
    }

    expect(Monitor::events()['exceptions'] ?? [])->toBe([]);
});

it('does not record deliberate http responses', function () {
    Config::fake(['enabled' => true]);
    $_GET['_url'] = 'v1/respond';

    try {
        callPrivate(makeRouter([route('respond', 'respond')]), 'callMethodRoute');
        $this->fail('Esperava HttpResponseException');
    } catch (HttpResponseException $e) {
        expect($e)->toBeInstanceOf(HttpResponseException::class);
    }

    expect(Monitor::events()['exceptions'] ?? [])->toBe([]);
});

it('keeps the wrapped exception and records nothing when the monitor is disabled', function () {
    Config::fake(['enabled' => false]);
    $_GET['_url'] = 'v1/boom';

    try {
        callPrivate(makeRouter([route('boom', 'boom')]), 'callMethodRoute');
        $this->fail('Esperava exceção');
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('Erro ao executar a rota.');
        expect($e->getPrevious())->toBeInstanceOf(\RuntimeException::class);
    }

    expect(Monitor::events())->toBe([]);
});

it('records and rethrows unchanged an Error raised by the controller', function () {
    Config::fake(['enabled' => true]);
    $_GET['_url'] = 'v1/type-error';

    $capturada = null;
    try {
        callPrivate(makeRouter([route('type-error', 'typeError')]), 'callMethodRoute');
        $this->fail('Esperava TypeError');
    } catch (\TypeError $e) {
        $capturada = $e;
    }

    expect($capturada->getMessage())->toBe('tipo inválido')
        ->and($capturada->getPrevious())->toBeNull();
    $registradas = Monitor::events()['exceptions'];
    expect($registradas)->toHaveCount(1)
        ->and($registradas[0]['throwable'])->toBe($capturada);
});

it('rethrows an Error unchanged and records nothing when the monitor is disabled', function () {
    Config::fake(['enabled' => false]);
    $_GET['_url'] = 'v1/type-error';

    try {
        callPrivate(makeRouter([route('type-error', 'typeError')]), 'callMethodRoute');
        $this->fail('Esperava TypeError');
    } catch (\TypeError $e) {
        expect($e->getMessage())->toBe('tipo inválido');
    }

    expect(Monitor::events())->toBe([]);
});
