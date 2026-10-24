<?php

namespace Tests\Fixtures\Controllers;

use Psf\Http\Http;
use Psf\Model\ControllerBase;

class FakeController extends ControllerBase
{
    public static int $instances = 0;

    public function __construct()
    {
        parent::__construct();
        self::$instances++;
    }

    public function ok(): array
    {
        return ['ok', null, 200];
    }

    public function show($id): array
    {
        return ['show', ['id' => $id], 200];
    }

    public function respond(): void
    {
        Http::response('Campo inválido', [], 422);
    }

    public function forbidden(): void
    {
        throw new \Exception('Sem permissão', 403);
    }

    public function boom(): void
    {
        throw new \RuntimeException('detalhe interno', 7);
    }
}
