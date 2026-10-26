<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\Notification;

/** Microsoft Teams (webhook de conector, formato MessageCard). */
final class TeamsNotifier extends WebhookChannel
{
    protected function payload(Notification $notification): array
    {
        return [
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'summary'    => $notification->title(),
            'themeColor' => 'D13438',
            'title'      => $notification->title(),
            'text'       => str_replace("\n", "\n\n", $notification->text()),
        ];
    }
}
