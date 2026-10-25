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
 *   'expose_headers' => ['X-Request-Id']                 (padrão: nenhum — emite Access-Control-Expose-Headers)
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

        $expose = self::headerNames($config['expose_headers'] ?? []);
        if ($expose !== []) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $expose);
        }

        $preflight = strtoupper($method) === 'OPTIONS' && !empty($requestedMethod);

        if ($preflight) {
            $headers['Access-Control-Allow-Methods'] = implode(', ', $config['methods'] ?? self::DEFAULT_METHODS);
            $headers['Access-Control-Allow-Headers'] = implode(', ', $config['headers'] ?? self::DEFAULT_HEADERS);
            $headers['Access-Control-Max-Age']       = (string) ($config['max_age'] ?? 600);
        }

        return ['headers' => $headers, 'preflight' => $preflight];
    }

    /**
     * Devolve a config com `$name` incluído em `expose_headers`, se ainda não
     * estiver (comparação sem diferenciar maiúsculas). Um `expose_headers`
     * que não seja array é tratado como vazio.
     */
    public static function withExposedHeader(array $config, string $name): array
    {
        $expose = isset($config['expose_headers']) && is_array($config['expose_headers'])
            ? $config['expose_headers']
            : [];

        if (!in_array(strtolower($name), array_map('strtolower', self::headerNames($expose)), true)) {
            $expose[] = $name;
        }
        $config['expose_headers'] = $expose;

        return $config;
    }

    /**
     * Normaliza uma lista de nomes de header vinda da config: mantém só
     * strings não vazias (aparadas), sem repetição (sem diferenciar
     * maiúsculas). Qualquer outra forma vira lista vazia.
     *
     * @return list<string>
     */
    private static function headerNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $names = [];
        $seen  = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            $key  = strtolower($item);
            if ($item === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[]    = $item;
        }
        return $names;
    }
}
