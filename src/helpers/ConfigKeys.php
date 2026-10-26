<?php

namespace Psf\Helper;

/**
 * Padrão de nomes das chaves de configuração no PHP: camelCase (`rawDays`).
 * As chaves em snake_case (`raw_days`) continuam aceitas como alias legado;
 * quando as duas grafias aparecem, a camelCase vence. Colunas de banco seguem
 * em snake_case e não passam por aqui.
 */
final class ConfigKeys
{
    /** `raw_days` → `rawDays`; qualquer outra string volta como veio. */
    public static function camelKey(string $key): string
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/', $key)) {
            return $key;
        }

        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }

    /**
     * Converte as chaves (recursivamente) para camelCase. Subárvores listadas em
     * `$skip` (por chave já em camelCase) ficam intactas: são dados do projeto.
     *
     * @param array<mixed> $config
     * @param list<string> $skip
     * @return array<mixed>
     */
    public static function camelize(array $config, array $skip = []): array
    {
        $result = [];

        // Primeiro as chaves já em camelCase, para que vençam o alias legado
        foreach ($config as $key => $value) {
            if (is_string($key) && self::camelKey($key) !== $key) {
                continue;
            }
            $result[$key] = self::camelizeValue($key, $value, $skip);
        }

        foreach ($config as $key => $value) {
            if (!is_string($key) || ($camel = self::camelKey($key)) === $key || array_key_exists($camel, $result)) {
                continue;
            }
            $result[$camel] = self::camelizeValue($camel, $value, $skip);
        }

        return $result;
    }

    private static function camelizeValue(int|string $key, mixed $value, array $skip): mixed
    {
        if (!is_array($value) || (is_string($key) && in_array($key, $skip, true))) {
            return $value;
        }

        return self::camelize($value, $skip);
    }
}
