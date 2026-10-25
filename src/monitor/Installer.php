<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectFactory;
use Psf\Database\PhinxEnvironments;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Instala as tabelas do monitor rodando o Manager do Phinx apenas sobre
 * `src/monitor/migrations`, com tabela de controle própria
 * (`psf_monitor_migrations`) — a `phinxlog` do projeto não é tocada.
 *
 * Não é `final`: os testes estendem a classe.
 */
class Installer
{
    /** Tabela de controle de migrations do monitor. */
    public const MIGRATION_TABLE = 'psf_monitor_migrations';

    /** Nome do ambiente Phinx usado internamente. */
    public const ENVIRONMENT = 'monitor';

    /** Tabelas que precisam existir para o monitor estar instalado. */
    public const TABLES = [
        'psf_monitor_requests',
        'psf_monitor_state',
        'psf_monitor_rollups',
        'psf_monitor_queries',
        'psf_monitor_exceptions',
        'psf_monitor_external',
    ];

    protected string $database;

    /** @param string|null $database Chave de config['db']; null = `Config::database()`. */
    public function __construct(?string $database = null)
    {
        $this->database = $database ?? Config::database();
    }

    /** Chave de config['db'] usada pelo instalador. */
    public function database(): string
    {
        return $this->database;
    }

    /** Caminho absoluto do diretório de migrations do monitor. */
    public static function migrationsPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * Executa as migrations pendentes (idempotente).
     *
     * @throws \RuntimeException chave de banco inexistente ou Phinx não instalado
     */
    public function install(): void
    {
        $dbConfig = $this->dbConfig();

        if (!class_exists(\Phinx\Migration\Manager::class)) {
            throw new \RuntimeException('Instalação do monitor requer robmorgan/phinx.');
        }

        $environments = PhinxEnvironments::fromDbConfigs([self::ENVIRONMENT => $dbConfig]);
        if (!isset($environments[self::ENVIRONMENT])) {
            throw new \RuntimeException("Configuração de banco '{$this->database}' incompleta para o Phinx.");
        }

        $config = new \Phinx\Config\Config([
            'paths' => [
                'migrations' => [self::migrationsPath()],
            ],
            'environments' => [
                'default_migration_table' => self::MIGRATION_TABLE,
                'default_environment'     => self::ENVIRONMENT,
                self::ENVIRONMENT         => $environments[self::ENVIRONMENT],
            ],
        ]);

        $manager = new \Phinx\Migration\Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate(self::ENVIRONMENT);
    }

    /** Indica se as tabelas do monitor existem no banco configurado. */
    public function isInstalled(): bool
    {
        $dbConfig = $this->dbConfig();
        $dialect  = DialectFactory::fromConfig($dbConfig);

        $tables = Connection::open($this->database)
            ->query($dialect->listTablesQuery())
            ->fetchAll(\PDO::FETCH_COLUMN);

        $tables = array_map('strtolower', array_map('strval', $tables));
        foreach (self::TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Entrada de config['db'] do banco do monitor.
     *
     * @throws \RuntimeException quando a chave não existe
     */
    private function dbConfig(): array
    {
        $configs = \PSF::config('db');
        if (!is_array($configs) || !isset($configs[$this->database]) || !is_array($configs[$this->database])) {
            throw new \RuntimeException("Banco '{$this->database}' não encontrado em config['db'].");
        }
        return $configs[$this->database];
    }
}
