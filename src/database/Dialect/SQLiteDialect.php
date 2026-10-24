<?php

namespace Psf\Database\Dialect;

class SQLiteDialect implements DialectInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') return '*';
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** SQLite não tem "banco" no sentido de schema do servidor: o arquivo já é o banco. */
    public function quoteTable(string $table, ?string $database = null): string
    {
        return $this->quoteIdentifier($table);
    }

    public function topClause(?int $limit, ?int $offset): string
    {
        return ''; // SQLite usa LIMIT no final
    }

    public function limitOffsetClause(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        if ($offset !== null) {
            // SQLite exige LIMIT junto com OFFSET; -1 = sem limite
            if ($limit === null) {
                $sql .= ' LIMIT -1';
            }
            $sql .= ' OFFSET ' . $offset;
        }
        return $sql;
    }

    public function listTablesQuery(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
    }

    public function columnsQuery(string $table): string
    {
        // pragma_table_info() como função de tabela aceita bind e devolve o mesmo formato
        // (Field/Type/Null/Key) que os demais dialetos.
        return "SELECT
            name AS \"Field\",
            type AS \"Type\",
            CASE WHEN \"notnull\" = 1 THEN 'NO' ELSE 'YES' END AS \"Null\",
            CASE WHEN pk > 0 THEN 'PRI' ELSE '' END AS \"Key\"
        FROM pragma_table_info(:table)";
    }

    public function columnsQueryParams(string $table): array
    {
        return ['table' => $table];
    }

    public function buildDsn(array $config): string
    {
        // `database` é o caminho do arquivo ou ':memory:'
        return 'sqlite:' . $config['database'];
    }

    public function autoIncrement(): string
    {
        return 'AUTOINCREMENT';
    }

    public function tableOptions(): string
    {
        return '';
    }

    public function addColumnSql(string $column, string $definition): string
    {
        return 'ADD COLUMN ' . $this->quoteIdentifier($column) . ' ' . $definition;
    }

    public function modifyColumnSql(string $column, string $definition): string
    {
        throw new \LogicException('SQLite não suporta ALTER COLUMN; recrie a tabela para alterar uma coluna.');
    }
}
