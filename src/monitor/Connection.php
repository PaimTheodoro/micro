<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectFactory;
use Psf\Database\Dialect\SQLServerDialect;

/**
 * Abre a conexão PDO dedicada do monitor.
 *
 * EXCEÇÃO DELIBERADA à regra "não criar caminhos paralelos de CRUD": esta
 * conexão não passa por `Connect::getConnection()`. Assim ela (a) não herda
 * transações abertas pela aplicação, (b) não é instrumentada pelo próprio
 * monitor (evita recursão) e (c) não dispara a listagem de tabelas do `Connect`.
 */
final class Connection
{
    /**
     * Abre uma nova conexão PDO para a chave `$database` de config['db'], com
     * o timeout curto do monitor (`connectTimeout`) e ERRMODE de exceções.
     *
     * @throws \RuntimeException chave inexistente em config['db']
     * @throws \PDOException     falha ao conectar
     */
    public static function open(string $database): \PDO
    {
        $configs = \PSF::config('db');
        if (!is_array($configs) || !isset($configs[$database]) || !is_array($configs[$database])) {
            throw new \RuntimeException("Banco '{$database}' não encontrado em config['db'].");
        }
        $dbConfig = $configs[$database];

        $username = $dbConfig['username'] ?? $dbConfig['user'] ?? null;
        $password = $dbConfig['password'] ?? $dbConfig['pass'] ?? null;

        [$dsn, $options] = self::parameters($dbConfig, (int) Config::get('connectTimeout'));

        return new \PDO($dsn, $username, $password, $options);
    }

    /**
     * Monta o DSN e as opções PDO da conexão do monitor.
     *
     * O timeout curto vai em `PDO::ATTR_TIMEOUT`, exceto no SQL Server: o
     * pdo_sqlsrv rejeita esse atributo, então lá ele vira `LoginTimeout` no DSN.
     * `extras` do banco (chaves int) prevalecem onde colidirem; o ERRMODE é
     * sempre forçado para exceções.
     *
     * @return array{0: string, 1: array}
     */
    public static function parameters(array $dbConfig, int $timeout): array
    {
        $dialect = DialectFactory::fromConfig($dbConfig);
        $extras  = is_array($dbConfig['extras'] ?? null) ? $dbConfig['extras'] : [];
        $dsn     = $dialect->buildDsn($dbConfig);

        if ($dialect instanceof SQLServerDialect) {
            $dsn     = self::appendLoginTimeout($dsn, $timeout);
            $options = $extras;
        } else {
            $options = $extras + [\PDO::ATTR_TIMEOUT => $timeout];
        }
        $options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;

        return [$dsn, $options];
    }

    /** Acrescenta `LoginTimeout=<s>` ao DSN do sqlsrv, se ele ainda não tiver um. */
    public static function appendLoginTimeout(string $dsn, int $timeout): string
    {
        if (preg_match('/[:;]\s*LoginTimeout\s*=/i', $dsn) === 1) {
            return $dsn;
        }
        return rtrim($dsn, ';') . ';LoginTimeout=' . $timeout;
    }
}
