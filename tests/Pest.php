<?php

/*
|--------------------------------------------------------------------------
| Pest Configuration
|--------------------------------------------------------------------------
|
| Este arquivo é carregado automaticamente pelo Pest antes de qualquer teste.
| Use-o para registrar helpers globais, datasets reutilizáveis e configurações
| que se aplicam a todas as suites.
|
*/

use Psf\Database\Connect;
use Psf\Model\MetadataCache;

uses()->in('Unit', 'Integration');

/*
| Integração: cada teste roda contra um SQLite :memory: novo, com as tabelas
| fake_users e fake_notes já criadas. Ao final, a config e a conexão voltam ao
| estado dos testes unitários.
*/
uses()
    ->beforeEach(function () {
        PSF::init(['config' => __DIR__ . '/Fixtures/config.sqlite.php']);
        Connect::$connect = null;
        Connect::$tables  = null;
        Connect::clearColumnsCache();
        MetadataCache::clearCache();

        // explodeException() registra o erro; evita poluir a saída dos testes
        ini_set('error_log', sys_get_temp_dir() . '/psf-integration.log');

        $pdo = Connect::getConnection('default');
        $pdo->exec('CREATE TABLE fake_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            created_at TEXT NULL
        )');
        $pdo->exec('CREATE TABLE fake_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            price REAL NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');
        $pdo->exec('CREATE TABLE fake_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NULL,
            deleted_at TEXT NULL
        )');

        // Relê a lista de tabelas (Connect::$tables) com o schema criado
        Connect::listTables('default', true);
    })
    ->afterEach(function () {
        Connect::$connect = null;
        Connect::$tables  = null;
        Connect::clearColumnsCache();
        MetadataCache::clearCache();
        PSF::init(['config' => __DIR__ . '/Fixtures/config.php']);
    })
    ->in('Integration');

/*
| Monitor: helpers de config temporária e limpeza após cada teste.
*/
require_once __DIR__ . '/Fixtures/monitor_helpers.php';

uses()->afterEach(fn () => monitorCleanup())->in('Unit/Monitor', 'Integration/Monitor');

/*
| Drivers: o monitor contra MySQL e PostgreSQL reais (docker-compose.drivers.yml).
| Só roda com PSF_DRIVERS=1; sem isso cada teste é pulado com o motivo. Cada
| teste cria e apaga o próprio banco (driverUse/driverCleanup).
*/
require_once __DIR__ . '/Fixtures/driver_helpers.php';

dataset('drivers', ['mysql', 'pgsql']);

uses()
    ->beforeEach(function () {
        if (!driversEnabled()) {
            $this->markTestSkipped(DRIVERS_SKIP_REASON);
        }
        // Erros esperados (explodeException, breaker) não poluem a saída.
        ini_set('error_log', sys_get_temp_dir() . '/psf-drivers.log');
    })
    ->afterEach(fn () => driverCleanup())
    ->in('Drivers');
