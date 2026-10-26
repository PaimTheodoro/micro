<?php

use Psf\Monitor\Config;

it('is disabled when settings.monitor is missing', function () {
    Config::fake(null);
    expect(Config::enabled())->toBeFalse();
});

it('applies defaults', function () {
    Config::fake(['enabled' => true]);

    expect(Config::get('sample.normal'))->toBe(0.1)
        ->and(Config::get('slowMs.request'))->toBe(1000)
        ->and(Config::get('slowMs.query'))->toBe(200)
        ->and(Config::get('maxEventsPerRequest'))->toBe(200)
        ->and(Config::get('retention.rawDays'))->toBe(7)
        ->and(Config::get('retention.aggregateDays'))->toBe(90)
        ->and(Config::get('breaker.failures'))->toBe(3)
        ->and(Config::get('breaker.cooldownSeconds'))->toBe(300)
        ->and(Config::get('connectTimeout'))->toBe(1)
        ->and(Config::get('exposeRequestId'))->toBeTrue()
        ->and(Config::get('storeUser'))->toBeTrue()
        ->and(Config::get('userIdProperty'))->toBe('id')
        ->and(Config::get('autoMigrate'))->toBeFalse()
        ->and(Config::get('storeExceptionMessage'))->toBeTrue()
        ->and(Config::database())->toBe('default');
});

it('merges nested overrides', function () {
    Config::fake(['enabled' => true, 'slowMs' => ['query' => 50]]);

    expect(Config::get('slowMs.query'))->toBe(50)
        ->and(Config::get('slowMs.request'))->toBe(1000);
});

it('clamps sample.normal', function ($input, $expected) {
    Config::fake(['enabled' => true, 'sample' => ['normal' => $input]]);
    expect(Config::sampleNormal())->toBe($expected);
})->with([
    [0, 0.0],
    [1.5, 1.0],
    [-1, 0.1],
    ['abc', 0.1],
    [0.25, 0.25],
]);

it('reads settings.monitor from PSF config', function () {
    monitorUseConfig(['enabled' => true, 'database' => 'monitor']);

    expect(Config::enabled())->toBeTrue()
        ->and(Config::database())->toBe('monitor');
});

it('keeps nested defaults when a section is overridden by a scalar', function ($key, $value) {
    Config::fake(['enabled' => true, $key => $value]);

    expect(Config::get('slowMs.query'))->toBe(200)
        ->and(Config::get('breaker.failures'))->toBe(3)
        ->and(Config::get('retention.rawDays'))->toBe(7);
})->with([
    ['slowMs', 5],
    ['breaker', true],
    ['retention', null],
]);

it('yields defaults when settings.monitor is not an array', function ($value) {
    monitorUseConfig([], ['settings' => ['monitor' => $value]]);

    expect(Config::enabled())->toBeFalse()
        ->and(Config::get('slowMs.query'))->toBe(200);
})->with(['texto', true]);

it('allows turning off the exception message storage', function () {
    Config::fake(['enabled' => true, 'storeExceptionMessage' => false]);
    expect(Config::get('storeExceptionMessage'))->toBeFalse();
});
