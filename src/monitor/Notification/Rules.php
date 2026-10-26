<?php

namespace Psf\Monitor\Notification;

/**
 * Decide se um request dispara o aviso de um notificador, a partir de `when`.
 *
 * Gatilhos (basta um): `statusGte` (int), `statuses` (lista de status),
 * `exceptions` (qualquer exceção registrada, inclusive tratadas), `fatal`,
 * `slowRequest`, `slowQuery`, `slowExternal` (acima de `slowMs.*`) e
 * `externalErrors` (chamada externa sem status ou >= 500). Sem nenhum
 * gatilho em `when`, valem os padrões: `statusGte` 500 e `fatal`.
 *
 * Filtros (todos precisam passar): `routes` e `ignoreRoutes` (padrões
 * `fnmatch` sobre o padrão da rota, ex.: `/v1/pagamentos/*`), `methods` e
 * `environments`.
 */
final class Rules
{
    private const TRIGGERS = ['statusGte', 'statuses', 'exceptions', 'fatal', 'slowRequest', 'slowQuery', 'slowExternal', 'externalErrors'];

    /**
     * @param array<string, mixed> $when
     * @param array<string, mixed> $facts ver Monitor::notificationFacts()
     */
    public static function matches(array $when, array $facts): bool
    {
        if (!self::passesFilters($when, $facts)) {
            return false;
        }

        if (array_intersect(self::TRIGGERS, array_keys($when)) === []) {
            $when += ['statusGte' => 500, 'fatal' => true];
        }

        $status = (int) $facts['status'];

        if (isset($when['statusGte']) && is_numeric($when['statusGte']) && $status >= (int) $when['statusGte']) {
            return true;
        }
        if (isset($when['statuses']) && is_array($when['statuses']) && in_array($status, array_map('intval', $when['statuses']), true)) {
            return true;
        }

        $flags = [
            'exceptions'     => 'hasException',
            'fatal'          => 'hasFatal',
            'slowRequest'    => 'slowRequest',
            'slowQuery'      => 'slowQuery',
            'slowExternal'   => 'slowExternal',
            'externalErrors' => 'hasExternalError',
        ];
        foreach ($flags as $key => $fact) {
            if (!empty($when[$key]) && !empty($facts[$fact])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $when */
    private static function passesFilters(array $when, array $facts): bool
    {
        $route = (string) $facts['route'];

        if (!empty($when['routes']) && !self::anyGlob((array) $when['routes'], $route)) {
            return false;
        }
        if (!empty($when['ignoreRoutes']) && self::anyGlob((array) $when['ignoreRoutes'], $route)) {
            return false;
        }
        if (!empty($when['methods']) && !in_array(strtoupper((string) $facts['method']), array_map('strtoupper', array_map('strval', (array) $when['methods'])), true)) {
            return false;
        }
        if (!empty($when['environments']) && !in_array((string) $facts['env'], array_map('strval', (array) $when['environments']), true)) {
            return false;
        }

        return true;
    }

    /** @param list<mixed> $patterns */
    private static function anyGlob(array $patterns, string $route): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $route)) {
                return true;
            }
        }

        return false;
    }
}
