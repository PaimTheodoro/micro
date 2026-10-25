<?php

use Psf\Monitor\ExternalTarget;

it('extracts only the lowercase host from a url', function (string $url, string $esperado) {
    expect(ExternalTarget::fromUrl($url))->toBe($esperado);
})->with([
    'caminho e query' => ['https://api.exemplo.com/v1/users?token=abc', 'api.exemplo.com'],
    'usuario, senha, porta, fragmento' => ['https://user:senha@Api.Exemplo.COM:8443/x#f', 'api.exemplo.com'],
    'ipv6 sem colchetes' => ['http://[2001:db8::1]:80/a', '2001:db8::1'],
    'ipv4 com porta' => ['http://127.0.0.1:1', '127.0.0.1'],
    'esquema relativo' => ['//cdn.exemplo.com/a.js', 'cdn.exemplo.com'],
    'esquema maiusculo' => ['HTTPS://EXEMPLO.COM', 'exemplo.com'],
    'punycode' => ['https://xn--exemplo-9ua.com', 'xn--exemplo-9ua.com'],
    'arroba na senha' => ['https://user:p@ss@word@Api.exemplo.com/x', 'api.exemplo.com'],
    'ponto final no host' => ['https://exemplo.com./x', 'exemplo.com'],
    'arroba apos o caminho' => ['https://exemplo.com/a@evil.com', 'exemplo.com'],
    'query sem caminho' => ['https://exemplo.com?x=1', 'exemplo.com'],
]);

it('returns (invalido) for invalid urls', function (string $url) {
    expect(ExternalTarget::fromUrl($url))->toBe('(invalido)');
})->with([
    'sem esquema' => ['api.exemplo.com/v1'],
    'vazio' => [''],
    'lixo' => ['not a url'],
    'sem host' => ['http://'],
    'crlf no host' => ["http://a\r\nb.com"],
    'espaco no host' => ['http://a b.com'],
    'tab no host' => ["http://a\tb.com"],
    'nul no host' => ["http://a\0b.com"],
    'file' => ['file:///etc/passwd'],
    'ftp' => ['ftp://exemplo.com'],
    'javascript' => ['javascript://exemplo.com'],
    'host vazio com porta' => ['http://:80'],
    'so pontos' => ['http://./x'],
    'ipv6 invalido' => ['http://[zzz]/'],
    'ipv6 sem fechar' => ['http://[::1/'],
    'porta invalida' => ['http://exemplo.com:abc/'],
    'dois pontos no host' => ['http://a:b:80/'],
    'utf8 invalido' => ["http://\xff\xfe.com"],
    'espaco unicode' => ["http://a\u{00A0}b.com"],
]);

it('limits the host to 191 characters', function () {
    $host = str_repeat('a', 300) . '.com';
    $r = ExternalTarget::fromUrl("https://{$host}/x");
    expect(mb_strlen($r))->toBeLessThanOrEqual(191)->and($r)->toBe(str_repeat('a', 191));
});

it('cuts multibyte hosts without breaking utf-8', function () {
    $r = ExternalTarget::fromUrl('https://' . str_repeat('é', 300) . '.com');
    expect(mb_strlen($r))->toBeLessThanOrEqual(191)->and(mb_check_encoding($r, 'UTF-8'))->toBeTrue();
});

it('stays fast and bounded with a 1 MB input', function () {
    $t = microtime(true);
    $a = ExternalTarget::fromUrl('https://' . str_repeat('u:p@', 262144));
    $b = ExternalTarget::fromUrl('https://' . str_repeat('a', 1048576));
    $c = ExternalTarget::fromUrl(str_repeat('/', 1048576));
    $d = ExternalTarget::fromUrl('https://exemplo.com/' . str_repeat('x', 1048576));
    expect(microtime(true) - $t)->toBeLessThan(1.0)
        ->and(mb_strlen($a))->toBeLessThanOrEqual(191)
        ->and(mb_strlen($b))->toBeLessThanOrEqual(191)
        ->and($c)->toBe('(invalido)')
        ->and($d)->toBe('exemplo.com');
});

it('labels smtp hosts safely', function (string $host, string $esperado) {
    expect(ExternalTarget::smtp($host))->toBe($esperado);
})->with([
    ['SMTP.Exemplo.com', 'smtp.exemplo.com'],
    ['', '(invalido)'],
    ['a b', '(invalido)'],
    ["a\r\nb", '(invalido)'],
    ['user@smtp.exemplo.com', '(invalido)'],
    ['smtp.exemplo.com.', 'smtp.exemplo.com'],
    ['::1', '::1'],
]);

