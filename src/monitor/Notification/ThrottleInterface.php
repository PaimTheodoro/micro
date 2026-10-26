<?php

namespace Psf\Monitor\Notification;

/**
 * Limita a repetição de avisos iguais. O projeto pode fornecer a sua (por
 * exemplo, em Redis ou numa tabela, para valer entre servidores) com
 * `Dispatcher::useThrottle()`.
 */
interface ThrottleInterface
{
    /**
     * Tenta liberar um aviso para `$key`. Devolve null se ainda está dentro da
     * janela (o aviso deve ser suprimido e contado); senão abre a janela de
     * `$seconds` e devolve quantos avisos foram suprimidos na janela anterior.
     * Nunca lança: na dúvida, libera (devolve 0).
     */
    public function acquire(string $key, int $seconds): ?int;
}
