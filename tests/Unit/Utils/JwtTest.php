<?php

use Psf\Utils\JWT;

// Cobertura para as correções da Vuln 2.2:
// - hash_equals() contra timing attack
// - verificação de exp e nbf
// - validação de estrutura (3 partes)

it('encodes and decodes a valid token correctly', function () {
    $payload = ['user_id' => 42, 'role' => 'admin'];

    $token   = JWT::encode($payload);
    $decoded = JWT::decode($token, true);

    expect($decoded)->toMatchArray($payload);
});

it('returns false when signature is tampered', function () {
    $token   = JWT::encode(['user_id' => 1]);
    $parts   = explode('.', $token);
    $tampered = $parts[0] . '.' . $parts[1] . '.invalidsignature';

    expect(JWT::decode($tampered, true))->toBeFalse();
});

it('returns false for an expired token (exp in the past)', function () {
    $token = JWT::encode(['user_id' => 1, 'exp' => time() - 3600]);

    expect(JWT::decode($token, true))->toBeFalse();
});

it('returns false for a not-yet-valid token (nbf in the future)', function () {
    $token = JWT::encode(['user_id' => 1, 'nbf' => time() + 3600]);

    expect(JWT::decode($token, true))->toBeFalse();
});

it('accepts a token whose exp is in the future', function () {
    $payload = ['user_id' => 1, 'exp' => time() + 3600];
    $token   = JWT::encode($payload);

    expect(JWT::decode($token, true))->toMatchArray($payload);
});

it('returns false for a malformed token with wrong number of parts', function () {
    expect(JWT::decode('only.twoparts', true))->toBeFalse();
    expect(JWT::decode('one', true))->toBeFalse();
    expect(JWT::decode('a.b.c.d', true))->toBeFalse();
});

it('skips all validation when $valid is false (useful for inspection)', function () {
    // Token expirado — mas decode sem validação deve retornar o payload
    $payload = ['user_id' => 99, 'exp' => time() - 9999];
    $token   = JWT::encode($payload);

    $decoded = JWT::decode($token, false);

    expect($decoded)->toMatchArray($payload);
});

it('returns false when token has no payload (empty string)', function () {
    expect(JWT::decode('', true))->toBeFalse();
});
