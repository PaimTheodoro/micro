<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\{Notification, NotifierInterface};

/** Escreve o aviso no `error_log` (útil em dev ou como segundo destino). */
final class LogNotifier implements NotifierInterface
{
    /** @param array<string, mixed> $options */
    public function __construct(private array $options = [])
    {
    }

    public function notify(Notification $notification): void
    {
        error_log('[PSF][monitor][alerta] ' . str_replace("\n", ' | ', $notification->text()));
    }
}