it('labels storage as provider/operation', function (string $p, string $o, string $esperado) {
    expect(ExternalTarget::storage($p, $o))->toBe($esperado);
})->with([
    ['R2', 'putObject', 'r2/putobject'],
    ['r2', 'listObjects', 'r2/listobjects'],
    ['', 'x', '(invalido)/x'],
    ['r2', '', 'r2/(invalido)'],
    ['r2', 'put/Object', 'r2/(invalido)'],
    ['r2', 'put Object', 'r2/(invalido)'],
    ['r2', "put\nObject", 'r2/(invalido)'],
    ['r 2', 'put_Object', '(invalido)/put_object'],
    ['', '', '(invalido)/(invalido)'],
]);

it('bounds storage labels to 191 characters', function () {
    $r = ExternalTarget::storage(str_repeat('p', 300), str_repeat('o', 300));
    expect(mb_strlen($r))->toBeLessThanOrEqual(191);
});

it('never leaks secrets', function () {
    $saidas = [
        ExternalTarget::fromUrl('https://user:senha@api.exemplo.com/v1?token=abc#abc'),
        ExternalTarget::fromUrl('https://user:senha@/x?token=abc'),
        ExternalTarget::fromUrl('user:senha@x?token=abc'),
        ExternalTarget::smtp('user:senha@smtp.exemplo.com'),
        ExternalTarget::storage('r2', 'token'),
    ];
    foreach (array_slice($saidas, 0, 4) as $s) {
        foreach (['senha', 'token', 'abc', 'user'] as $segredo) {
            expect($s)->not->toContain($segredo);
        }
    }
});

it('rejects backslash in the authority instead of leaking userinfo', function (string $url, array $segredos) {
    $r = ExternalTarget::fromUrl($url);
    expect($r)->toBe('(invalido)');
    foreach ($segredos as $s) {
        expect($r)->not->toContain($s);
    }
})->with([
    ['https://sk_live_ABC\\@h.com', ['sk_live']],
    ['https://us\\er:pw@h.com', ['us']],
    ['https://trusted.com\\@attacker.com', ['trusted']],
    ['https://h.com\\x', ['h.com']],
]);

it('accepts a backslash after the authority', function () {
    expect(ExternalTarget::fromUrl('https://h.com/a\\b'))->toBe('h.com')
        ->and(ExternalTarget::fromUrl('https://h.com?x=\\'))->toBe('h.com');
});

it('never turns cut userinfo into the host', function () {
    $pre = 'https://SECRET';
    $at = fn (int $pos) => $pre . str_repeat('a', $pos - strlen($pre)) . '@h.com';
    // `@` perto do byte 4096 (4095, 4096, 4097): todos além do corte sem terminador
    foreach ([4095, 4096, 4097, 5000] as $pos) {
        $r = ExternalTarget::fromUrl($at($pos));
        expect($r)->toBe('(invalido)');
    }
    expect(ExternalTarget::fromUrl($pre . str_repeat('a', 4083) . '@h.com'))->not->toContain('secret');
});

it('keeps the host when a long path follows a short authority', function () {
    expect(ExternalTarget::fromUrl('https://user:pw@host.com/' . str_repeat('x', 10000)))->toBe('host.com')
        ->and(ExternalTarget::fromUrl('https://host.com?' . str_repeat('x', 10000)))->toBe('host.com')
        ->and(ExternalTarget::fromUrl('//host.com#' . str_repeat('x', 10000)))->toBe('host.com');
});

it('keeps normal urls working', function () {
    expect(ExternalTarget::fromUrl('https://user:pw@host/'))->toBe('host')
        ->and(ExternalTarget::fromUrl('http://Api.Exemplo.com:8080/x'))->toBe('api.exemplo.com')
        ->and(ExternalTarget::fromUrl('https://exemplo.com'))->toBe('exemplo.com');
});

it('treats oversized smtp and storage inputs as invalid', function () {
    expect(ExternalTarget::smtp(str_repeat('a', 5000)))->toBe('(invalido)')
        ->and(ExternalTarget::storage(str_repeat('a', 5000), 'x'))->toBe('(invalido)/x')
        ->and(ExternalTarget::storage('r2', str_repeat('a', 5000)))->toBe('r2/(invalido)');
});
