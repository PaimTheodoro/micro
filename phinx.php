<?php

// phinx.php - Arquivo de configuração para o Phinx.
// Este arquivo é carregado pelo nosso wrapper `bin/phinx`, que já inicializou o framework.

// Recupera as configurações de banco de dados do framework
$dbConfigs = \PSF::config('db');
if (empty($dbConfigs) || !is_array($dbConfigs)) {
    throw new \RuntimeException('Configurações de banco de dados não encontradas ou em formato inválido. O framework foi inicializado corretamente?');
}

// Mapeia os drivers do seu framework para os do Phinx
$driverMap = [
    \Psf\Enumerators\DBDriver::MySQL => 'mysql',
    \Psf\Enumerators\DBDriver::SQLServer => 'sqlsrv',
    \Psf\Enumerators\DBDriver::PostgreSQL => 'pgsql',
    \Psf\Enumerators\DBDriver::SQLite => 'sqlite',
];

$environments = [];
foreach ($dbConfigs as $envName => $config) {
    // Valida se a configuração tem os campos mínimos para o Phinx
    if (!isset($config['driver'], $config['host'], $config['database'], $config['user'], $config['pass'])) {
        continue;
    }

    $environments[$envName] = [
        'adapter'      => $driverMap[$config['driver']] ?? 'mysql',
        'host'         => $config['host'],
        'name'         => $config['database'],
        'user'         => $config['user'],
        'pass'         => $config['pass'],
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

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds'
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