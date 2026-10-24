<?php

// phinx.php - Arquivo de configuração para o Phinx.
// Este arquivo é carregado pelo nosso wrapper `bin/phinx`, que já inicializou o framework
// e registra os comandos do PSF (make:migration-from-model, make:model-from-table, analyze:table-vs-model).

// Recupera as configurações de banco de dados do framework
$dbConfigs = \PSF::config('db');
if (empty($dbConfigs) || !is_array($dbConfigs)) {
    throw new \RuntimeException('Configurações de banco de dados não encontradas ou em formato inválido. O framework foi inicializado corretamente?');
}

// Mapeia os drivers do framework para os adapters do Phinx.
// Configs legadas podem passar o valor inteiro do enum (ex: 1) em vez da instância.
$driverToAdapter = static function ($driver): string {
    if (!$driver instanceof \Psf\Enumerators\DBDriver) {
        // DBDriver é um backed enum int — DBDriver::from() reusa o mapeamento canônico
        $driver = \Psf\Enumerators\DBDriver::tryFrom((int) $driver)
            ?? \Psf\Enumerators\DBDriver::MySQL;
    }

    return match ($driver) {
        \Psf\Enumerators\DBDriver::MySQL      => 'mysql',
        \Psf\Enumerators\DBDriver::SQLServer  => 'sqlsrv',
        \Psf\Enumerators\DBDriver::PostgreSQL => 'pgsql',
        \Psf\Enumerators\DBDriver::SQLite     => 'sqlite',
    };
};

$environments = [];
foreach ($dbConfigs as $envName => $config) {
    // Suporta os dois formatos: host/user/pass e hostname/username/password
    $host = $config['host'] ?? $config['hostname'] ?? null;
    $user = $config['user'] ?? $config['username'] ?? null;
    $pass = $config['pass'] ?? $config['password'] ?? null;
    $driver = $config['driver'] ?? null;
    $database = $config['database'] ?? null;

    if (empty($driver) || empty($database)) {
        continue;
    }

    $adapter = $driverToAdapter($driver);

    // SQLite é um arquivo (ou :memory:) — não tem host nem credenciais
    if ($adapter === 'sqlite') {
        $environments[$envName] = [
            'adapter' => 'sqlite',
            'name'    => $database,
            'suffix'  => $config['suffix'] ?? '',
        ];
        continue;
    }

    if (empty($host) || empty($user) || !isset($pass)) {
        continue;
    }

    // Porta e charset padrão dependem do driver (antes: 3306/utf8 para todos)
    $defaults = match ($adapter) {
        'pgsql'  => ['port' => 5432, 'charset' => 'utf8',    'collation' => null],
        'sqlsrv' => ['port' => 1433, 'charset' => 'utf8',    'collation' => null],
        default  => ['port' => 3306, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
    };

    $environments[$envName] = [
        'adapter'      => $adapter,
        'host'         => $host,
        'name'         => $database,
        'user'         => $user,
        'pass'         => $pass,
        'port'         => $config['port'] ?? $defaults['port'],
        'charset'      => $config['charset'] ?? $defaults['charset'],
    ] + (($config['collation'] ?? $defaults['collation']) !== null
        ? ['collation' => $config['collation'] ?? $defaults['collation']]
        : []);
}

if (empty($environments)) {
     throw new \RuntimeException('Nenhuma configuração de ambiente de banco de dados válida foi encontrada.');
}

// O ambiente padrão será o primeiro que você definiu nas suas configs
$defaultEnvironment = array_key_first($environments);
$projectRoot = defined('ROOT') ? ROOT : __DIR__;

return [
    'paths' => [
        // Salva migrations/seeds no projeto consumidor, não dentro do vendor do framework.
        'migrations' => $projectRoot . '/db/migrations',
        'seeds' => $projectRoot . '/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $defaultEnvironment,
    ] + $environments
]; 
