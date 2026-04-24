<?php

namespace Psf\Http;

class RequestParser
{
    /**
     * Parses the current HTTP request body into an associative array.
     * Handles application/json (raw body) and form-encoded/multipart ($_POST).
     * Merges $_GET parameters and strips the internal _url routing key.
     */
    public static function parseBody(): array
    {
        $data        = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null;

        if (!empty($contentType) && $contentType === 'application/json') {
            $requestData = json_decode(file_get_contents('php://input'), true);
            $data        = array_merge($data, $requestData ?? []);
        } elseif (!empty($contentType)) {
            $data = array_merge($data, $_POST ?? []);
        } else {
            $requestData = json_decode(file_get_contents('php://input'), true);
            $data        = array_merge($data, $requestData ?? []);
        }

        $data = array_merge($data, $_GET);
        unset($data['_url']);

        return $data;
    }

    /**
     * Extracts the Bearer token from the Authorization header, or null if absent.
     */
    public static function extractBearerToken(): ?string
    {
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];

        if (!empty($headers['Authorization'])) {
            return str_replace('Bearer ', '', $headers['Authorization']);
        }

        // Fallback for FastCGI / nginx environments
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
