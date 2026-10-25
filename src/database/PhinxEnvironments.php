<?php

namespace Psf\Database;

use Psf\Enumerators\DBDriver;

/**
 * Traduz as configurações `config['db']` do framework em ambientes do Phinx.
 * Extraído do phinx.php para ser reutilizado (ex.: instalador do monitor).
 */
final class PhinxEnvironments
{
    /**
     * @param array<string|int, mixed> $dbConfigs
     * @return array<string|int, array<string, mixed>> [nomeDoAmbiente => ambiente Phinx]
     */
    public static function fromDbConfigs(array $dbConfigs): array
    {
        $environments = [];
        foreach ($dbConfigs as $envName => $config) {
            // Suporta os dois formatos: host/user/pass e hostname/username/password
            $host = $config['host'] ?? $config['hostname'] ?? null;
            $user = $config['user'] ?? $config['username'] ?? null;
            $pass = $config['pass'] ?? $config['password'] ?? null;
            $driver = $config['driver'] ?? null;
            $database = $config['database'] ?? null;

            if (empty($driver) || empty($database)) {
                continue;
            }

            $adapter = self::adapterFor($driver);

            // SQLite é um arquivo (ou :memory:) — não tem host nem credenciais
            if ($adapter === 'sqlite') {
                $environments[$envName] = [
                    'adapter' => 'sqlite',
                    'name'    => $database,
                    'suffix'  => $config['suffix'] ?? '',
                ];
                continue;
            }

            if (empty($host) || empty($user) || !isset($pass)) {
                continue;
            }

            // Porta e charset padrão dependem do driver
            $defaults = match ($adapter) {
                'pgsql'  => ['port' => 5432, 'charset' => 'utf8',    'collation' => null],
                'sqlsrv' => ['port' => 1433, 'charset' => 'utf8',    'collation' => null],
                default  => ['port' => 3306, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            };

            $environments[$envName] = [
                'adapter' => $adapter,
                'host'    => $host,
                'name'    => $database,
                'user'    => $user,
                'pass'    => $pass,
                'port'    => $config['port'] ?? $defaults['port'],
                'charset' => $config['charset'] ?? $defaults['charset'],
            ] + (($config['collation'] ?? $defaults['collation']) !== null
                ? ['collation' => $config['collation'] ?? $defaults['collation']]
                : []);
        }

        return $environments;
    }

    /**
     * Mapeia o driver do framework para o adapter do Phinx.
     * Configs legadas podem passar o valor inteiro do enum em vez da instância.
     */
    private static function adapterFor(mixed $driver): string
    {
        if (!$driver instanceof DBDriver) {
            $driver = DBDriver::tryFrom((int) $driver) ?? DBDriver::MySQL;
        }

        return match ($driver) {
            DBDriver::MySQL      => 'mysql',
            DBDriver::SQLServer  => 'sqlsrv',
            DBDriver::PostgreSQL => 'pgsql',
            DBDriver::SQLite     => 'sqlite',
        };
    }
}
