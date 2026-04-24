<?php

namespace Psf\Model;

use Psf\Model\Attributes\{Column, ColumnDeletedDate};

class ModelQuery
{
    private QueryBuilder $builder;
    private QueryHydrator $hydrator;

    public function __construct($class, $startWith = null)
    {
        $configDb       = \PSF::getConfig()->db[MetadataCache::getDatabase($class)];
        $this->builder  = new QueryBuilder($class, $startWith, $configDb);
        $this->hydrator = new QueryHydrator($class);
    }

    public function __clone()
    {
        $this->builder  = clone $this->builder;
        $this->hydrator = clone $this->hydrator;
    }

    // -------------------------------------------------------------------------
    // Static helper — public API preserved
    // -------------------------------------------------------------------------

    public static function getHandleTableName(string $table, string $database = 'default'): string
    {
        return QueryBuilder::getHandleTableName($table, $database);
    }

    // -------------------------------------------------------------------------
    // Fluent query API — all return $this (ModelQuery), not QueryBuilder
    // -------------------------------------------------------------------------

    public function andWhere(string|array $query, array|null $parses = null): ModelQuery
    {
        $this->builder->addAndWhere($query, $parses);
        return $this;
    }

    public function orWhere(string|array $query, array|null $parses = null): ModelQuery
    {
        $this->builder->addOrWhere($query, $parses);
        return $this;
    }

    public function fields(array|null $fields = null): ModelQuery
    {
        $this->builder->setFields($fields);
        return $this;
    }

    public function innerJoin(array|string $table, string $query): ModelQuery
    {
        $this->builder->addInnerJoin($table, $query);
        return $this;
    }

    public function leftJoin(array|string|null $table, string $query): ModelQuery
    {
        $this->builder->addLeftJoin($table, $query);
        return $this;
    }

    public function orderBy(string $field, string|null $type = 'ASC'): ModelQuery
    {
        $this->builder->addOrderBy($field, $type);
        return $this;
    }

    public function limit(int $limit): ModelQuery
    {
        $this->builder->setLimit($limit);
        return $this;
    }

    public function groupBy(string $field): ModelQuery
    {
        $this->builder->addGroupBy($field);
        return $this;
    }

    public function database(string $database): ModelQuery
    {
        $this->builder->setDatabase($database);
        return $this;
    }

    public function asArray(): ModelQuery
    {
        $this->builder->setAsArray(true);
        return $this;
    }

