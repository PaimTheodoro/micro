<?php

use Phinx\Migration\AbstractMigration;

/**
 * Monitor: chamadas externas (HTTP, e-mail, storage) amostradas.
 * O `target` é só o rótulo seguro (host ou provedor/operação), nunca a URL completa.
 * Usa só a abstração do Phinx para ser portável entre MySQL/PostgreSQL/SQL Server/SQLite.
 */
final class PsfMonitorCreateExternal extends AbstractMigration
{
    /** Cria `psf_monitor_external` (esquema do contrato em docs/observabilidade.md). */
    public function change(): void
    {
        // SQLite só aceita AUTOINCREMENT em "INTEGER PRIMARY KEY"; nos demais o id é BIGINT identity.
        $idType = $this->getAdapter()->getAdapterType() === 'sqlite' ? 'integer' : 'biginteger';

        // Phinx 0.16 cria colunas NULL por padrão: NOT NULL vem explícito ('null' => false).
        $this->table('psf_monitor_external', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', $idType, ['null' => false, 'identity' => true])
            ->addColumn('request_id', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('kind', 'string', ['null' => false, 'limit' => 16])
            ->addColumn('target', 'string', ['null' => false, 'limit' => 191])
            ->addColumn('duration_ms', 'decimal', ['null' => false, 'precision' => 10, 'scale' => 2])
            ->addColumn('status', 'integer', ['null' => true])
            ->addColumn('sample_weight', 'decimal', ['null' => false, 'precision' => 12, 'scale' => 4])
            ->addIndex(['request_id'], ['name' => 'idx_psf_monitor_external_request_id'])
            ->addIndex(['ts'], ['name' => 'idx_psf_monitor_external_ts'])
            ->addIndex(['kind', 'target', 'ts'], ['name' => 'idx_psf_monitor_external_kind_target_ts'])
            ->create();
    }
}
