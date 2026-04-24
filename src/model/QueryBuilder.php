<?php

namespace Psf\Model;

use Psf\Database\Dialect\DialectFactory;
use Psf\Model\Attributes\{Column, ColumnDeletedDate};

class QueryBuilder
{
    private object $obj;
    private array  $query;
    private array  $configDb;

    private array $allowedSqlFunctions = [
        'SUM', 'COUNT', 'AVG', 'MIN', 'MAX',
        'CONCAT', 'LEFT', 'RIGHT', 'UPPER', 'LOWER', 'TRIM', 'LENGTH',
        'DATE', 'YEAR', 'MONTH', 'DAY', 'NOW', 'CURDATE', 'CURTIME',
        'IF', 'CASE', 'COALESCE',
    ];

    public function __construct(string $class, ?string $startWith, array $configDb)
    {
        $this->obj      = new $class;
        $this->configDb = $configDb;
        $this->query    = [
            'fields'     => null,
            'startWith'  => $startWith,
            'wheres'     => null,
            'orWheres'   => null,
            'innerJoins' => null,
            'leftJoins'  => null,
            'order'      => null,
            'groupBy'    => null,
            'limit'      => null,
            'offset'     => null,
            'parses'     => null,
            'freequery'  => null,
            'isCount'    => false,
            'asArray'    => false,
            'database'   => MetadataCache::getDatabase($class) ?? 'default',
        ];
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getQuery(): array
    {
        return $this->query;
    }

    public function getObj(): object
    {
        return $this->obj;
    }

    public function getConfigDb(): array
    {
        return $this->configDb;
    }

    public function getDatabaseName(): string
    {
        return \PSF::getConfig()->db[MetadataCache::getDatabase($this->obj::class)]['database'];
    }

    // -------------------------------------------------------------------------
    // State mutators — called by ModelQuery fluent methods
    // -------------------------------------------------------------------------

    public function addAndWhere(string|array $query, ?array $parses = null): void
    {
        $condition = $this->buildConditionClause($query, $parses);
        if ($condition !== null) {
            $this->query['wheres'][] = $condition;
        }
    }

    public function addOrWhere(string|array $query, ?array $parses = null): void
    {
        $condition = $this->buildConditionClause($query, $parses);
        if ($condition !== null) {
            $this->query['orWheres'][] = $condition;
        }
    }

    public function setFields(?array $fields): void
    {
        if (empty($fields)) {
            $this->query['fields'] = [];
            return;
        }
        foreach ($fields as $item) {
            $this->query['fields'][] = $this->generateField($item);
        }
    }

    public function setLimit(?int $limit): void
    {
        $this->query['limit'] = $limit;
    }

    public function setOffset(?int $offset): void
    {
        $this->query['offset'] = $offset;
    }

    public function addOrderBy(string $field, ?string $type = 'ASC'): void
    {
        if ($type === 'ASC' || $type === 'DESC') {
            $this->query['order'][] = $this->generateField($field) . ' ' . $type;
        } else {
            $this->query['order'][] = $field;
        }
    }

    public function addGroupBy(string $field): void
    {
        $this->query['groupBy'][] = $this->generateField($field);
    }

    public function setDatabase(string $database): void
    {
        $this->query['database'] = $database;
    }

    public function addInnerJoin(array|string $table, string $query): void
    {
        $this->query['innerJoins'][] = ['table' => $table, 'query' => $query];
    }

    public function addLeftJoin(array|string|null $table, string $query): void
    {
        $this->query['leftJoins'][] = ['table' => $table, 'query' => $query];
    }

    public function setIsCount(bool $value): void
    {
        $this->query['isCount'] = $value;
    }

    public function setAsArray(bool $value): void
    {
        $this->query['asArray'] = $value;
    }

    public function setOrderNull(): void
    {
        $this->query['order'] = null;
    }

    public function setGroupByNull(): void
    {
        $this->query['groupBy'] = null;
    }

    public function prependField(string $field): void
    {
        array_unshift($this->query['fields'], $field);
    }

    public function leftJoinAndSelect(array|string $table, string $attr, string $joinQuery, array $fields = []): void
    {
        $relationClass = is_array($table) ? $table[0] : $table;
        $tableAlias    = is_array($table) ? $table[1] : $table;
        $columnsMap    = class_exists($relationClass) ? MetadataCache::getColumnMap($relationClass) : [];

        if (empty($this->query['fields'])) {
            $this->query['fields'] = [$this->generateField([MetadataCache::getTable($this->obj::class), '*'])];
        }

        if (!empty($columnsMap) && is_array($columnsMap)) {
            if (!empty($fields)) {
                $columnsToSelect = array_values(array_filter(array_map(function ($field) use ($columnsMap) {
                    return $columnsMap[$field] ?? $field;
                }, $fields)));
            } else {
                $columnsToSelect = array_values($columnsMap);
            }

            $safeAttr    = str_replace('.', '#', $attr);
            $fieldsToAdd = array_map(function ($column) use ($tableAlias, $safeAttr) {
                $field = $tableAlias . '.' . $column . ' AS ' . $safeAttr . '_' . $column;
                return $this->generateField($field);
            }, $columnsToSelect);

            $this->query['fields'] = array_merge($this->query['fields'], array_values($fieldsToAdd));
        }

        $this->query['leftJoins'][] = [
            'table'     => $table,
            'query'     => $joinQuery,
            'andSelect' => $attr,
        ];
    }

    // -------------------------------------------------------------------------
    // SQL builders
    // -------------------------------------------------------------------------

    public function generateField($field): string
    {
        if (is_string($field)) {
            $upperField = strtoupper($field);
            foreach ($this->allowedSqlFunctions as $function) {
                if (str_starts_with($upperField, '(') || str_starts_with($upperField, $function) || str_starts_with($upperField, $function . '(')) {
                    return $field;
                }
            }
        }

        if (is_array($field) && isset($field[0]) && $field[0] === 'subquery') {
            return $field[1];
        }

        if (is_object($field) && isset($field->Field)) {
            return $field->Field;
        }

        $tableOrClass = null;
        $columnOrProp = null;
        $alias        = null;

        if (is_string($field)) {
            $parts           = preg_split('/\s+as\s+/i', $field);
            $fieldDefinition = $parts[0];
            $alias           = $parts[1] ?? null;

            $fieldParts = explode('.', $fieldDefinition);
            if (count($fieldParts) === 2) {
                [$tableOrClass, $columnOrProp] = $fieldParts;
            } else {
                $columnOrProp = $fieldParts[0];
            }
        } elseif (is_array($field)) {
            [$tableOrClass, $columnOrProp] = $field;
            $alias = $field[2] ?? null;
        } else {
            return (string) $field;
        }

        if ($tableOrClass && class_exists($tableOrClass)) {
            $table  = MetadataCache::getTable($tableOrClass);
            $column = MetadataCache::getColumnByProp($tableOrClass, $columnOrProp);
            if ($column === false) {
                $column = $columnOrProp;
            }
        } else {
            $table  = $tableOrClass;
            $column = $columnOrProp;
        }

        if ($column === '*') {
            $table = $table ?? MetadataCache::getTable($this->obj::class);
        } elseif (empty($table)) {
            $table = MetadataCache::getTable($this->obj::class);
        }

        $dialect = DialectFactory::fromConfig($this->configDb);
        $quote   = fn(string $id) => $dialect->quoteIdentifier($id);

        $compiledField = $quote($table) . '.' . $quote($column);

        if ($alias) {
            $compiledField .= ' AS ' . $quote($alias);
        }

        return $compiledField;
    }

    public function handleExtraQuery(string $query): string
    {
        $explodeSpaces = explode(' ', $query);

        foreach ($explodeSpaces as &$itemSpaced) {
            $explodeField = explode('.', $itemSpaced);

            foreach ($explodeField as &$itemField) {
                if (str_contains($itemField, "\\")) {
                    if (class_exists($itemField)) {
                        $class     = $itemField;
                        $itemField = (new $itemField)->getTableName();
                    }
                }

                if (!in_array($itemField, ['=', '<>'])) {
                    if (isset($class)) {
                        $getField = MetadataCache::getColumnByProp($class, $itemField);
                        if (!empty($getField)) {
                            $itemField = $getField;
                        }
                    }
                }
            }
            unset($itemField);

            $itemSpaced = implode('.', $explodeField);
        }
        unset($itemSpaced);

        return implode(' ', $explodeSpaces);
    }

    public function handleTableName(): string
    {
        $dialect = DialectFactory::fromConfig($this->configDb);
        return $dialect->quoteTable(MetadataCache::getTable($this->obj::class), $this->getDatabaseName());
    }

    public static function getHandleTableName(string $table, string $database = 'default'): string
    {
        $configDb = \PSF::getConfig()->db;
        $dialect  = DialectFactory::fromConfig($configDb[$database]);
        return $dialect->quoteTable($table, $configDb[$database]['database']);
    }

    public function getAllFields(string $class): string
    {
        $dialect = DialectFactory::fromConfig($this->configDb);
        $columns = MetadataCache::getColumnMap($class);
        $table   = MetadataCache::getTable($this->obj::class);

        if (!empty($columns) && is_array($columns)) {
            return implode(',', array_map(
                fn($col) => $dialect->quoteIdentifier($table) . '.' . $dialect->quoteIdentifier($col),
                $columns
            ));
        }

        return $dialect->quoteIdentifier($table) . '.*';
    }

    public function getParses(): array|false
    {
        if (empty($this->query['parses'])) {
            return false;
        }

        return $this->query['parses'];
    }

    public function getRowQuery(): string
    {
        $configDb = \PSF::getConfig()->db[MetadataCache::getDatabase($this->obj::class)];

        if (isset($configDb['fields']['status']) && !empty($configDb['fields']['status']) && property_exists($this->obj, $configDb['fields']['status'])) {
            $this->addAndWhere([$this->obj::class . '.' . $configDb['fields']['status'], '<>', -1]);
        } elseif (property_exists($this->obj, 'status')) {
            $this->addAndWhere([$this->obj::class . '.status', '<>', -1]);
        }

        if (isset($configDb['fields']['deletado']) && !empty($configDb['fields']['deletado']) && property_exists($this->obj, $configDb['fields']['deletado'])) {
            $this->addAndWhere([$this->obj::class . '.' . $configDb['fields']['deletado'], 'IS NULL', null]);
        } elseif (property_exists($this->obj, 'deletado')) {
            $this->addAndWhere([$this->obj::class . '.deletado', 'IS NULL']);
        }

        $query = $this->writeQuery();
        if (!empty($this->query['parses']) && is_array($this->query['parses'])) {
            foreach ($this->query['parses'] as $key => $value) {
                $query = str_replace(':' . $key, $value, $query);
            }
        }
        return $query;
    }

    public function writeQuery(): string
    {
        $dialect     = DialectFactory::fromConfig($this->configDb);
        $primaryKeys = MetadataCache::getPrimaryKey($this->obj::class);

        $stringQuery = !empty($this->query['startWith']) ? $this->query['startWith'] . ' ' : 'SELECT ';

        $topClause = $dialect->topClause($this->query['limit'] ?? null, $this->query['offset'] ?? null);
        if ($topClause !== '') {
            $stringQuery .= $topClause;
        }

        if (!empty($topClause === '') && isset($this->query['offset']) && $this->query['offset'] !== null && isset($this->query['limit']) && $this->query['limit'] !== null) {
            if ($this->query['order'] === null && !empty($this->obj->getIdentityColumn())) {
                $this->addOrderBy($this->obj::class . '.' . $this->obj->getIdentityColumn(), 'ASC');
            }
        }

        if (isset($this->query['isCount']) && $this->query['isCount'] === true) {
            if (!empty($primaryKeys)) {
                $fieldsQuery = 'COUNT(' . $this->generateField($primaryKeys[0]) . ') as qtd';
                $this->query['order'] = null;
            }
        } elseif (!isset($this->query['fields']) || empty($this->query['fields'])) {
            if ((!empty($this->query['innerJoins'])) || (!empty($this->query['leftJoins']))) {
                $fieldsQuery = $dialect->quoteIdentifier(MetadataCache::getTable($this->obj::class)) . '.*';
            } else {
                $fieldsQuery = '*';
            }
        } else {
            $fieldsQuery = implode(', ', $this->query['fields']);
        }

        $stringQuery .= $fieldsQuery;
        $stringQuery .= ' FROM ' . $this->handleTableName() . ' ';

        if (!empty($this->query['innerJoins'])) {
            foreach ($this->query['innerJoins'] as $itemJoin) {
                if (is_array($itemJoin['table'])) {
                    $tableName    = class_exists($itemJoin['table'][0]) ? (new $itemJoin['table'][0])->getTableName() : $itemJoin['table'][0];
                    $stringQuery .= ' INNER JOIN ' . $dialect->quoteIdentifier($tableName) . ' AS ' . $itemJoin['table'][1] . ' ON ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                } else {
                    $tableName    = class_exists($itemJoin['table']) ? (new $itemJoin['table'])->getTableName() : $itemJoin['table'];
                    $stringQuery .= ' INNER JOIN ' . $dialect->quoteIdentifier($tableName) . ' ON ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                }
            }
        }

        if (!empty($this->query['leftJoins'])) {
            foreach ($this->query['leftJoins'] as $itemJoin) {
                if (is_array($itemJoin['table'])) {
                    $tableName    = class_exists($itemJoin['table'][0]) ? (new $itemJoin['table'][0])->getTableName() : $itemJoin['table'][0];
                    $stringQuery .= ' LEFT JOIN ' . $tableName . ' AS ' . $itemJoin['table'][1] . ' ON ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                } else {
                    if (!empty($itemJoin['table'])) {
                        $tableName    = class_exists($itemJoin['table']) ? (new $itemJoin['table'])->getTableName() : $itemJoin['table'];
                        $stringQuery .= ' LEFT JOIN ' . $tableName . ' ON ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                    } else {
                        $stringQuery .= ' LEFT JOIN ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                    }
                }
            }
        }

        if (!empty($this->query['wheres'])) {
            $stringWhere  = 'WHERE ';
            $countWheres  = 0;
            $countOrWheres = 0;

            foreach ($this->query['wheres'] as $item) {
                $stringWhere .= $item;
                if ($countWheres < (count($this->query['wheres']) - 1)) {
                    $stringWhere .= ' AND ';
                }
                $countWheres++;
            }

            if (!empty($this->query['wheres']) && !empty($this->query['orWheres'])) {
                $stringWhere .= ' OR ';
            }

            if (!empty($this->query['orWheres'])) {
                foreach ($this->query['orWheres'] as $item) {
                    $stringWhere .= $item;
                    if ($countOrWheres < (count($this->query['orWheres']) - 1)) {
                        $stringWhere .= ' OR ';
                    }
                    $countOrWheres++;
                }
            }
        }

        $stringQuery .= ($stringWhere ?? '');

        if (!empty($this->query['groupBy'])) {
            if (count($this->query['groupBy']) === 1) {
                $stringQuery .= ' GROUP BY ' . $this->query['groupBy'][0];
            } else {
                $stringQuery .= ' GROUP BY ' . implode(', ', $this->query['groupBy']);
            }
        }

        if (!empty($this->query['order'])) {
            $stringQuery .= ' ORDER BY ' . implode(', ', $this->query['order']);
        }

        if (!isset($this->query['isCount']) || $this->query['isCount'] === false) {
            $stringQuery .= $dialect->limitOffsetClause(
                $this->query['limit']  ?? null,
                $this->query['offset'] ?? null
            );
        }

        return trim(str_replace('  ', ' ', $stringQuery));
    }

    // -------------------------------------------------------------------------
    // Condition builder (private — called by addAndWhere / addOrWhere)
    // -------------------------------------------------------------------------

    private function buildConditionClause(string|array $query, ?array $parses = null): ?string
    {
        if (is_string($query)) {
            if (!empty($parses)) {
                foreach ($parses as $key => $item) {
                    $parse = uniqid();
                    $query = str_replace(':' . $key . ':', ':' . $parse, $query);
                    $this->query['parses'][$parse] = $item;
                }
            }
            return $this->handleExtraQuery($query);
        }

        if (is_array($query)) {
            switch (count($query)) {
                case 1:
                    $key   = array_keys($query)[0];
                    $parse = uniqid();
                    $this->query['parses'][$parse] = $query[$key];
                    return $this->generateField($key) . ' = :' . $parse;

                case 2:
                    if (in_array(strtoupper($query[0]), ['OR', 'AND']) && is_array($query[1])) {
                        $conditions = array_filter(array_map(fn($c) => $this->buildConditionClause($c), $query[1]));
                        if (empty($conditions)) {
                            return null;
                        }
                        return '(' . implode(' ' . strtoupper($query[0]) . ' ', $conditions) . ')';
                    }
                    if (in_array(strtoupper($query[1]), ['IS NULL', 'IS NOT NULL'], true)) {
                        return $this->generateField($query[0]) . ' ' . $query[1];
                    }
                    break;

                case 3:
                    $operator = strtoupper($query[1]);
                    if (in_array($operator, $this->getAcceptComparativeOperators(), true)) {
                        $parse = uniqid();
                        $this->query['parses'][$parse] = $query[2];
                        return $this->generateField($query[0]) . ' ' . $operator . ' :' . $parse;
                    }
                    if (in_array($operator, ['IN', 'NOT IN']) && is_array($query[2])) {
                        if (empty($query[2])) {
                            return $operator === 'IN' ? '0=1' : '1=1';
                        }
                        $placeholders = [];
                        foreach ($query[2] as $itemIn) {
                            $parse          = uniqid();
                            $placeholders[] = ':' . $parse;
                            $this->query['parses'][$parse] = $itemIn;
                        }
                        return $this->generateField($query[0]) . ' ' . $operator . ' (' . implode(',', $placeholders) . ')';
                    }
                    break;
            }
        }

        trigger_error('Formato de condição WHERE inválido: ' . json_encode($query), E_USER_WARNING);
        return null;
    }

    private function getAcceptComparativeOperators(): array
    {
        return ['=', '<>', '>', '<', 'IS NULL', 'IS NOT NULL', 'LIKE'];
    }
}
