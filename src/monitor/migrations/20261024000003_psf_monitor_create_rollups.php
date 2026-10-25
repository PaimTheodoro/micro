<?php

use Phinx\Migration\AbstractMigration;

/**
 * Monitor: agregados por hora. Criada já na fase 1 para o esquema nascer completo.
 */
final class PsfMonitorCreateRollups extends AbstractMigration
{
    /** Cria `psf_monitor_rollups` (esquema do contrato em docs/observabilidade.md). */
    public function change(): void
    {
        // Phinx 0.16 cria colunas NULL por padrão: NOT NULL vem explícito ('null' => false).
        $this->table('psf_monitor_rollups', [
            'id'          => false,
            'primary_key' => ['bucket_hour', 'metric', 'metric_key'],
        ])
            ->addColumn('bucket_hour', 'datetime', ['null' => false])
            ->addColumn('metric', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('metric_key', 'string', ['null' => false, 'limit' => 191])
            ->addColumn('hits', 'decimal', ['null' => false, 'precision' => 14, 'scale' => 4])
            ->addColumn('errors', 'decimal', ['null' => false, 'precision' => 14, 'scale' => 4])
            ->addColumn('sum_ms', 'decimal', ['null' => false, 'precision' => 18, 'scale' => 2])
            ->addColumn('max_ms', 'decimal', ['null' => false, 'precision' => 10, 'scale' => 2])
            ->addColumn('histogram', 'text', ['null' => false])
            ->addColumn('sample_text', 'text', ['null' => true])
            ->addColumn('last_ts', 'datetime', ['null' => true])
            ->create();
    }
}
