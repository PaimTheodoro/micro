<?php

namespace Psf\Monitor;

/**
 * Amostragem por peso do monitor.
 *
 * Erros, exceções e requisições/queries/chamadas externas lentas são sempre
 * mantidos (peso 1). Requisições normais são amostradas com probabilidade p e,
 * quando mantidas, recebem peso 1/p para que as agregações permaneçam não-enviesadas.
 */
final class Sampler
{
    /**
     * Retorna o peso do evento ou null se deve ser descartado.
     *
     * @param float|null $rand          Valor em [0, 1); padrão aleatório.
     * @param float      $maxExternalMs Maior duração de chamada externa do request
     *                                  (mantém com peso 1 se >= `slow_ms.external`).
     */
    public static function weight(
        int $status,
        float $durationMs,
        bool $hasException = false,
        float $maxQueryMs = 0.0,
        ?float $rand = null,
        float $maxExternalMs = 0.0
    ): ?float {
        if (
            $status >= 500
            || $hasException
            || $durationMs >= (float) Config::get('slow_ms.request')
            || $maxQueryMs >= (float) Config::get('slow_ms.query')
            || ($maxExternalMs > 0.0 && $maxExternalMs >= (float) Config::get('slow_ms.external'))
        ) {
            return 1.0;
        }

        $p = Config::sampleNormal();
        if ($p <= 0.0) {
            return null;
        }

        $rand ??= mt_rand() / (mt_getrandmax() + 1);
        return $rand < $p ? 1.0 / $p : null;
    }
}
