<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectInterface;

/**
 * Apaga dados brutos antigos do monitor, em lotes e de forma portável.
 *
 * Só usa `topClause`/`limitOffsetClause` do dialeto (sem `DELETE ... LIMIT`,
 * que não existe em todos os drivers).
 */
final class Pruner
{
    /** Tabelas brutas com colunas `id` e `ts` (também usadas pelo Rollup). */
    public const RAW_TABLES = ['psf_monitor_requests', 'psf_monitor_queries', 'psf_monitor_exceptions', 'psf_monitor_external'];

    /** Teto: linhas brutas nunca passam de 4 x raw_days, mesmo com o rollup parado. */
    public const HARD_CEILING_FACTOR = 4;

    /** Teto de raw_days (evita estouro em `raw_days * 4`). */
    public const MAX_RAW_DAYS = 36500;

    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * @param \PDO             $pdo         conexão do banco do monitor
     * @param DialectInterface $dialect     dialeto desse banco (TOP/LIMIT, quoting, datas)
     * @param int              $batchSize   linhas por lote (mínimo 1)
     * @param int              $pauseMicros pausa entre lotes (µs), para não saturar o banco
     */
    public function __construct(
        private \PDO $pdo,
        private DialectInterface $dialect,
        private int $batchSize = 5000,
        private int $pauseMicros = 10000,
    ) {
        $this->batchSize = max(1, $batchSize);
    }

    /**
     * Apaga linhas brutas com `ts < now - rawDays`, nunca além do watermark do rollup,
     * mas sempre abaixo do teto `now - 4 x rawDays` (rollup parado não pode crescer sem limite).
     * Corte efetivo = max(min(now - rawDays, watermark), now - 4 x rawDays).
     * Erro ao ler o watermark (que não seja tabela ausente) propaga sem apagar nada.
     *
     * @return int total de linhas apagadas
     * @throws \InvalidArgumentException `$rawDays` fora de 1..MAX_RAW_DAYS
     */
    public function pruneRaw(\DateTimeImmutable $now, int $rawDays): int
    {
        if ($rawDays < 1) {
            throw new \InvalidArgumentException('raw_days inválido: informe um inteiro >= 1.');
        }
        if ($rawDays > self::MAX_RAW_DAYS) {
            throw new \InvalidArgumentException('raw_days inválido: informe um inteiro entre 1 e ' . self::MAX_RAW_DAYS . '.');
        }

        $utc    = new \DateTimeZone('UTC');
        $cutoff = $now->setTimezone($utc)->modify('-' . $rawDays . ' days')->format(self::FORMAT);

        $watermark = (new Watermark($this->pdo, $this->dialect))->read();
        if ($watermark !== null && $watermark < $cutoff) {
            $cutoff = $watermark;
        }

        $ceiling = $now->setTimezone($utc)
            ->modify('-' . ($rawDays * self::HARD_CEILING_FACTOR) . ' days')->format(self::FORMAT);
        if ($cutoff < $ceiling) {
            $cutoff = $ceiling;
        }

        $total = 0;
        foreach (self::RAW_TABLES as $table) {
            $total += $this->pruneTable($table, $cutoff);
        }
        return $total;
    }

    /**
     * Apaga agregados horários com `bucket_hour < now - aggregateDays` (a tabela é pequena).
     *
     * @return int linhas apagadas
     * @throws \InvalidArgumentException `$aggregateDays` < 1
     */
    public function pruneAggregates(\DateTimeImmutable $now, int $aggregateDays): int
    {
        if ($aggregateDays < 1) {
            throw new \InvalidArgumentException('aggregate_days inválido: informe um inteiro >= 1.');
        }

        $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-' . $aggregateDays . ' days')->format(self::FORMAT);

        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->dialect->quoteIdentifier('psf_monitor_rollups')
            . ' WHERE ' . $this->dialect->quoteIdentifier('bucket_hour') . ' < :cutoff'
        );
        $stmt->execute([':cutoff' => SqlDate::bind($cutoff, $this->dialect)]);
        return $stmt->rowCount();
    }

    private function pruneTable(string $table, string $cutoff): int
    {
        $t = $this->dialect->quoteIdentifier($table);
        $id = $this->dialect->quoteIdentifier('id');
        $ts = $this->dialect->quoteIdentifier('ts');

        $select = $this->pdo->prepare(
            'SELECT ' . $this->dialect->topClause($this->batchSize, null) . $id
            . " FROM {$t} WHERE {$ts} < :cutoff ORDER BY {$id}"
            . $this->dialect->limitOffsetClause($this->batchSize, null)
        );
        $delete = $this->pdo->prepare("DELETE FROM {$t} WHERE {$ts} < :cutoff AND {$id} <= :lastId");

        $cutoff = SqlDate::bind($cutoff, $this->dialect);
        $total  = 0;
        while (true) {
            $select->execute([':cutoff' => $cutoff]);
            $ids = $select->fetchAll(\PDO::FETCH_COLUMN);
            $select->closeCursor();
            if ($ids === []) {
                break;
            }

            $delete->execute([':cutoff' => $cutoff, ':lastId' => end($ids)]);
            $total += $delete->rowCount();

            if ($this->pauseMicros > 0) {
                usleep($this->pauseMicros);
            }
        }
        return $total;
    }
}
