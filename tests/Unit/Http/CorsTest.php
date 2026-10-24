<?php

use Psf\Http\Cors;

it('ignores requests without an Origin header', function () {
    expect(Cors::resolve(['origins' => '*'], null, 'GET'))->toBe(['headers' => [], 'preflight' => false]);
});

it('releases nothing by default', function () {
    expect(Cors::resolve([], 'https://a.com', 'GET')['headers'])->toBe([]);
});

it('answers * for a wildcard without credentials', function () {
    $result = Cors::resolve(['origins' => '*'], 'https://a.com', 'GET');

    expect($result['headers'])->toBe(['Access-Control-Allow-Origin' => '*']);
    expect($result['preflight'])->toBeFalse();
});

it('echoes the origin and varies on it for an allow-list', function () {
    $config = ['origins' => ['https://a.com', 'https://b.com']];

    expect(Cors::resolve($config, 'https://b.com', 'GET')['headers'])->toBe([
        'Access-Control-Allow-Origin' => 'https://b.com',
        'Vary'                        => 'Origin',
    ]);
    expect(Cors::resolve($config, 'https://evil.com', 'GET')['headers'])->toBe([]);
});

it('never combines * with credentials', function () {
    $headers = Cors::resolve(['origins' => '*', 'credentials' => true], 'https://a.com', 'GET')['headers'];

    expect($headers['Access-Control-Allow-Origin'])->toBe('https://a.com');
    expect($headers['Access-Control-Allow-Credentials'])->toBe('true');
});

it('treats OPTIONS with Access-Control-Request-Method as a preflight', function () {
    $result = Cors::resolve(['origins' => '*', 'max_age' => 60], 'https://a.com', 'OPTIONS', 'POST');

    expect($result['preflight'])->toBeTrue();
    expect($result['headers']['Access-Control-Allow-Methods'])->toContain('DELETE');
    expect($result['headers']['Access-Control-Allow-Headers'])->toBe('Content-Type, Authorization');
    expect($result['headers']['Access-Control-Max-Age'])->toBe('60');
});

it('does not treat a plain OPTIONS as a preflight', function () {
    expect(Cors::resolve(['origins' => '*'], 'https://a.com', 'OPTIONS')['preflight'])->toBeFalse();
});
