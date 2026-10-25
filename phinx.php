<?php

// phinx.php - Arquivo de configuração para o Phinx.
// Este arquivo é carregado pelo nosso wrapper `bin/phinx`, que já inicializou o framework
// e registra os comandos do PSF (make:migration-from-model, make:model-from-table, analyze:table-vs-model).

// Recupera as configurações de banco de dados do framework
$dbConfigs = \PSF::config('db');
if (empty($dbConfigs) || !is_array($dbConfigs)) {
    throw new \RuntimeException('Configurações de banco de dados não encontradas ou em formato inválido. O framework foi inicializado corretamente?');
}

// Traduz config['db'] em ambientes do Phinx (lógica compartilhada com o monitor).
$environments = \Psf\Database\PhinxEnvironments::fromDbConfigs($dbConfigs);

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
