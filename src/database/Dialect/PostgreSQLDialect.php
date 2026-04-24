<?php

namespace Psf\Database\Dialect;

class PostgreSQLDialect implements DialectInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') return '*';
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function quoteTable(string $table, ?string $database = null): string
    {
        if ($database !== null) {
            return '"' . $database . '"."' . $table . '"';
        }
        return '"' . $table . '"';
    }

    public function topClause(?int $limit, ?int $offset): string
    {
        return ''; // PostgreSQL usa LIMIT no final, igual ao MySQL
    }

    public function limitOffsetClause(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }
        return $sql;
    }

    public function listTablesQuery(): string
    {
        return "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
    }

    public function columnsQuery(string $table): string
    {
        // Dois parâmetros distintos (:table1, :table2) porque PDO com pgsql em modo
        // nativo não permite reutilizar o mesmo nome de parâmetro numa query.
        return "SELECT
            c.column_name AS \"Field\",
            c.data_type   AS \"Type\",
            c.is_nullable AS \"Null\",
            CASE WHEN pk.column_name IS NOT NULL THEN 'PRI' ELSE '' END AS \"Key\"
        FROM information_schema.columns c
        LEFT JOIN (
            SELECT ku.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage ku
              ON tc.constraint_name = ku.constraint_name
             AND tc.table_name      = ku.table_name
            WHERE tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_name = :table1
        ) pk ON c.column_name = pk.column_name
        WHERE c.table_name = :table2
        ORDER BY c.ordinal_position";
    }

    public function columnsQueryParams(string $table): array
    {
        return ['table1' => $table, 'table2' => $table];
    }

    public function buildDsn(array $config): string
    {
        $host = $config['hostname'];
        $db   = $config['database'];
        $port = $config['port'] ?? 5432;
        return "pgsql:host={$host};port={$port};dbname={$db}";
    }

    public function autoIncrement(): string
    {
        return 'SERIAL';
    }

    public function tableOptions(): string
    {
        return ''; // PostgreSQL não tem ENGINE ou CHARSET no CREATE TABLE
    }

    public function addColumnSql(string $column, string $definition): string
    {
        return 'ADD COLUMN "' . $column . '" ' . $definition;
    }

    public function modifyColumnSql(string $column, string $definition): string
    {
        return "ALTER COLUMN \"{$column}\" TYPE {$definition}";
    }
}
