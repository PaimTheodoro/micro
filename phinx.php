<?php

// phinx.php - Arquivo de configuração para o Phinx.
// Este arquivo é carregado pelo nosso wrapper `bin/phinx`, que já inicializou o framework.

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

    if (empty($driver) || empty($host) || empty($user) || !isset($pass) || empty($database)) {
        continue;
    }

    $environments[$envName] = [
        'adapter'      => $driverToAdapter($driver),
        'host'         => $host,
        'name'         => $database,
        'user'         => $user,
        'pass'         => $pass,
        'port'         => $config['port'] ?? 3306,
        'charset'      => $config['charset'] ?? 'utf8',
        'collation'    => $config['collation'] ?? 'utf8_unicode_ci',
    ];
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
    'commands' => [
        'Psf\\Database\\Command\\ModelAwareMigration',
        'Psf\\Database\\Command\\ModelGenerator',
        'Psf\\Database\\Command\\TableAnalyzer',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $defaultEnvironment,
    ] + $environments
]; 
