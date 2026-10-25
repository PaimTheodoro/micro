<?php

use Phinx\Migration\AbstractMigration;

/**
 * Monitor: estado chave/valor (ex.: watermark do rollup).
 */
final class PsfMonitorCreateState extends AbstractMigration
{
    /** Cria `psf_monitor_state` (esquema do contrato em docs/observabilidade.md). */
    public function change(): void
    {
        // Phinx 0.16 cria colunas NULL por padrão: NOT NULL vem explícito ('null' => false).
        $this->table('psf_monitor_state', ['id' => false, 'primary_key' => ['name']])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('value', 'string', ['null' => false, 'limit' => 255])
            ->create();
    }
}
