<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\Notification;

/**
 * Webhook genérico: POST com o `Notification::toArray()` em JSON. Com a opção
 * `secret`, assina o corpo (HMAC-SHA256 em `X-Psf-Signature: sha256=<hex>`).
 */
final class WebhookNotifier extends WebhookChannel
{
    protected function payload(Notification $notification): array
    {
        return $notification->toArray();
    }

    protected function extraHeaders(string $body, array $headers): array
    {
        $secret = $this->options['secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            $headers[] = 'X-Psf-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        return $headers;
    }
}
