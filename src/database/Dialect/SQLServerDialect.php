<?php

namespace Psf\Database\Dialect;

class SQLServerDialect implements DialectInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') return '*';
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    public function quoteTable(string $table, ?string $database = null): string
    {
        if ($database !== null) {
            return '[' . $database . '].[dbo].[' . $table . ']';
        }
        return '[' . $table . ']';
    }

    public function topClause(?int $limit, ?int $offset): string
    {
        // TOP N só é usado quando há limit sem offset; com offset usa OFFSET/FETCH no final.
        if ($limit !== null && $offset === null) {
            return 'TOP ' . $limit . ' ';
        }
        return '';
    }

    public function limitOffsetClause(?int $limit, ?int $offset): string
    {
        // OFFSET/FETCH só faz sentido quando ambos estão presentes no SQL Server.
        if ($limit !== null && $offset !== null) {
            return ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
        }
        return '';
    }

    public function listTablesQuery(): string
    {
        return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'";
    }

    public function columnsQuery(string $table): string
    {
        // Dois parâmetros distintos porque PDO em modo não-emulado não reutiliza o mesmo nome.
        return "SELECT
            COLUMNINFO.COLUMN_NAME AS Field,
            COLUMNINFO.DATA_TYPE AS Type,
            COLUMNINFO.IS_NULLABLE AS [Null],
            (SELECT 'PRI'
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS COLUMNUSAGE
             WHERE COLUMNUSAGE.COLUMN_NAME = COLUMNINFO.COLUMN_NAME
               AND OBJECTPROPERTY(OBJECT_ID(CONSTRAINT_SCHEMA + '.' + CONSTRAINT_NAME), 'IsPrimaryKey') = 1
               AND COLUMNUSAGE.TABLE_NAME = :tbl1) AS [Key]
        FROM INFORMATION_SCHEMA.COLUMNS AS COLUMNINFO
        WHERE COLUMNINFO.TABLE_NAME = :tbl2";
    }

    public function columnsQueryParams(string $table): array
    {
        return ['tbl1' => $table, 'tbl2' => $table];
    }

    public function buildDsn(array $config): string
    {
        $host = $config['hostname'];
        $db   = $config['database'];
        return "sqlsrv:Server={$host};Database={$db}";
    }

    public function autoIncrement(): string
    {
        return 'IDENTITY(1,1)';
    }

    public function tableOptions(): string
    {
        return ''; // SQL Server não tem ENGINE ou CHARSET no CREATE TABLE
    }

    public function addColumnSql(string $column, string $definition): string
    {
        // SQL Server omite a palavra COLUMN
        return 'ADD [' . $column . '] ' . $definition;
    }

    public function modifyColumnSql(string $column, string $definition): string
    {
        return "ALTER COLUMN [{$column}] {$definition}";
    }
}
