<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\Notification;

/** Discord (webhook de canal). Limite de 2000 caracteres do Discord respeitado. */
final class DiscordNotifier extends WebhookChannel
{
    protected function payload(Notification $notification): array
    {
        return ['content' => mb_substr('🚨 ' . $notification->title() . "\n```\n" . $notification->text() . "\n```", 0, 2000)];
    }
}
