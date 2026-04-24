<?php

use Psf\Enumerators\DBDriver;

/*
|--------------------------------------------------------------------------
| Configuração de Teste do PSF Framework
|--------------------------------------------------------------------------
|
| Usada exclusivamente nos testes. A conexão de banco aponta para um MySQL
| local (usada em testes de integração). Testes unitários mocam o PDO e
| nunca chamam Connect::getConnection(), portanto os valores de DB aqui
| são apenas placeholders.
|
*/

return [
    'db' => [
        'default' => [
            'driver'   => DBDriver::MySQL,
            'hostname' => '127.0.0.1',
            'username' => 'root',
            'password' => '',
            'database' => 'psf_test',
            'port'     => 3306,
        ],
    ],
    'jwt' => [
        'secret' => 'psf-test-secret-key-do-not-use-in-production',
    ],
];
