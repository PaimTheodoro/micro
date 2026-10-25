<?php

use Psf\Monitor\CircuitBreaker;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-cb-' . uniqid();
    mkdir($this->dir);
    $this->now = 1000;
    $this->make = fn (string $key = 'k') => new CircuitBreaker(
        $key, 3, 300, fn () => $this->now, $this->dir
    );
});

afterEach(function () {
    foreach (glob($this->dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('stays closed below the threshold', function () {
    $cb = ($this->make)();
    expect($cb->recordFailure())->toBeFalse()
        ->and($cb->recordFailure())->toBeFalse()
        ->and($cb->isOpen())->toBeFalse();
});

it('opens at the threshold and reports it once', function () {
    $cb = ($this->make)();
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->recordFailure())->toBeTrue()
        ->and($cb->isOpen())->toBeTrue()
        ->and($cb->recordFailure())->toBeFalse();
});

it('closes after the cooldown', function () {
    $cb = ($this->make)();
    $cb->trip();
    expect($cb->isOpen())->toBeTrue();
    $this->now += 301;
    expect($cb->isOpen())->toBeFalse();
});

it('success resets the failure count', function () {
    $cb = ($this->make)();
    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordSuccess();
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->isOpen())->toBeFalse();
});

it('trip opens immediately and only reports the first time', function () {
    $cb = ($this->make)();
    expect($cb->trip())->toBeTrue()
        ->and($cb->isOpen())->toBeTrue()
        ->and($cb->trip())->toBeFalse();
});

it('keeps separate state per key', function () {
    $a = ($this->make)('a');
    $b = ($this->make)('b');
    $a->trip();
    expect($a->isOpen())->toBeTrue()
        ->and($b->isOpen())->toBeFalse();
});

it('does not write state on success when the breaker is clean', function () {
    $cb = ($this->make)();
    $cb->recordSuccess();
    expect(glob($this->dir . '/*'))->toBe([]);

    // Estado já zerado no arquivo: o sucesso não regrava.
    $path = $this->dir . '/psf-monitor-' . md5('k') . '.json';
    file_put_contents($path, json_encode(['failures' => 0, 'open_until' => 0]) . ' ');
    $cb->recordSuccess();
    expect(file_get_contents($path))->toBe(json_encode(['failures' => 0, 'open_until' => 0]) . ' ');
});

it('success still clears a non-zero failure count or an expired open_until', function () {
    $cb = ($this->make)();
    $path = $this->dir . '/psf-monitor-' . md5('k') . '.json';

    $cb->recordFailure();
    $cb->recordSuccess();
    expect(json_decode(file_get_contents($path), true))->toBe(['failures' => 0, 'open_until' => 0]);

    $cb->trip();
    $this->now += 301;
    $cb->recordSuccess();
    expect(json_decode(file_get_contents($path), true))->toBe(['failures' => 0, 'open_until' => 0]);
});
