<?php

/*
|--------------------------------------------------------------------------
| Bootstrap de Testes do PSF Framework
|--------------------------------------------------------------------------
|
| Este arquivo é carregado pelo PHPUnit/Pest antes de qualquer teste.
| Inicializa o singleton PSF com uma configuração de teste para que
| os componentes do framework (JWT, Connect, etc.) funcionem corretamente.
|
*/

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Inicializa o PSF com o config de teste (JWT secret, DB stub, etc.)
PSF::init(['config' => __DIR__ . '/Fixtures/config.php']);
