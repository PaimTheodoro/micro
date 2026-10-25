<?php

/*
|--------------------------------------------------------------------------
| Helpers de teste do Monitor
|--------------------------------------------------------------------------
|
| As classes do monitor só são referenciadas dentro dos corpos das funções,
| protegidas por class_exists(), pois surgem em tarefas posteriores.
|
*/

use Psf\Enumerators\DBDriver;

/**
 * Lista de arquivos temporários criados pelos helpers (removidos em monitorCleanup).
 *
 * @return array<int, string>
 */
function &monitorTempFiles(): array
{
    static $files = [];
    return $files;
}

/**
 * Grava um config temporário (SQLite em arquivo único + settings.monitor),
 * inicializa o PSF com ele e devolve o caminho do arquivo SQLite.
 *
 * @param array $monitor Conteúdo de settings.monitor
 * @param array $top     Mesclado ao config com array_replace_recursive
 */
function monitorUseConfig(array $monitor = [], array $top = []): string
{
    $files = &monitorTempFiles();

    $sqlite = sys_get_temp_dir() . '/psf-monitor-' . uniqid('', true) . '.sqlite';
    $cfg    = sys_get_temp_dir() . '/psf-monitor-cfg-' . uniqid('', true) . '.php';
    $files[] = $sqlite;
    $files[] = $cfg;

    $config = [
        'db' => [
            'default' => [
                'driver'   => DBDriver::SQLite,
                'database' => $sqlite,
            ],
        ],
        'jwt'      => ['secret' => 'psf-test-secret-key-do-not-use-in-production'],
        'settings' => ['monitor' => $monitor],
    ];
    $config = array_replace_recursive($config, $top);

    file_put_contents($cfg, '<?php use Psf\Enumerators\DBDriver; return ' . var_export($config, true) . ';');

    PSF::init(['config' => $cfg]);

    if (class_exists(\Psf\Monitor\Config::class)) {
        \Psf\Monitor\Config::reset();
    }

    return $sqlite;
}

/** Remove temporários e restaura o PSF com o config padrão de testes. */
function monitorCleanup(): void
{
    PSF::init(['config' => dirname(__DIR__) . '/Fixtures/config.php']);

    if (class_exists(\Psf\Monitor\Config::class)) {
        \Psf\Monitor\Config::reset();
    }
    if (class_exists(\Psf\Monitor\Monitor::class)) {
        \Psf\Monitor\Monitor::reset();
    }

    $files = &monitorTempFiles();
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    $files = [];
}

/**
 * PDO SQLite (no arquivo do banco `default`) que lança uma PDOException genérica
 * (não "tabela ausente") ao preparar qualquer SQL que contenha `$needle`.
 */
function monitorFailingPdo(string $needle, string $message = 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', string $code = 'HY000'): PDO
{
    $pdo = new class ('sqlite:' . \PSF::config('db', 'default', 'database')) extends PDO {
        public string $needle = '';
        public string $failMessage = '';
        public string $failCode = '';

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if ($this->needle !== '' && str_contains($query, $this->needle)) {
                throw monitorPdoException($this->failMessage, $this->failCode);
            }
            return parent::prepare($query, $options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->needle      = $needle;
    $pdo->failMessage = $message;
    $pdo->failCode    = $code;
    return $pdo;
}

/** PDOException como a do PDO real: o código é a SQLSTATE (string). */
function monitorPdoException(string $message, string $sqlState): PDOException
{
    return new class ($message, $sqlState) extends PDOException {
        public function __construct(string $message, string $sqlState)
        {
            parent::__construct($message);
            $this->code      = $sqlState;
            $this->errorInfo = [$sqlState, 0, $message];
        }
    };
}
