<?php

namespace Psf\Database\Dialect;

use Psf\Enumerators\DBDriver;

class DialectFactory
{
    public static function make(DBDriver $driver): DialectInterface
    {
        return match($driver) {
            DBDriver::MySQL      => new MySQLDialect(),
            DBDriver::SQLServer  => new SQLServerDialect(),
            DBDriver::PostgreSQL => new PostgreSQLDialect(),
        };
    }

    /**
     * Cria o dialeto a partir de um array de config do PSF (entry de 'db').
     * Usa MySQL como fallback quando o driver não está definido.
     */
    public static function fromConfig(array $config): DialectInterface
    {
        $driver = !empty($config['driver']) ? $config['driver'] : DBDriver::MySQL;
        return self::make($driver);
    }
}
