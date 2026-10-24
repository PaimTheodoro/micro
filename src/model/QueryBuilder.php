<?php

namespace Psf\Model;

use Psf\Database\Dialect\DialectFactory;
use Psf\Model\Attributes\{Column, ColumnDeletedDate};

class QueryBuilder
{
    private object $obj;
    private array  $query;
    private array  $configDb;
    private ReferenceResolver $resolver;

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
        // settings.strictQueries: referência que não resolve lança erro em vez de gerar SQL quebrado
        $this->resolver = new ReferenceResolver($class, (bool) \PSF::config('settings', 'strictQueries'));
        $this->query    = [
            'fields'     => null,
            'startWith'  => $startWith,
            'wheres'     => null,
            'orWheres'   => null,
            'softWheres' => [],
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

    public function getResolver(): ReferenceResolver
    {
        return $this->resolver;
    }

    /** Alias da entidade raiz (`FROM tabela AS alias`), usável como prefixo em qualquer referência. */
    public function setAlias(string $alias): void
    {
        $this->resolver->setRootAlias($alias);
    }

    public function setStrict(bool $strict): void
    {
        $this->resolver->setStrict($strict);
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

    /**
     * Filtro obrigatório (soft delete / status) — sempre combinado com AND sobre o conjunto
     * where/orWhere do usuário, nunca como parte de um OR. Idempotente por `$key`: chamar
     * duas vezes com a mesma chave (ex.: execute() repetido) não duplica a cláusula.
     */
    public function addSoftWhere(string $key, string|array $query, ?array $parses = null): void
    {
        if (isset($this->query['softWheres'][$key])) {
            return;
        }

        $condition = $this->buildConditionClause($query, $parses);
        if ($condition !== null) {
            $this->query['softWheres'][$key] = $condition;
        }
    }

    public function setFields(?array $fields): void
    {
        if (empty($fields)) {
            $this->query['fields'] = [];
            return;
        }
        foreach ($fields as $item) {
            $this->query['fields'][] = $this->compileField($item);
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
        $type = $type === null ? null : strtoupper(trim($type));

        if ($type === 'ASC' || $type === 'DESC') {
            $this->query['order'][] = $this->compileField($field) . ' ' . $type;
            return;
        }

        if ($type !== null && $type !== '') {
            throw new \InvalidArgumentException("Direção de ORDER BY inválida: use ASC ou DESC (recebido: {$type})");
        }

        // Sem direção: `$field` é uma expressão completa (ex.: 'nome DESC, id ASC') e entra crua no SQL
        $this->assertSafeOrderExpression($field);
        $this->query['order'][] = $this->defer(['fragment', $field]);
    }

    public function addGroupBy(string $field): void
    {
        $this->query['groupBy'][] = $this->compileField($field);
    }

    public function setDatabase(string $database): void
    {
        $this->query['database'] = $database;
    }

    public function addInnerJoin(array|string $table, string $query): void
    {
        $this->registerJoin($table);
        $this->query['innerJoins'][] = ['table' => $table, 'query' => $query];
    }

    public function addLeftJoin(array|string|null $table, string $query): void
    {
        $this->registerJoin($table);
        $this->query['leftJoins'][] = ['table' => $table, 'query' => $query];
    }

    /** Torna a entidade do join resolvível por alias, classe, nome curto e tabela. */
    private function registerJoin(array|string|null $table): void
    {
        if ($table === null || $table === '') {
            return;
        }

        if (is_array($table)) {
            $this->resolver->register($table[0], $table[1] ?? null);
            return;
        }

        $this->resolver->register($table);
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
        $this->registerJoin($table);

        $relationClass = is_array($table) ? $table[0] : $table;
        $tableAlias    = is_array($table) ? $table[1] : $table;
        $columnsMap    = class_exists($relationClass) ? MetadataCache::getColumnMap($relationClass) : [];

        if (empty($this->query['fields'])) {
            $this->query['fields'] = [$this->compileField([$this->resolver->root()['table'], '*'])];
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
            $dialect     = DialectFactory::fromConfig($this->configDb);
            $entity      = $this->resolver->entityFor($tableAlias);
            $ref         = $entity !== null ? $this->resolver->refName($entity) : $tableAlias;
            // Colunas aqui já são nomes de coluna reais: monta o SQL direto, sem passar pela
            // tradução propriedade → coluna (que poderia reinterpretar um nome de coluna)
            $fieldsToAdd = array_map(function ($column) use ($dialect, $ref, $safeAttr) {
                return $dialect->quoteIdentifier($ref) . '.' . $dialect->quoteIdentifier($column)
                    . ' AS ' . $dialect->quoteIdentifier($safeAttr . '_' . $column);
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

    /**
     * Versão imediata de compileField(): devolve o SQL já resolvido com o estado atual da query.
     * Dentro do builder use compileField(), que adia a resolução até writeQuery().
     */
    public function generateField($field): string
    {
        return $this->finalize($this->compileField($field));
    }

    /**
     * Compila um campo/coluna/expressão. Referências (`Order.id`, `p.userId`, `createdAt`...) ficam
     * pendentes e só são traduzidas em writeQuery()/finalize(), quando todas as entidades e aliases da
     * query já estão registrados — assim `andWhere(['p.userId' => 1])` antes do innerJoin funciona.
     */
    public function compileField($field): string
    {
        if (is_string($field)) {
            // Literal numérico (ex.: SELECT 1 em exist())
            if (ctype_digit($field)) {
                return $field;
            }

            if ($this->isSqlExpression($field)) {
                $this->assertSafeExpression($field);
                // Referências dentro da expressão (SUM(Order.id)) também são traduzidas
                return $this->defer(['fragment', $field]);
            }

            if (str_starts_with(ltrim($field), '(')) {
                throw new \InvalidArgumentException(
                    "Expressão iniciada por '(' não é aceita como string; para subquery use ['subquery', 'SELECT ...']"
                );
            }
        }

        if (is_array($field) && isset($field[0]) && $field[0] === 'subquery') {
            return $field[1];
        }

        if (is_object($field) && isset($field->Field)) {
            return $field->Field;
        }

        $prefix = null;
        $name   = null;
        $alias  = null;

        if (is_string($field)) {
            $parts           = preg_split('/\s+as\s+/i', $field);
            $fieldDefinition = $parts[0];
            $alias           = $parts[1] ?? null;

            $fieldParts = explode('.', $fieldDefinition);
            if (count($fieldParts) === 2) {
                [$prefix, $name] = $fieldParts;
            } else {
                $name = $fieldParts[0];
            }
        } elseif (is_array($field)) {
            [$prefix, $name] = $field;
            $alias = $field[2] ?? null;
        } else {
            return (string) $field;
        }

        return $this->defer(['field', $prefix, $name, $alias]);
    }

    /** Resolve um campo estruturado com o estado atual: prefixo (alias, classe, nome curto, tabela) e coluna. */
    private function resolveFieldSpec(?string $prefix, string $name, ?string $alias): string
    {
        // Prefixo (alias, classe, nome curto ou tabela; vazio = entidade raiz) e propriedade → coluna
        $entity = $this->resolver->entityFor($prefix ?: null);
        if ($entity === null) {
            $ref    = $prefix;   // prefixo desconhecido: usado como está, como sempre foi
            $column = $name;
        } else {
            $ref    = $this->resolver->refName($entity);
            $column = $this->resolver->column($entity, $name);
        }

        $dialect = DialectFactory::fromConfig($this->configDb);
        $quote   = fn(string $id) => $dialect->quoteIdentifier($id);

        $compiledField = $quote($ref) . '.' . $quote($column);

        if ($alias) {
            $compiledField .= ' AS ' . $quote($alias);
        }

        return $compiledField;
    }

    // -------------------------------------------------------------------------
    // Resolução adiada
    // -------------------------------------------------------------------------

    /** @var list<array> */
    private array $pending = [];

    private function defer(array $spec): string
    {
        $this->pending[] = $spec;

        return "\x01" . (count($this->pending) - 1) . "\x02";
    }

    /** Substitui os marcadores pendentes pelo SQL resolvido (repete para marcadores aninhados). */
    public function finalize(string $sql): string
    {
        for ($i = 0; $i < 16 && str_contains($sql, "\x01"); $i++) {
            $sql = (string) preg_replace_callback('/\x01(\d+)\x02/', fn(array $m) => $this->resolvePending((int) $m[1]), $sql);
        }

        return $sql;
    }

    private function resolvePending(int $id): string
    {
        $spec = $this->pending[$id];

        return $spec[0] === 'field'
            ? $this->resolveFieldSpec($spec[1], $spec[2], $spec[3])
            : $this->resolver->resolveFragment($spec[1]);
    }

    /** Resolve as referências de um fragmento SQL cru já com o estado atual (sem validar `;`/comentários). */
    public function resolveReferences(string $sql): string
    {
        return $this->resolver->resolveFragment($this->finalize($sql));
    }

    public function __clone()
    {
        // Resolver próprio: aliases/joins adicionados no clone não vazam para a query original
        $this->resolver = clone $this->resolver;
    }

    /**
     * Expressão SQL "crua" aceita em fields/groupBy/orderBy: começa com uma função da
     * allow-list seguida de '(' (ex.: COUNT(...), SUM(...) AS x) ou é um CASE ... END.
     */
    private function isSqlExpression(string $field): bool
    {
        $names = implode('|', array_map(fn($f) => preg_quote($f, '/'), $this->allowedSqlFunctions));

        return (bool) preg_match('/^\s*(?:' . $names . ')\s*\(/i', $field)
            || (bool) preg_match('/^\s*CASE\s/i', $field);
    }

    /** Remove literais entre aspas/backticks para que a análise não tropece em conteúdo de string. */
    private function stripQuoted(string $sql): string
    {
        return (string) preg_replace(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`/s',
            "''",
            $sql
        );
    }

    /** Barra empilhamento de statements e comentários em fragmentos SQL cru (where/join). */
    private function assertSafeFragment(string $sql): void
    {
        $bare = $this->stripQuoted($sql);

        if (str_contains($bare, ';') || str_contains($bare, '--') || str_contains($bare, '/*') || str_contains($bare, '*/')) {
            throw new \InvalidArgumentException('Fragmento SQL inválido: ";" e comentários não são permitidos');
        }
    }

    /** Regras de fragmento + parênteses balanceados e sem palavras-chave de outros statements. */
    private function assertSafeExpression(string $expr): void
    {
        $this->assertSafeFragment($expr);

        $bare  = $this->stripQuoted($expr);
        $depth = 0;
        foreach (str_split($bare) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth < 0) {
                break;
            }
        }
        if ($depth !== 0) {
            throw new \InvalidArgumentException('Expressão SQL inválida: parênteses desbalanceados');
        }

        if (preg_match('/\b(SELECT|UNION|INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|EXEC|EXECUTE|INTO|SLEEP|BENCHMARK|LOAD_FILE|WAITFOR)\b/i', $bare)) {
            throw new \InvalidArgumentException('Expressão SQL inválida: palavra-chave não permitida');
        }
    }

    private function assertSafeOrderExpression(string $expr): void
    {
        if (preg_match('/^\s*[\w.\\\\`"\[\]]+(?:\s+(?:ASC|DESC))?(?:\s*,\s*[\w.\\\\`"\[\]]+(?:\s+(?:ASC|DESC))?)*\s*$/i', $expr)) {
            return;
        }

        if ($this->isSqlExpression($expr)) {
            $this->assertSafeExpression($expr);
            return;
        }

        throw new \InvalidArgumentException("Expressão ORDER BY inválida: {$expr}");
    }

    public function handleExtraQuery(string $query): string
    {
        return $this->finalize($this->deferFragment($query));
    }

    /** Valida o fragmento cru e adia a tradução das referências até writeQuery(). */
    private function deferFragment(string $query): string
    {
        $this->assertSafeFragment($query);

        return $this->defer(['fragment', $query]);
    }

    public function handleTableName(): string
    {
        $dialect = DialectFactory::fromConfig($this->configDb);
        return $dialect->quoteTable(MetadataCache::getTable($this->obj::class), $this->getDatabaseName());
    }

    /** Tabela da raiz para o FROM, com `AS alias` quando ->alias() foi usado. */
    public function handleFromClause(): string
    {
        $alias = $this->resolver->root()['alias'];
        if ($alias === null) {
            return $this->handleTableName();
        }

        $dialect = DialectFactory::fromConfig($this->configDb);
        return $this->handleTableName() . ' AS ' . $dialect->quoteIdentifier($alias);
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
        $table   = $this->resolver->refName($this->resolver->root());

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
            $this->addSoftWhere('status', [$configDb['fields']['status'], '<>', -1]);
        } elseif (property_exists($this->obj, 'status')) {
            $this->addSoftWhere('status', ['status', '<>', -1]);
        }

        if (isset($configDb['fields']['deletado']) && !empty($configDb['fields']['deletado']) && property_exists($this->obj, $configDb['fields']['deletado'])) {
            $this->addSoftWhere('deletado', [$configDb['fields']['deletado'], 'IS NULL', null]);
        } elseif (property_exists($this->obj, 'deletado')) {
            $this->addSoftWhere('deletado', ['deletado', 'IS NULL']);
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

        if ($topClause === '' && isset($this->query['offset']) && $this->query['offset'] !== null && isset($this->query['limit']) && $this->query['limit'] !== null) {
            if ($this->query['order'] === null && !empty($this->obj->getIdentityColumn())) {
                $this->addOrderBy($this->obj::class . '.' . $this->obj->getIdentityColumn(), 'ASC');
            }
        }

        if (isset($this->query['isCount']) && $this->query['isCount'] === true) {
            // Sem chave primária declarada, COUNT(*) em vez de deixar o SELECT sem campos
            $rootRef     = $dialect->quoteIdentifier($this->resolver->refName($this->resolver->root()));
            $fieldsQuery = !empty($primaryKeys)
                ? 'COUNT(' . $rootRef . '.' . $dialect->quoteIdentifier($primaryKeys[0]) . ') as qtd'
                : 'COUNT(*) as qtd';
            $this->query['order'] = null;
        } elseif (!isset($this->query['fields']) || empty($this->query['fields'])) {
            if ((!empty($this->query['innerJoins'])) || (!empty($this->query['leftJoins']))) {
                $fieldsQuery = $dialect->quoteIdentifier($this->resolver->refName($this->resolver->root())) . '.*';
            } else {
                $fieldsQuery = '*';
            }
        } else {
            $fieldsQuery = implode(', ', $this->query['fields']);
        }

        $stringQuery .= $fieldsQuery;
        $stringQuery .= ' FROM ' . $this->handleFromClause() . ' ';

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
                    $stringQuery .= ' LEFT JOIN ' . $dialect->quoteIdentifier($tableName) . ' AS ' . $itemJoin['table'][1] . ' ON ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                } else {
                    if (!empty($itemJoin['table'])) {
                        $tableName    = class_exists($itemJoin['table']) ? (new $itemJoin['table'])->getTableName() : $itemJoin['table'];
                        $stringQuery .= ' LEFT JOIN ' . $dialect->quoteIdentifier($tableName) . ' ON ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                    } else {
                        $stringQuery .= ' LEFT JOIN ' . $this->handleExtraQuery($itemJoin['query']) . ' ';
                    }
                }
            }
        }

        // (wheres AND ...) OR orWheres... — agrupado, e os filtros obrigatórios (soft delete)
        // entram por fora com AND para que um orWhere nunca os contorne.
        $userClause = '';
        if (!empty($this->query['wheres'])) {
            $userClause = implode(' AND ', $this->query['wheres']);
        }
        if (!empty($this->query['orWheres'])) {
            $userClause = ($userClause !== '' ? '(' . $userClause . ') OR ' : '') . implode(' OR ', $this->query['orWheres']);
            $userClause = '(' . $userClause . ')';
        }

        $whereParts = array_merge($userClause !== '' ? [$userClause] : [], array_values($this->query['softWheres'] ?? []));
        if (!empty($whereParts)) {
            $stringQuery .= 'WHERE ' . implode(' AND ', $whereParts);
        }

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

        return $this->finalize(trim(str_replace('  ', ' ', $stringQuery)));
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
            return $this->deferFragment($query);
        }

        if (is_array($query) && $this->isAssociativeCondition($query)) {
            return $this->buildAssocCondition($query);
        }

        if (is_array($query)) {
            switch (count($query)) {
                case 1:
                    $key   = array_keys($query)[0];
                    $parse = uniqid();
                    $this->query['parses'][$parse] = $query[$key];
                    return $this->compileField($key) . ' = :' . $parse;

                case 2:
                    if (is_string($query[0]) && in_array(strtoupper($query[0]), ['OR', 'AND']) && is_array($query[1])) {
                        // Condição inválida aninhada lança exceção (não é descartada em silêncio)
                        $conditions = array_filter(array_map(fn($c) => $this->buildConditionClause($c), $query[1]));
                        if (empty($conditions)) {
                            return null;
                        }
                        return '(' . implode(' ' . strtoupper($query[0]) . ' ', $conditions) . ')';
                    }
                    if (is_string($query[1]) && in_array(strtoupper($query[1]), ['IS NULL', 'IS NOT NULL'], true)) {
                        return $this->compileField($query[0]) . ' ' . strtoupper($query[1]);
                    }
                    break;

                case 3:
                    if (!is_string($query[1])) {
                        break;
                    }
                    $operator = strtoupper(trim($query[1]));
                    if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                        return $this->compileField($query[0]) . ' ' . $operator;
                    }
                    if (in_array($operator, $this->getAcceptComparativeOperators(), true)) {
                        $parse = uniqid();
                        $this->query['parses'][$parse] = $query[2];
                        return $this->compileField($query[0]) . ' ' . $operator . ' :' . $parse;
                    }
                    if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true) && is_array($query[2]) && count($query[2]) === 2) {
                        [$from, $to] = array_values($query[2]);
                        $parseFrom = uniqid();
                        $this->query['parses'][$parseFrom] = $from;
                        $parseTo = uniqid();
                        $this->query['parses'][$parseTo] = $to;
                        return $this->compileField($query[0]) . ' ' . $operator . ' :' . $parseFrom . ' AND :' . $parseTo;
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
                        return $this->compileField($query[0]) . ' ' . $operator . ' (' . implode(',', $placeholders) . ')';
                    }
                    break;
            }
        }

        // Antes só emitia warning e devolvia null, fazendo a query rodar SEM o filtro
        throw new \InvalidArgumentException('Formato de condição WHERE inválido: ' . json_encode($query));
    }

    /** ['coluna' => valor, ...]: todas as chaves são strings. */
    private function isAssociativeCondition(array $query): bool
    {
        if ($query === [] || array_is_list($query)) {
            return false;
        }

        foreach (array_keys($query) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ['status' => 1, 'type' => [1, 2], 'deletedAt' => null] →
     * (status = :a AND type IN (:b,:c) AND deletedAt IS NULL), com as chaves resolvidas como
     * qualquer referência (Order::class.'.status', 'o.status' ou só 'status' na entidade raiz).
     */
    private function buildAssocCondition(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            $field = $this->compileField($key);

            if ($value === null) {
                $parts[] = $field . ' IS NULL';
            } elseif (is_array($value)) {
                if ($value === []) {
                    $parts[] = '0=1';
                    continue;
                }

                $placeholders = [];
                foreach ($value as $item) {
                    $parse                         = uniqid();
                    $placeholders[]                = ':' . $parse;
                    $this->query['parses'][$parse] = $item;
                }
                $parts[] = $field . ' IN (' . implode(',', $placeholders) . ')';
            } else {
                $parse                         = uniqid();
                $this->query['parses'][$parse] = $value;
                $parts[]                       = $field . ' = :' . $parse;
            }
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
    }

    private function getAcceptComparativeOperators(): array
    {
        return ['=', '<>', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE'];
    }
}
