<?php

use Psf\Http\RouteCacheManager;

function writeController(string $dir, string $file, string $body): void
{
    file_put_contents($dir . '/' . $file, "<?php\n" . $body);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-routes-' . uniqid();
    mkdir($this->dir);
    $this->ns  = 'Tests\\Tmp\\R' . uniqid();

    $this->useControllersDir = function () {
        $config = $this->dir . '/config.php';
        file_put_contents($config, "<?php return ['settings' => ['controllers' => ['{$this->dir}/controllers']], 'environment' => 'dev'];");
        \PSF::init(['config' => $config]);
    };
    mkdir($this->dir . '/controllers');
});

afterEach(function () {
    \PSF::init(['config' => dirname(__DIR__, 2) . '/Fixtures/config.php']);
    array_map('unlink', glob($this->dir . '/controllers/*.php') ?: []);
    @unlink($this->dir . '/config.php');
    @rmdir($this->dir . '/controllers');
    @rmdir($this->dir);
});

it('discovers #[Router] methods of a controller', function () {
    writeController($this->dir . '/controllers', 'UsersController.php', "
namespace {$this->ns};

use Psf\\Http\\Router;

class UsersController {
    #[Router(method: 'GET', path: '/users/{id:int}', version: 1)]
    public function show(\$id) {}

    public function helper() {}
}
");
    ($this->useControllersDir)();

    $routes = (new RouteCacheManager())->getRoutes();

    expect($routes)->toHaveCount(1);
    expect($routes[0]['class'])->toBe($this->ns . '\\UsersController');
    expect($routes[0]['name'])->toBe('show');
    expect($routes[0]['attributes']['arguments']['path'])->toBe('/users/{id:int}');
});

it('is not fooled by the word class in comments, ::class or anonymous classes', function () {
    writeController($this->dir . '/controllers', 'TrickyController.php', "
namespace {$this->ns};

use Psf\\Http\\Router;

/* esta é uma class que aparece antes da declaração real */
// class Falsa {}
abstract class TrickyController {
    #[Router(method: 'GET', path: '/tricky', version: 1)]
    public function index() {
        \$anon = new class {};
        return [self::class, static::class];
    }
}
");
    ($this->useControllersDir)();

    $routes = (new RouteCacheManager())->getRoutes();

    expect($routes)->toHaveCount(1);
    expect($routes[0]['class'])->toBe($this->ns . '\\TrickyController');
});

it('ignores files that declare no namespaced class', function () {
    writeController($this->dir . '/controllers', 'helpers.php', "function soltinha() {}\n");
    ($this->useControllersDir)();

    expect((new RouteCacheManager())->getRoutes())->toBe([]);
});

it('falls back to dev when the environment is not configured', function () {
    $config = $this->dir . '/config.php';
    file_put_contents($config, "<?php return ['settings' => ['controllers' => []]];");
    \PSF::init(['config' => $config]);

    expect(fn() => new RouteCacheManager())->not->toThrow(TypeError::class);
});

it('clear() is a safe no-op when APCu is unavailable', function () {
    expect((new RouteCacheManager())->clear())->toBeFalse();
})->skip(extension_loaded('apcu') && apcu_enabled(), 'APCu habilitado neste ambiente');
