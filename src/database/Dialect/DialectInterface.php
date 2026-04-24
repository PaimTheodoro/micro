<?php

namespace Psf\Database\Dialect;

interface DialectInterface
{
    // --- Quoting de identificadores ---

    /** Envolve um identificador (coluna, tabela) nas aspas do driver. */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Retorna a referência completa de uma tabela, opcionalmente prefixada
     * pelo nome do banco de dados.
     * Ex.: MySQL → `mydb`.`users`  |  SQLServer → [mydb].[dbo].[users]
     */
    public function quoteTable(string $table, ?string $database = null): string;

    // --- Cláusulas de paginação ---

    /**
     * Retorna a cláusula TOP que vai logo após SELECT (vazio para MySQL/PostgreSQL).
     * Ex.: SQLServer com limit=10, offset=null → "TOP 10 "
     */
    public function topClause(?int $limit, ?int $offset): string;

    /**
     * Retorna a cláusula de paginação que vai no fim da query.
     * Ex.: MySQL/PostgreSQL → "LIMIT 10 OFFSET 20"
     *      SQLServer com offset → "OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY"
     */
    public function limitOffsetClause(?int $limit, ?int $offset): string;

    // --- Queries de schema ---

    /** SQL que lista todas as tabelas do banco atual. */
    public function listTablesQuery(): string;

    /**
     * SQL que retorna as colunas de uma tabela.
     * Pode conter placeholders (:table1, :table2, etc.).
     * Use columnsQueryParams() para obter os valores a fazer bind.
     */
    public function columnsQuery(string $table): string;

    /**
     * Parâmetros para bind da query de colunas.
     * MySQL retorna [] pois o nome da tabela é embutido no SQL (DDL statement).
     */
    public function columnsQueryParams(string $table): array;

    // --- Construção de DSN ---

    /** Monta a string DSN para o PDO a partir do array de config do PSF. */
    public function buildDsn(array $config): string;

    // --- DDL helpers ---

    /** Palavra-chave de auto-incremento do driver. Ex.: AUTO_INCREMENT | IDENTITY(1,1) | SERIAL */
    public function autoIncrement(): string;

    /**
     * Retorna opções de tabela para CREATE TABLE (apenas MySQL usa isso).
     * Ex.: MySQL → " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
     *      SQLServer/PostgreSQL → ""
     */
    public function tableOptions(): string;

    /**
     * Retorna a cláusula "ADD [COLUMN] col def" para um ALTER TABLE.
     * Difere entre drivers: SQLServer omite a palavra COLUMN.
     */
    public function addColumnSql(string $column, string $definition): string;

    /**
     * Retorna a parte "MODIFY/ALTER COLUMN col definition" para um ALTER TABLE.
     * O chamador é responsável por incluir "ALTER TABLE table_name ".
     */
    public function modifyColumnSql(string $column, string $definition): string;
}
