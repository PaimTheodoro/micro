<?php

use Psf\Enumerators\HttpStatusCode;
use Psf\Http\Http;
use Psf\Http\HttpResponseException;

function captureResponse(callable $call): HttpResponseException
{
    try {
        $call();
    } catch (HttpResponseException $e) {
        return $e;
    }

    throw new RuntimeException('Http::response não lançou HttpResponseException');
}

it('builds message and data payloads with the given status', function () {
    $e = captureResponse(fn() => Http::response('ok', ['a' => 1], 201));

    expect($e->getStatus())->toBe(201);
    expect($e->getPayload())->toBe(['message' => 'ok', 'data' => ['a' => 1]]);
});

it('accepts an HttpStatusCode enum and numeric strings', function () {
    expect(captureResponse(fn() => Http::response('', [], HttpStatusCode::NOT_FOUND))->getStatus())->toBe(404);
    expect(captureResponse(fn() => Http::response('', [], '202'))->getStatus())->toBe(202);
});

it('falls back to 500 for an out-of-range status', function () {
    expect(captureResponse(fn() => Http::response('x', [], 42))->getStatus())->toBe(500);
    expect(captureResponse(fn() => Http::response('x', [], 999))->getStatus())->toBe(500);
});
