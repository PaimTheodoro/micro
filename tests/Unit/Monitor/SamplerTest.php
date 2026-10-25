<?php

use Psf\Monitor\Config;
use Psf\Monitor\Sampler;

beforeEach(function () {
    Config::fake(['enabled' => true, 'sample' => ['normal' => 0.1]]);
});

it('always keeps errors, exceptions and slow requests with weight 1', function () {
    expect(Sampler::weight(500, 10, rand: 0.99))->toBe(1.0)
        ->and(Sampler::weight(503, 10, rand: 0.99))->toBe(1.0)
        ->and(Sampler::weight(200, 10, true, rand: 0.99))->toBe(1.0)
        ->and(Sampler::weight(200, 1000, rand: 0.99))->toBe(1.0)
        ->and(Sampler::weight(200, 10, false, 200.0, 0.99))->toBe(1.0);
});

it('keeps a sampled normal request with weight 1/p', function () {
    expect(Sampler::weight(200, 10, rand: 0.05))->toBe(10.0);
});

it('drops a normal request outside the sample', function () {
    expect(Sampler::weight(200, 10, rand: 0.1))->toBeNull()
        ->and(Sampler::weight(200, 10, rand: 0.9))->toBeNull();
});

it('never divides by zero when sample.normal is 0', function () {
    Config::fake(['enabled' => true, 'sample' => ['normal' => 0]]);
    expect(Sampler::weight(200, 10, rand: 0.0))->toBeNull();
});

it('keeps everything with sample.normal 1', function () {
    Config::fake(['enabled' => true, 'sample' => ['normal' => 1]]);
    expect(Sampler::weight(200, 10, rand: 0.99))->toBe(1.0);
});
