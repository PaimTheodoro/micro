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

it('emits expose headers when configured', function () {
    $headers = Cors::resolve(['origins' => '*', 'expose_headers' => ['X-Request-Id', 'X-Total']], 'https://a.com', 'GET')['headers'];

    expect($headers['Access-Control-Expose-Headers'])->toBe('X-Request-Id, X-Total');
});

it('omits expose headers by default', function () {
    $headers = Cors::resolve(['origins' => '*'], 'https://a.com', 'GET')['headers'];

    expect($headers)->not->toHaveKey('Access-Control-Expose-Headers');
});

it('ignores misconfigured expose headers instead of failing', function () {
    $headers = Cors::resolve([
        'origins'        => '*',
        'expose_headers' => [['X-Nested'], ' X-Total ', '', '   ', 42, null, true, 'X-Total', 'x-total', new stdClass(), 'X-Request-Id'],
    ], 'https://a.com', 'GET')['headers'];

    expect($headers['Access-Control-Expose-Headers'])->toBe('X-Total, X-Request-Id');
});

it('omits expose headers when no valid name remains', function () {
    $headers = Cors::resolve(['origins' => '*', 'expose_headers' => [[], '', ' ', 1]], 'https://a.com', 'GET')['headers'];

    expect($headers)->not->toHaveKey('Access-Control-Expose-Headers');
});

it('adds an exposed header tolerating a non-array expose_headers', function (mixed $expose, array $expected) {
    $config = Cors::withExposedHeader(['origins' => '*', 'expose_headers' => $expose], 'X-Request-Id');

    expect($config['expose_headers'])->toBe($expected)
        ->and($config['origins'])->toBe('*');
})->with([
    'string'        => ['X-Total', ['X-Request-Id']],
    'null'          => [null, ['X-Request-Id']],
    'int'           => [5, ['X-Request-Id']],
    'array'         => [['X-Total'], ['X-Total', 'X-Request-Id']],
    'já presente'   => [['x-request-id'], ['x-request-id']],
]);

it('adds an exposed header when expose_headers is missing', function () {
    expect(Cors::withExposedHeader(['origins' => '*'], 'X-Request-Id')['expose_headers'])->toBe(['X-Request-Id']);
});
