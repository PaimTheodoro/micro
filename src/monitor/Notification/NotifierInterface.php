<?php

namespace Psf\Monitor\Notification;

/**
 * Destino de um aviso do monitor (Slack, e-mail, webhook, ...). O projeto
 * implementa esta interface (ou usa um canal pronto de `Channels/`) e registra
 * em `settings.monitor.notifiers`. Roda no shutdown, depois da resposta ao
 * cliente; uma exceção lançada aqui é contida e registrada no log, nunca
 * chega à aplicação.
 */
interface NotifierInterface
{
    public function notify(Notification $notification): void;
}
