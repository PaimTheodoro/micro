<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\Notification;

/**
 * Slack (Incoming Webhook). Opções extras: `mention` (ex.: `<!here>` ou
 * `<@U123>`, prefixa a mensagem), `username` e `channel`.
 */
final class SlackNotifier extends WebhookChannel
{
    protected function payload(Notification $notification): array
    {
        $mention = isset($this->options['mention']) && is_string($this->options['mention']) ? $this->options['mention'] . ' ' : '';
        $payload = ['text' => $mention . ':rotating_light: ' . $notification->title() . "\n```" . $notification->text() . '```'];

        foreach (['username', 'channel'] as $key) {
            if (!empty($this->options[$key]) && is_string($this->options[$key])) {
                $payload[$key] = $this->options[$key];
            }
        }

        return $payload;
    }
}
