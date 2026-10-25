<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectInterface;
use Psf\Database\Dialect\SQLServerDialect;

/**
 * Watermark do rollup (UTC, `Y-m-d H:i:s`) guardado em `psf_monitor_state`.
 *
 * A escrita é portável (sem upsert de fornecedor): UPDATE e, se a chave não existir, INSERT.
 */
final class Watermark
{
    /** Chave (`name`) do watermark em `psf_monitor_state`. */
    public const NAME = 'rollup_watermark';
    private const TABLE = 'psf_monitor_state';
    private const FORMAT = 'Y-m-d H:i:s';
    private const SAVEPOINT = 'psf_monitor_watermark';

    /**
     * @param \PDO             $pdo     conexão do banco do monitor
     * @param DialectInterface $dialect dialeto desse banco (quoting)
     */
    public function __construct(
        private \PDO $pdo,
        private DialectInterface $dialect,
    ) {
    }

    /**
     * Valor validado por round-trip, ou null se a chave não existir, o valor for
     * inválido ou a tabela de estado não existir.
     *
     * @throws \PDOException qualquer outra falha de leitura (conexão, permissão...):
     *                       quem chama para em vez de adivinhar o ponto de partida
     */
    public function read(): ?string
    {
        try {
            $value = $this->fetchValue();
        } catch (\PDOException $e) {
            if (self::isMissingTable($e)) {
                return null;
            }
            throw $e;
        }

        if (!is_string($value)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new \DateTimeZone('UTC'));
        return ($parsed !== false && $parsed->format(self::FORMAT) === $value) ? $value : null;
    }

    /**
     * Insere ou atualiza a chave. Participa da transação aberta, se houver;
     * senão abre a própria.
     *
     * @param string $value `Y-m-d H:i:s` em UTC (fim da última hora agregada)
     * @throws \PDOException falha de banco
     */
    public function write(string $value): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            if (!$this->update($value) && $this->fetchValue() === false) {
                // Na transação de quem chamou, o INSERT vai num savepoint: no PostgreSQL um
                // erro aborta a transação inteira (25P02) e o UPDATE abaixo falharia. No SQL
                // Server (outra sintaxe de savepoint) assume-se que o erro não aborta a transação:
                // suposição não validada em banco real, e só vale sem XACT_ABORT ON.
                $savepoint = !$own && !$this->dialect instanceof SQLServerDialect;
                if ($savepoint) {
                    $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
                }
                try {
                    $this->insert($value);
                    if ($savepoint) {
                        $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
                    }
                } catch (\PDOException $e) {
                    if ($savepoint) {
                        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                    }
                    // Corrida: outra escrita inseriu a chave entre o SELECT e o INSERT.
                    if ($own || !$this->update($value)) {
                        throw $e;
                    }
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tabela inexistente, de forma portável: SQLSTATE `42S02` (MySQL/SQL Server),
     * `42P01` (PostgreSQL) ou a mensagem "no such table" do SQLite (que usa HY000).
     */
    private static function isMissingTable(\PDOException $e): bool
    {
        $state = $e->errorInfo[0] ?? $e->getCode();
        if (in_array((string) $state, ['42S02', '42P01'], true)
            || in_array((string) $e->getCode(), ['42S02', '42P01'], true)) {
            return true;
        }
        return preg_match('/no such table|SQLSTATE\[(42S02|42P01)\]/i', $e->getMessage()) === 1;
    }

    private function fetchValue(): mixed
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->dialect->quoteIdentifier('value')
            . ' FROM ' . $this->dialect->quoteIdentifier(self::TABLE)
            . ' WHERE ' . $this->dialect->quoteIdentifier('name') . ' = :n'
        );
        $stmt->execute([':n' => self::NAME]);
        return $stmt->fetchColumn();
    }

    private function update(string $value): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->dialect->quoteIdentifier(self::TABLE)
            . ' SET ' . $this->dialect->quoteIdentifier('value') . ' = :v'
            . ' WHERE ' . $this->dialect->quoteIdentifier('name') . ' = :n'
        );
        $stmt->execute([':v' => $value, ':n' => self::NAME]);
        return $stmt->rowCount() > 0;
    }

    private function insert(string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->dialect->quoteIdentifier(self::TABLE)
            . ' (' . $this->dialect->quoteIdentifier('name') . ', ' . $this->dialect->quoteIdentifier('value') . ')'
            . ' VALUES (:n, :v)'
        );
        $stmt->execute([':n' => self::NAME, ':v' => $value]);
    }
}
