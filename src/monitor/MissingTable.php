<?php

namespace Psf\Monitor;

/**
 * Reconhece, pela mensagem, o erro de "tabela inexistente" dos drivers
 * (SQLite, MySQL, PostgreSQL e SQL Server). Usado para sugerir `monitor:install`.
 */
final class MissingTable
{
    private const PATTERN = '/no such table|doesn\'t exist|does not exist|invalid object name|base table or view not found|42S02|42P01/i';

    public const HINT = 'Tabelas do monitor ausentes ou desatualizadas: rode monitor:install.';

    public static function matches(\Throwable $e): bool
    {
        return preg_match(self::PATTERN, $e->getMessage()) === 1;
    }

    /** Sufixo (com espaço inicial) a anexar à mensagem de erro, ou vazio. */
    public static function suffix(\Throwable $e): string
    {
        return self::matches($e) ? ' ' . self::HINT : '';
    }
}
