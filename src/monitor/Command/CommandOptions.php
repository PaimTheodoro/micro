<?php

namespace Psf\Monitor\Command;

use Psf\Monitor\Config;
use Psf\Monitor\Pruner;

/**
 * Validações compartilhadas pelos comandos do monitor (feitas antes de qualquer
 * escrita): retenção em dias e `--max-hours`.
 */
final class CommandOptions
{
    /** Teto de dias de retenção (garante que `raw_days * 4` nunca estoure); o mesmo do Pruner */
    public const MAX_RETENTION_DAYS = Pruner::MAX_RAW_DAYS;

    /** Teto de `--max-hours` por execução */
    public const MAX_HOURS = 10000;

    /**
     * Lê `retention.<chave>` (inteiro, ou string só de dígitos) e exige valor >= 1.
     *
     * @throws \InvalidArgumentException se inválido
     */
    public static function retentionDays(string $key): int
    {
        $value = Config::get("retention.{$key}");
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException("retention.{$key} inválido: informe um inteiro >= 1");
        }
        if ($value > self::MAX_RETENTION_DAYS) {
            throw new \InvalidArgumentException(
                "retention.{$key} inválido: informe um inteiro entre 1 e " . self::MAX_RETENTION_DAYS
            );
        }
        return $value;
    }

    /**
     * Valida `--max-hours` (inteiro ou string só de dígitos, >= 1).
     *
     * @throws \InvalidArgumentException se inválido
     */
    public static function maxHours(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('--max-hours inválido: informe um inteiro >= 1');
        }
        if ($value > self::MAX_HOURS) {
            throw new \InvalidArgumentException('--max-hours inválido: informe um inteiro entre 1 e ' . self::MAX_HOURS);
        }
        return $value;
    }
}
