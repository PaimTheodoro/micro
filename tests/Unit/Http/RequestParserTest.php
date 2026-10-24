<?php

use Psf\Http\RequestParser;

beforeEach(function () {
    $this->server = $_SERVER;
    $_GET = $_POST = [];
    unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
});

afterEach(function () {
    $_SERVER = $this->server;
    $_GET = $_POST = [];
});

it('uses $_POST for form content types and strips the routing key', function () {
    $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=xyz';
    $_POST = ['nome' => 'Ana'];
    $_GET  = ['_url' => 'v1/x'];

    expect(RequestParser::parseBody())->toBe(['nome' => 'Ana']);
});

it('lets the body win over the query string on key conflicts', function () {
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_POST = ['role' => 'user'];
    $_GET  = ['role' => 'admin', 'page' => '2'];

    expect(RequestParser::parseBody())->toBe(['role' => 'user', 'page' => '2']);
});

it('recognizes application/json with a charset parameter instead of falling back to $_POST', function () {
    $_SERVER['CONTENT_TYPE'] = 'Application/JSON; charset=utf-8';
    $_POST = ['deveria' => 'ser ignorado'];

    // Sem body no CLI: o ramo JSON devolve [] (o ramo de formulário devolveria $_POST)
    expect(RequestParser::parseBody())->toBe([]);
});

it('extracts a Bearer token regardless of scheme case', function (string $header, ?string $expected) {
    $_SERVER['HTTP_AUTHORIZATION'] = $header;

    expect(RequestParser::extractBearerToken())->toBe($expected);
})->with([
    'standard'       => ['Bearer abc.def.ghi', 'abc.def.ghi'],
    'lowercase'      => ['bearer abc', 'abc'],
    'extra spaces'   => ['Bearer   abc  ', 'abc'],
    'basic scheme'   => ['Basic dXNlcjpwYXNz', null],
    'token with word' => ['Bearer Bearer-x', 'Bearer-x'],
    'no token'       => ['Bearer', null],
]);

it('returns null when there is no Authorization header', function () {
    expect(RequestParser::extractBearerToken())->toBeNull();
});
