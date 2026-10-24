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

    /**
     * innerJoin(Product::class, 'Product.userId = User.id')              — forma original
     * innerJoin([Product::class, 'p'], 'p.userId = User.id')             — alias via array
     * innerJoin(Product::class, 'p', 'p.userId = User.id')               — alias em 3 argumentos
     */
    public function innerJoin(array|string $table, string $query, ?string $on = null): ModelQuery
    {
        if ($on !== null) {
            [$table, $query] = [[$table, $query], $on];
        }

        $this->builder->addInnerJoin($table, $query);
        return $this;
    }

    /** Mesmas formas de innerJoin(); `$table` null aceita um join escrito por inteiro em `$query`. */
    public function leftJoin(array|string|null $table, string $query, ?string $on = null): ModelQuery
    {
        if ($on !== null) {
            [$table, $query] = [[$table, $query], $on];
        }

        $this->builder->addLeftJoin($table, $query);
        return $this;
    }

    /**
     * Dá um alias à entidade raiz (`FROM tabela AS o`); passa a valer como prefixo em qualquer
     * referência: `o.status`, `Order::class.'.status'` e `Order::col('status')` resolvem para ele.
     */
    public function alias(string $alias): ModelQuery
    {
        $this->builder->setAlias($alias);
        return $this;
    }

    /**
     * Modo estrito para esta query (padrão: settings.strictQueries): referência que não resolve —
     * classe inexistente, propriedade desconhecida, prefixo ambíguo — lança InvalidArgumentException.
     */
    public function strict(bool $strict = true): ModelQuery
    {
        $this->builder->setStrict($strict);
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
        $obj = $this->builder->getObj();

        // Soft delete como filtro obrigatório (AND por fora de where/orWhere) e idempotente
        $softDeleteProp = MetadataCache::getSoftDeleteProp($obj::class);
        if ($softDeleteProp !== null) {
            // Nome solto = entidade raiz: inequívoco mesmo com self-join, alias e modo estrito
            $this->builder->addSoftWhere('deleted_date', [$softDeleteProp, 'IS NULL']);
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
        $this->builder->setFields(['SUM(' . $this->builder->compileField($field) . ') AS result']);
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
        $countField  = 'COUNT(' . $this->builder->compileField([$obj::class, $primaryKey[0]]) . ') OVER () AS totalItensFromPagination';
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

    public function query($query, $parseString = null, $database = null)
    {
        $builderQuery = $this->builder->getQuery();
        $fields       = $builderQuery['fields'];
        // Sem este prefixo o SQL ficava "* FROM ..." (inválido em qualquer banco)
        $verb         = !empty($builderQuery['startWith']) ? $builderQuery['startWith'] . ' ' : 'SELECT ';

        if (empty($fields)) {
            $query = $verb . '* FROM ' . $this->builder->handleFromClause() . ' ' . $query;
        } else {
            $fieldsQuery = implode(', ', $fields);
            $query       = $verb . $fieldsQuery . ' FROM ' . $this->builder->handleFromClause() . ' ' . $query;
        }

        // Campos pendentes e referências no trecho livre (Order.id, o.status...) são traduzidos aqui
        $query = $this->builder->resolveReferences($query);

        $stringParseString = (!empty($parseString) && is_array($parseString)) ? $parseString : null;

        $obj      = $this->builder->getObj();
        $database = $database ?? MetadataCache::getDatabase($obj::class);
        $Read     = new \Psf\Database\Read();
        $Read->exe(
            MetadataCache::getTable($obj::class),
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
