<?php

namespace Psf\Http;

/**
 * Resolve os headers CORS a partir de `config['settings']['cors']`.
 *
 * Configuração (todas as chaves são opcionais):
 *   'origins'     => ['https://app.exemplo.com'] | '*'   (padrão: [] — nenhuma origem liberada)
 *   'methods'     => ['GET', 'POST', ...]                (padrão: GET, POST, PUT, PATCH, DELETE, OPTIONS)
 *   'headers'     => ['Content-Type', 'Authorization']   (padrão: os dois acima)
 *   'credentials' => bool                                (padrão: false)
 *   'max_age'     => int segundos do cache do preflight  (padrão: 600)
 */
class Cors
{
    private const DEFAULT_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const DEFAULT_HEADERS = ['Content-Type', 'Authorization'];

    /**
     * @return array{headers: array<string, string>, preflight: bool}
     *   `headers`   — headers a enviar (vazio se a requisição não é CORS ou a origem não é permitida);
     *   `preflight` — true quando a requisição é um preflight liberado e deve ser respondida com 204.
     */
    public static function resolve(array $config, ?string $origin, string $method, ?string $requestedMethod = null): array
    {
        $none = ['headers' => [], 'preflight' => false];

        if ($origin === null || $origin === '') {
            return $none;
        }

        $origins     = $config['origins'] ?? [];
        $credentials = !empty($config['credentials']);
        $wildcard    = $origins === '*' || (is_array($origins) && in_array('*', $origins, true));

        if (!$wildcard && !in_array($origin, (array) $origins, true)) {
            return $none;
        }

        $headers = [];

        if ($wildcard && !$credentials) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } else {
            // Com credenciais o spec proíbe '*': devolve a origem específica
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary']                        = 'Origin';
        }

        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $preflight = strtoupper($method) === 'OPTIONS' && !empty($requestedMethod);

        if ($preflight) {
            $headers['Access-Control-Allow-Methods'] = implode(', ', $config['methods'] ?? self::DEFAULT_METHODS);
            $headers['Access-Control-Allow-Headers'] = implode(', ', $config['headers'] ?? self::DEFAULT_HEADERS);
            $headers['Access-Control-Max-Age']       = (string) ($config['max_age'] ?? 600);
        }

        return ['headers' => $headers, 'preflight' => $preflight];
    }
}
