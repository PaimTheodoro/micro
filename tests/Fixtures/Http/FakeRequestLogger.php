<?php

namespace Tests\Fixtures\Http;

/** Callback de `settings.logrequest` que guarda a última chamada para inspeção nos testes. */
class FakeRequestLogger
{
    public static ?array $last = null;

    public function log(...$args): void
    {
        self::$last = $args;
    }
}
