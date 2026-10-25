<?php

use Phinx\Migration\AbstractMigration;

/**
 * Monitor: queries SQL amostradas (texto normalizado, sem literais).
 * Usa só a abstração do Phinx para ser portável entre MySQL/PostgreSQL/SQL Server/SQLite.
 */
final class PsfMonitorCreateQueries extends AbstractMigration
{
    /** Cria `psf_monitor_queries` (esquema do contrato em docs/observabilidade.md). */
    public function change(): void
    {
        // SQLite só aceita AUTOINCREMENT em "INTEGER PRIMARY KEY"; nos demais o id é BIGINT identity.
        $idType = $this->getAdapter()->getAdapterType() === 'sqlite' ? 'integer' : 'biginteger';

        // Phinx 0.16 cria colunas NULL por padrão: NOT NULL vem explícito ('null' => false).
        $this->table('psf_monitor_queries', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', $idType, ['null' => false, 'identity' => true])
            ->addColumn('request_id', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('sql_hash', 'string', ['null' => false, 'limit' => 40])
            ->addColumn('sql_text', 'text', ['null' => false])
            ->addColumn('duration_ms', 'decimal', ['null' => false, 'precision' => 10, 'scale' => 2])
            ->addColumn('connection_name', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('sample_weight', 'decimal', ['null' => false, 'precision' => 12, 'scale' => 4])
            ->addIndex(['request_id'], ['name' => 'idx_psf_monitor_queries_request_id'])
            ->addIndex(['sql_hash', 'ts'], ['name' => 'idx_psf_monitor_queries_hash_ts'])
            ->addIndex(['ts'], ['name' => 'idx_psf_monitor_queries_ts'])
            ->create();
    }
}
