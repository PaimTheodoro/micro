<?php

use Phinx\Migration\AbstractMigration;

/**
 * Monitor: exceções amostradas (classe, mensagem, local e stack trace).
 * Usa só a abstração do Phinx para ser portável entre MySQL/PostgreSQL/SQL Server/SQLite.
 */
final class PsfMonitorCreateExceptions extends AbstractMigration
{
    /** Cria `psf_monitor_exceptions` (esquema do contrato em docs/observabilidade.md). */
    public function change(): void
    {
        // SQLite só aceita AUTOINCREMENT em "INTEGER PRIMARY KEY"; nos demais o id é BIGINT identity.
        $idType = $this->getAdapter()->getAdapterType() === 'sqlite' ? 'integer' : 'biginteger';

        // Phinx 0.16 cria colunas NULL por padrão: NOT NULL vem explícito ('null' => false).
        $this->table('psf_monitor_exceptions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', $idType, ['null' => false, 'identity' => true])
            ->addColumn('request_id', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('exception_class', 'string', ['null' => false, 'limit' => 191])
            ->addColumn('message', 'string', ['null' => false, 'limit' => 500])
            ->addColumn('file', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('line_no', 'integer', ['null' => false])
            ->addColumn('trace_hash', 'string', ['null' => false, 'limit' => 40])
            ->addColumn('trace', 'text', ['null' => false])
            ->addColumn('sample_weight', 'decimal', ['null' => false, 'precision' => 12, 'scale' => 4])
            ->addIndex(['ts'], ['name' => 'idx_psf_monitor_exceptions_ts'])
            ->addIndex(['trace_hash', 'ts'], ['name' => 'idx_psf_monitor_exceptions_hash_ts'])
            ->addIndex(['request_id'], ['name' => 'idx_psf_monitor_exceptions_request_id'])
            ->create();
    }
}
