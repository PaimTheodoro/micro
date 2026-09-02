<?php

namespace Psf\Database\Dialect;

class MySQLDialect implements DialectInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') return '*';
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function quoteTable(string $table, ?string $database = null): string
    {
        if ($database !== null) {
            return '`' . $database . '`.`' . $table . '`';
        }
        return '`' . $table . '`';
    }

    public function topClause(?int $limit, ?int $offset): string
    {
        return ''; // MySQL usa LIMIT no final
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
        return 'SHOW TABLES';
    }

    public function columnsQuery(string $table): string
    {
        // SHOW COLUMNS é DDL e não suporta parâmetros PDO — tabela embutida diretamente.
        // Risco aceitável: $table sempre vem do atributo #[Table], não de input de usuário.
        return 'SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`';
    }

    public function columnsQueryParams(string $table): array
    {
        return [];
    }

    public function buildDsn(array $config): string
    {
        $host    = $config['hostname'];
        $db      = $config['database'];
        $port    = $config['port'] ?? 3306;
        return "mysql:host={$host};dbname={$db};port={$port};charset=utf8mb4";
    }

    public function autoIncrement(): string
    {
        return 'AUTO_INCREMENT';
    }

    public function tableOptions(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function addColumnSql(string $column, string $definition): string
    {
        return 'ADD COLUMN `' . $column . '` ' . $definition;
    }

    public function modifyColumnSql(string $column, string $definition): string
    {
        return "MODIFY COLUMN `{$column}` {$definition}";
    }
}
