<?php

use Psf\Monitor\Config;

it('is disabled when settings.monitor is missing', function () {
    Config::fake(null);
    expect(Config::enabled())->toBeFalse();
});

it('applies defaults', function () {
    Config::fake(['enabled' => true]);

    expect(Config::get('sample.normal'))->toBe(0.1)
        ->and(Config::get('slow_ms.request'))->toBe(1000)
        ->and(Config::get('slow_ms.query'))->toBe(200)
        ->and(Config::get('max_events_per_request'))->toBe(200)
        ->and(Config::get('retention.raw_days'))->toBe(7)
        ->and(Config::get('retention.aggregate_days'))->toBe(90)
        ->and(Config::get('breaker.failures'))->toBe(3)
        ->and(Config::get('breaker.cooldown_seconds'))->toBe(300)
        ->and(Config::get('connect_timeout'))->toBe(1)
        ->and(Config::get('expose_request_id'))->toBeTrue()
        ->and(Config::get('store_user'))->toBeTrue()
        ->and(Config::get('user_id_property'))->toBe('id')
        ->and(Config::get('auto_migrate'))->toBeFalse()
        ->and(Config::get('store_exception_message'))->toBeTrue()
        ->and(Config::database())->toBe('default');
});

it('merges nested overrides', function () {
    Config::fake(['enabled' => true, 'slow_ms' => ['query' => 50]]);

    expect(Config::get('slow_ms.query'))->toBe(50)
        ->and(Config::get('slow_ms.request'))->toBe(1000);
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

    expect(Config::get('slow_ms.query'))->toBe(200)
        ->and(Config::get('breaker.failures'))->toBe(3)
        ->and(Config::get('retention.raw_days'))->toBe(7);
})->with([
    ['slow_ms', 5],
    ['breaker', true],
    ['retention', null],
]);

it('yields defaults when settings.monitor is not an array', function ($value) {
    monitorUseConfig([], ['settings' => ['monitor' => $value]]);

    expect(Config::enabled())->toBeFalse()
        ->and(Config::get('slow_ms.query'))->toBe(200);
})->with(['texto', true]);

it('allows turning off the exception message storage', function () {
    Config::fake(['enabled' => true, 'store_exception_message' => false]);
    expect(Config::get('store_exception_message'))->toBeFalse();
});
