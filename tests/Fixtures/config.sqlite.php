<?php

use Psf\Enumerators\DBDriver;

/*
| Config dos testes de integração: SQLite em memória (sem servidor de banco).
| Cada teste recebe um banco novo — ver tests/Pest.php.
*/

return [
    'db' => [
        'default' => [
            'driver'   => DBDriver::SQLite,
            'database' => ':memory:',
        ],
    ],
    'jwt' => [
        'secret' => 'psf-test-secret-key-do-not-use-in-production',
    ],
];
