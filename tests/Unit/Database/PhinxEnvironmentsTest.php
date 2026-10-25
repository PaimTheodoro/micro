<?php

use Psf\Database\PhinxEnvironments;
use Psf\Enumerators\DBDriver;

it('maps a sqlite config', function () {
    $envs = PhinxEnvironments::fromDbConfigs([
        'default' => ['driver' => DBDriver::SQLite, 'database' => ':memory:'],
    ]);

    expect($envs)->toBe([
        'default' => ['adapter' => 'sqlite', 'name' => ':memory:', 'suffix' => ''],
    ]);
});

it('maps a mysql config with legacy int driver and aliases', function () {
    $envs = PhinxEnvironments::fromDbConfigs([
        'prd' => [
            'driver'   => 1,
            'hostname' => 'db.local',
            'username' => 'root',
            'password' => 'secret',
            'database' => 'app',
        ],
    ]);

    expect($envs['prd'])->toBe([
        'adapter'   => 'mysql',
        'host'      => 'db.local',
        'name'      => 'app',
        'user'      => 'root',
        'pass'      => 'secret',
        'port'      => 3306,
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
});

it('applies pgsql defaults', function () {
    $envs = PhinxEnvironments::fromDbConfigs([
        'pg' => [
            'driver' => DBDriver::PostgreSQL,
            'host' => 'h', 'user' => 'u', 'pass' => 'p', 'database' => 'd',
        ],
    ]);

    expect($envs['pg']['adapter'])->toBe('pgsql')
        ->and($envs['pg']['port'])->toBe(5432)
        ->and($envs['pg']['charset'])->toBe('utf8')
        ->and($envs['pg'])->not->toHaveKey('collation');
});

it('skips entries without driver or database', function () {
    $envs = PhinxEnvironments::fromDbConfigs([
        'a' => ['database' => 'x', 'host' => 'h', 'user' => 'u', 'pass' => 'p'],
        'b' => ['driver' => DBDriver::MySQL, 'host' => 'h', 'user' => 'u', 'pass' => 'p'],
    ]);

    expect($envs)->toBe([]);
});

it('skips server drivers missing host, user or pass', function () {
    $base = ['driver' => DBDriver::MySQL, 'database' => 'd', 'host' => 'h', 'user' => 'u', 'pass' => 'p'];
    $envs = PhinxEnvironments::fromDbConfigs([
        'sem_host' => array_diff_key($base, ['host' => 1]),
        'sem_user' => array_diff_key($base, ['user' => 1]),
        'sem_pass' => array_diff_key($base, ['pass' => 1]),
        'ok'       => $base,
    ]);

    expect(array_keys($envs))->toBe(['ok']);
});

it('phinx.php still returns the environments', function () {
    PSF::init(['config' => __DIR__ . '/../../Fixtures/config.sqlite.php']);

    try {
        $config = include __DIR__ . '/../../../phinx.php';
    } finally {
        PSF::init(['config' => __DIR__ . '/../../Fixtures/config.php']);
    }

    expect($config['environments']['default']['adapter'])->toBe('sqlite')
        ->and($config['environments']['default_environment'])->toBe('default')
        ->and($config['paths']['migrations'])->toEndWith('/db/migrations');
});
