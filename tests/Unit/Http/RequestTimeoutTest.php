<?php

use Psf\Http\{Http, Request};

// Servidor TCP que aceita a conexão (backlog do kernel) mas nunca responde.
function silentServer(): array{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name   = stream_socket_get_name($server, false);

    return [$server, 'http://' . $name . '/'];
}

it('Request::timeout() encerra uma chamada que não responde', function () {
    [$server, $url] = silentServer();

    $start    = microtime(true);
    $response = Request::get()->url($url)->timeout(1)->send();

    expect($response)->toBeFalse()
        ->and(microtime(true) - $start)->toBeLessThan(5);
    fclose($server);
});

it('Request::timeout() rejeita valor negativo', function () {
    expect(fn () => Request::get()->timeout(-1))->toThrow(InvalidArgumentException::class);
});

it('Http::request() aceita timeout por chamada e devolve sem travar', function () {
    [$server, $url] = silentServer();

    $start  = microtime(true);
    $result = Http::request('GET', $url, [], [], 1);

    expect($result['code'])->toBe(0)
        ->and(microtime(true) - $start)->toBeLessThan(5);
    fclose($server);
});

it('o template de docs escapa a description', function () {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/http/templates/docsTemplate.phtml');

    expect($source)->toContain("htmlspecialchars((string) \$request['description'])")
        ->and($source)->not->toContain("<?= \$request['description'] ?>");
});