    public function leftJoinAndSelect(array|string $table, string $attr, string $query, array $fields = []): ModelQuery
    {
        $this->builder->leftJoinAndSelect($table, $attr, $query, $fields);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Terminal operations
    // -------------------------------------------------------------------------

    public function one()
    {
        $this->builder->setLimit(1);
        return $this->execute();
    }

    public function all()
    {
        $this->builder->setLimit(null);
        return $this->execute();
    }

    public function count(): int
    {
        $this->builder->setIsCount(true);
        return $this->execute();
    }

    public function countAll(): int
    {
        $countTotal = clone $this;
        $countTotal->builder->setIsCount(true);
        $countTotal->builder->setLimit(null);
        $countTotal->builder->setOffset(null);
        $countTotal->builder->setOrderNull();
        $countTotal->builder->setGroupByNull();

        return $countTotal->execute();
    }

    public function execute()
    {
        $obj      = $this->builder->getObj();
        $refClass = new \ReflectionClass($obj::class);

        foreach ($refClass->getProperties() as $property) {
            $attributes    = $property->getAttributes();
            $hasDeletedDate = false;
            $columnName    = null;

            foreach ($attributes as $attr) {
                $attrName = method_exists($attr, 'getName') ? $attr->getName() : (property_exists($attr, 'name') ? $attr->name : null);

                if ($attrName === ColumnDeletedDate::class || (is_object($attr) && $attr instanceof ColumnDeletedDate)) {
                    $hasDeletedDate = true;
                }

                if ($attrName === Column::class || (is_object($attr) && $attr instanceof Column)) {
                    if (method_exists($attr, 'getArguments')) {
                        $args       = $attr->getArguments();
                        $columnName = $args[0] ?? $args['name'] ?? null;
                    } else {
                        $columnName = $attr->name ?? null;
                    }
                }
            }

            if ($hasDeletedDate) {
                $this->andWhere([$obj::class . '.' . $columnName, 'IS NULL']);
                break;
            }
        }

        $query  = $this->builder->getQuery();
        $Read   = new \Psf\Database\Read();
        $Read->exe(
            MetadataCache::getTable($obj::class),
            $this->builder->writeQuery(),
            $this->builder->getParses(),
            MetadataCache::getDatabase($obj::class),
            true
        );

        return $this->hydrator->queryResult($Read, $query);
    }

    public function exist(): bool
    {
        $this->builder->setLimit(1);
        $this->builder->setFields(['1']);
        $this->builder->setAsArray(true);
        $executeSelect = $this->execute();
        return !empty($executeSelect);
    }

    public function sum(string $field): float
    {
        $this->builder->setLimit(1);
        $this->builder->setFields(['SUM(' . $this->builder->generateField($field) . ') AS result']);
        $this->builder->setAsArray(true);
        $executeSelect = $this->execute();

        if ($executeSelect && isset($executeSelect['result']) && !empty($executeSelect['result'])) {
            return (float) $executeSelect['result'];
        }

        return 0;
    }

    public function paginator(int $page = 1, int $itensPerPage = 25, $callbackFunction = null): object|array|bool|null
    {
        if ($page < 1) {
            $page = 1;
        }

        $initIn = $page === 1 ? 0 : (($page - 1) * $itensPerPage);

        $this->builder->setAsArray(true);
        $this->builder->setLimit($itensPerPage);
        $this->builder->setOffset($initIn);

        $query = $this->builder->getQuery();
        if (empty($query['fields'])) {
            $obj = $this->builder->getObj();
            $this->builder->setFields([Model::getTable($obj::class) . '.*']);
        }

        $obj         = $this->builder->getObj();
        $primaryKey  = Model::getPrimaryKey($obj::class);
        $countField  = 'COUNT(' . $this->builder->generateField([$obj::class, $primaryKey[0]]) . ') OVER () AS totalItensFromPagination';
        $this->builder->prependField($countField);

        $itens = $this->execute();
        if ($itens === false) {
            $itens = [];
        }

        $total          = isset($itens[0]['totalItensFromPagination']) ? $itens[0]['totalItensFromPagination'] : 0;
        $estimatedPages = ceil($total / $itensPerPage);

        $paginatorData = [
            'itens' => [
                'total'       => $total,
                'perPage'     => $itensPerPage,
                'inThisPage'  => count($itens),
            ],
            'pages' => [
                'atual'     => $page,
                'estimated' => $estimatedPages,
                'hasBefore' => $page <= 1 ? false : true,
                'hasAfter'  => $page >= $estimatedPages ? false : true,
            ],
        ];

        array_walk($itens, function (&$item) {
            unset($item['totalItensFromPagination']);
        });

        $result = ['itens' => $itens, 'paginator' => $paginatorData];

        return empty($callbackFunction) ? $result : call_user_func($callbackFunction, $result);
    }

    public function query($query, $parseString = null, $database = 'default')
    {
        $fields = $this->builder->getQuery()['fields'];

        if (empty($fields)) {
            $query = '* FROM ' . $this->builder->handleTableName() . ' ' . $query;
        } else {
            $fieldsQuery = implode(', ', $fields);
            $query       = $fieldsQuery . ' FROM ' . $this->builder->handleTableName() . ' ' . $query;
        }

        $stringParseString = (!empty($parseString) && is_array($parseString)) ? $parseString : null;

        $obj  = $this->builder->getObj();
        $Read = new \Psf\Database\Read($obj->databaseConnect ?? null);
        $Read->exe(
            $obj->table,
            $query,
            $stringParseString,
            $database,
            true
        );

        return $this->hydrator->queryResult($Read, $this->builder->getQuery());
    }

    public function getRowQuery(): string
    {
        return $this->builder->getRowQuery();
    }

    public function getParses(): array|false
    {
        return $this->builder->getParses();
    }

    public function getAllFields($class): string
    {
        return $this->builder->getAllFields($class);
    }

    public function dump()
    {
        echo '<pre>';
        var_dump($this);
        die;
    }
}
