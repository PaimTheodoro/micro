<?php

namespace Psf\Http;

class RequestParser
{
    /**
     * Parses the current HTTP request body into an associative array.
     * Handles application/json (raw body) and form-encoded/multipart ($_POST).
     * Merges $_GET parameters (body wins on key conflicts) and strips the internal _url routing key.
     */
    public static function parseBody(): array
    {
        $data        = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null;
        // Ignora parâmetros como "; charset=utf-8" e diferenças de caixa
        $mediaType   = strtolower(trim(explode(';', (string) $contentType)[0]));

        if ($mediaType === 'application/json' || str_ends_with($mediaType, '+json')) {
            $data = self::decodeJsonBody();
        } elseif ($mediaType !== '') {
            $data = $_POST;

            // PUT/PATCH com form-urlencoded: o PHP só popula $_POST em POST
            if (empty($data) && $mediaType === 'application/x-www-form-urlencoded') {
                parse_str((string) file_get_contents('php://input'), $data);
            }
        } else {
            $data = self::decodeJsonBody();
        }

        // O body tem precedência: a query string não pode sobrescrever campos enviados no body
        $data = array_merge($_GET, $data);
        unset($data['_url']);

        return $data;
    }

    /**
     * Decodifica o body JSON. Só objetos/arrays são aceitos: JSON inválido ou escalar vira [].
     */
    private static function decodeJsonBody(): array
    {
        $decoded = json_decode((string) file_get_contents('php://input'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extracts the Bearer token from the Authorization header, or null if absent.
     */
    public static function extractBearerToken(): ?string
    {
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        // Cabeçalhos HTTP são case-insensitive (HTTP/2 os entrega em minúsculas)
        $headers = array_change_key_case($headers, CASE_LOWER);

        // Fallback for FastCGI / nginx environments
        $authHeader = $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if (is_string($authHeader) && preg_match('/^Bearer\s+(\S+)\s*$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
