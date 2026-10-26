<?php

namespace Psf\Monitor\Notification\Channels;

/**
 * Envio HTTP dos canais por webhook. Usa cURL direto (e não `Psf\Http\Request`)
 * para que o aviso não seja contado como chamada externa da aplicação.
 */
final class Transport
{
    /**
     * @param array<int, string> $headers linhas `Nome: valor`
     * @return int|null status HTTP; null em falha de transporte
     */
    public static function post(string $url, string $body, array $headers, int $timeoutSeconds): ?int
    {
        if (!function_exists('curl_init') || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, min(5, $timeoutSeconds)),
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
        ]);

        $ok     = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return $ok === false || $status === 0 ? null : $status;
    }
}
