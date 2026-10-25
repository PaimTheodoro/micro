<?php

namespace Tests\Fixtures\Monitor;

/**
 * Statement de teste (via PDO::ATTR_STATEMENT_CLASS) que registra os valores
 * passados a execute(), junto com o SQL.
 */
class SpyStatement extends \PDOStatement
{
    /** @var array<int, array{sql: string, params: array}> */
    public static array $calls = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$calls[] = ['sql' => $this->queryString, 'params' => $params ?? []];
        return parent::execute($params);
    }

    /**
     * Valores ligados em statements cujo SQL contém `$needle`.
     *
     * @return array<int, mixed>
     */
    public static function boundIn(string $needle): array
    {
        $values = [];
        foreach (self::$calls as $call) {
            if (str_contains($call['sql'], $needle)) {
                foreach ($call['params'] as $v) {
                    $values[] = $v;
                }
            }
        }
        return $values;
    }
}
