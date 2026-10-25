<?php

namespace Psf\Monitor;

/**
 * Configuração do monitor (observabilidade).
 *
 * Lê `settings.monitor` do config do PSF, mescla com os defaults
 * (merge recursivo) e memoiza o resultado até `reset()`.
 */
final class Config
{
    /** Defaults globais do monitor. */
    private const DEFAULTS = [
        'enabled'                => false,
        'sample'                 => ['normal' => 0.1],
        'slow_ms'                => ['request' => 1000, 'query' => 200, 'external' => 1000],
        'max_events_per_request' => 200,
        'retention'              => ['raw_days' => 7, 'aggregate_days' => 90],
        'breaker'                => ['failures' => 3, 'cooldown_seconds' => 300],
        'connect_timeout'        => 1,
        'expose_request_id'      => true,
        'store_user'             => true,
        'user_id_property'       => 'id',
        'auto_migrate'           => false,
        'store_exception_message' => true,
        'api'                    => ['enabled' => false],
    ];

    /** @var array|null Configuração mesclada memoizada. */
    private static ?array $resolved = null;

    /** @var array|null|false Settings forçados em testes (false = sem fake). */
    private static array|null|false $fake = false;

    /** Indica se o monitor está habilitado. */
    public static function enabled(): bool
    {
        return (bool) self::get('enabled', false);
    }

    /** Lê um valor por caminho com pontos (ex.: `slow_ms.query`). */
    public static function get(string $dotPath, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $dotPath) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /** Taxa de amostragem normal, limitada ao intervalo [0, 1]. */
    public static function sampleNormal(): float
    {
        $value = self::get('sample.normal');
        if (!is_int($value) && !is_float($value) || $value < 0) {
            return 0.1;
        }
        return (float) min($value, 1.0);
    }

    /** Nome da conexão de banco usada pelo monitor. */
    public static function database(): string
    {
        $db = self::get('database');
        return is_string($db) && $db !== '' ? $db : 'default';
    }

    /** Força as settings (apenas testes). `null` simula chave ausente. */
    public static function fake(?array $settings): void
    {
        self::$fake     = $settings;
        self::$resolved = null;
    }

    /** Limpa a memoização e o fake. */
    public static function reset(): void
    {
        self::$fake     = false;
        self::$resolved = null;
    }

    /** Configuração final (defaults + settings). */
    private static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $settings = self::$fake !== false ? self::$fake : \PSF::config('settings', 'monitor');
        if (!is_array($settings)) {
            $settings = [];
        }

        return self::$resolved = self::merge(self::DEFAULTS, $settings);
    }

    /**
     * Merge recursivo que preserva os defaults quando um default é array
     * e o valor informado não é (sobrescrita escalar é ignorada).
     */
    private static function merge(array $defaults, array $override): array
    {
        foreach ($override as $key => $value) {
            if (array_key_exists($key, $defaults) && is_array($defaults[$key])) {
                if (is_array($value)) {
                    $defaults[$key] = self::merge($defaults[$key], $value);
                }
                continue;
            }
            $defaults[$key] = $value;
        }
        return $defaults;
    }
}
