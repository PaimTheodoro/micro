<?php

namespace Psf\Database\Dialect;

use Psf\Enumerators\DBDriver;

class DialectFactory
{
    /**
     * Aceita a instância do enum ou o valor inteiro dele (configs legadas).
     */
    public static function make(DBDriver|int $driver): DialectInterface
    {
        if (is_int($driver)) {
            $driver = DBDriver::tryFrom($driver)
                ?? throw new \InvalidArgumentException("Driver de banco desconhecido: {$driver}");
        }

        return match($driver) {
            DBDriver::MySQL      => new MySQLDialect(),
            DBDriver::SQLServer  => new SQLServerDialect(),
            DBDriver::PostgreSQL => new PostgreSQLDialect(),
            DBDriver::SQLite     => new SQLiteDialect(),
        };
    }

    /**
     * Cria o dialeto a partir de um array de config do PSF (entry de 'db').
     * Usa MySQL como fallback quando o driver não está definido.
     */
    public static function fromConfig(array $config): DialectInterface
    {
        $driver = !empty($config['driver']) ? $config['driver'] : DBDriver::MySQL;
        return self::make(is_numeric($driver) ? (int) $driver : $driver);
    }
}
