<?php

use Phinx\Migration\AbstractMigration;

/**
 * Monitor: tabela de requests brutos (um registro por request amostrado).
 * Usa só a abstração do Phinx para ser portável entre MySQL/PostgreSQL/SQL Server/SQLite.
 */
final class PsfMonitorCreateRequests extends AbstractMigration
{
    /** Cria `psf_monitor_requests` (esquema do contrato em docs/observabilidade.md). */
    public function change(): void
    {
        // SQLite só aceita AUTOINCREMENT em "INTEGER PRIMARY KEY" (que já é 64 bits);
        // nos demais drivers o id é BIGINT identity.
        $idType = $this->getAdapter()->getAdapterType() === 'sqlite' ? 'integer' : 'biginteger';

        // Phinx 0.16 cria colunas NULL por padrão: NOT NULL vem explícito ('null' => false).
        $this->table('psf_monitor_requests', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', $idType, ['null' => false, 'identity' => true])
            ->addColumn('request_id', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('method', 'string', ['null' => false, 'limit' => 10])
            ->addColumn('route', 'string', ['null' => false, 'limit' => 191])
            ->addColumn('version', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('status', 'integer', ['null' => false])
            ->addColumn('duration_ms', 'decimal', ['null' => false, 'precision' => 10, 'scale' => 2])
            ->addColumn('memory_kb', 'integer', ['null' => false])
            ->addColumn('user_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('env', 'string', ['null' => false, 'limit' => 20])
            ->addColumn('app_release', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('dropped_events', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('sample_weight', 'decimal', ['null' => false, 'precision' => 12, 'scale' => 4])
            ->addIndex(['ts'], ['name' => 'idx_psf_monitor_requests_ts'])
            ->addIndex(['route', 'ts'], ['name' => 'idx_psf_monitor_requests_route_ts'])
            ->addIndex(['request_id'], ['name' => 'idx_psf_monitor_requests_request_id'])
            ->create();
    }
}
