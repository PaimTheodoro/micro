<?php

namespace Psf\Monitor\Sinks;

use Psf\Database\Dialect\DialectFactory;
use Psf\Database\Dialect\DialectInterface;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\SqlDate;

/**
 * Grava lotes do monitor no banco: uma transação, um INSERT multi-linha por chunk.
 * Usa a conexão dedicada (`Connection::open`), nunca o `Connect` da aplicação.
 */
final class DatabaseSink implements SinkInterface
{
    /** Chave do lote => tabela. Novas chaves entram nas próximas fases. */
    private const TABLES = [
        'requests'   => 'psf_monitor_requests',
        'queries'    => 'psf_monitor_queries',
        'exceptions' => 'psf_monitor_exceptions',
        'external'   => 'psf_monitor_external',
    ];

    private ?\Closure $pdoResolver;
    private string $database;
    private ?\PDO $pdo = null;
    private ?DialectInterface $dialect;

    /**
     * @param ?\Closure         $pdoResolver fábrica do PDO (seam de teste; padrão Connection::open)
     * @param ?string           $database    chave de config['db'] (padrão: `settings.monitor.database`)
     * @param ?DialectInterface $dialect dialeto fixo (padrão: o do banco em config['db'])
     */
    public function __construct(?\Closure $pdoResolver = null, ?string $database = null, ?DialectInterface $dialect = null)
    {
        $this->database    = $database ?? Config::database();
        $this->pdoResolver = $pdoResolver;
        $this->dialect     = $dialect;
    }

    /** Linhas por INSERT: respeita o teto de ~2100 parâmetros e 1000 linhas (SQL Server), com folga. */
    public static function rowsPerStatement(int $columns): int
    {
        return max(1, min(1000, intdiv(2000, max(1, $columns))));
    }

    /**
     * Grava o lote (`requests`, `queries`, `exceptions`, `external`) numa única transação;
     * qualquer falha desfaz tudo e propaga.
     *
     * @param array<string, list<array<string, mixed>>> $batch
     * @throws \InvalidArgumentException chave de lote desconhecida
     * @throws \PDOException             falha na gravação (tudo é desfeito)
     */
    public function write(array $batch): void
    {
        foreach (array_keys($batch) as $key) {
            if (!isset(self::TABLES[$key])) {
                throw new \InvalidArgumentException("Chave de lote desconhecida: '{$key}'.");
            }
        }

        $pdo     = $this->pdo();
        $dialect = $this->dialect();

        $pdo->beginTransaction();
        try {
            foreach ($batch as $key => $rows) {
                $this->insertRows($pdo, $dialect, self::TABLES[$key], array_values($rows));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Indica se as tabelas do monitor existem no banco (usado pelo auto_migrate).
     *
     * @throws \PDOException falha ao consultar o banco
     */
    public function schemaInstalled(): bool
    {
        $tables = $this->pdo()
            ->query($this->dialect()->listTablesQuery())
            ->fetchAll(\PDO::FETCH_COLUMN);

        $tables = array_map('strtolower', array_map('strval', $tables));
        foreach (Installer::TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(\PDO $pdo, DialectInterface $dialect, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Colunas são a união das chaves do chunk (ausentes viram NULL); coluna inválida falha no banco.
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $columns[$column] = true;
            }
        }
        $columns = array_map('strval', array_keys($columns));

        $quotedColumns = implode(', ', array_map([$dialect, 'quoteIdentifier'], $columns));
        $group         = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        foreach (array_chunk($rows, self::rowsPerStatement(count($columns))) as $chunk) {
            $sql = 'INSERT INTO ' . $dialect->quoteIdentifier($table) . ' (' . $quotedColumns . ') VALUES '
                . implode(', ', array_fill(0, count($chunk), $group));

            $values = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    // Coluna datetime `ts`: forma ISO só no SQL Server (ver SqlDate).
                    if ($column === 'ts' && is_string($value)) {
                        $value = SqlDate::bind($value, $dialect);
                    }
                    $values[] = $value;
                }
            }
            $pdo->prepare($sql)->execute($values);
        }
    }

    private function pdo(): \PDO
    {
        return $this->pdo ??= $this->pdoResolver !== null
            ? ($this->pdoResolver)()
            : Connection::open($this->database);
    }

    private function dialect(): DialectInterface
    {
        return $this->dialect ?? DialectFactory::fromConfig(\PSF::config('db')[$this->database]);
    }
}
