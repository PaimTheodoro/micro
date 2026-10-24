<?php

namespace Psf\Model;

/**
 * Traduz referências a entidades/colunas para o SQL real — o único tradutor usado por todos
 * os pontos do QueryBuilder (where em array e em string, ON de join, orderBy, groupBy, fields
 * e expressões).
 *
 * Uma referência é `Prefixo.nome`. O prefixo pode ser (nesta ordem de busca, entre as
 * entidades que participam da query — a raiz e os joins):
 *   1. um alias           `p.userId`         (de innerJoin([Product::class, 'p'], ...) ou ->alias('o'))
 *   2. a classe completa  `App\Models\Order.id`  (Order::class . '.id')
 *   3. o nome curto       `Order.id`
 *   4. o nome da tabela   `orders.id`
 *   5. o nome curto sem diferenciar maiúsculas (`order.id`)
 * Sem prefixo, vale a entidade raiz. Em seguida o nome é traduzido de propriedade para coluna
 * (`createdAt` → `created_at`); se não for uma propriedade conhecida, é mantido como coluna
 * literal — o comportamento de sempre.
 *
 * No modo estrito (settings.strictQueries ou ->strict()), o que parece uma referência mas não
 * resolve (classe inexistente, propriedade desconhecida, prefixo ambíguo) lança
 * InvalidArgumentException em vez de gerar SQL quebrado.
 */
class ReferenceResolver
{
    /** Palavras que nunca são traduzidas quando aparecem soltas num fragmento SQL cru. */
    private const SQL_WORDS = [
        'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'LIKE', 'BETWEEN', 'EXISTS', 'CASE', 'WHEN', 'THEN',
        'ELSE', 'END', 'AS', 'ON', 'ASC', 'DESC', 'TRUE', 'FALSE', 'SELECT', 'FROM', 'WHERE', 'JOIN',
        'LEFT', 'RIGHT', 'INNER', 'OUTER', 'USING', 'GROUP', 'ORDER', 'BY', 'HAVING', 'LIMIT', 'OFFSET',
        'UNION', 'ALL', 'ANY', 'SOME', 'DISTINCT', 'INTERVAL', 'DIV', 'MOD', 'XOR', 'REGEXP', 'RLIKE',
        'COLLATE', 'ESCAPE', 'CAST', 'CONVERT', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP',
    ];

    /**
     * @var list<array{class: ?string, table: string, alias: ?string}>
     */
    private array $entities = [];

    private bool $strict;

    public function __construct(string $rootClass, bool $strict = false)
    {
        $this->strict   = $strict;
        $this->entities = [['class' => $rootClass, 'table' => MetadataCache::getTable($rootClass), 'alias' => null]];
    }

    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    // -------------------------------------------------------------------------
    // Entidades da query
    // -------------------------------------------------------------------------

    public function setRootAlias(?string $alias): void
    {
        $this->entities[0]['alias'] = $alias === null ? null : self::assertAlias($alias);
    }

    /**
     * Registra uma entidade que participa da query (join). `$classOrTable` é uma classe de
     * model ou o nome de uma tabela; sem classe não há tradução de propriedade, só de prefixo.
     */
    public function register(string $classOrTable, ?string $alias = null): void
    {
        $class = ltrim($classOrTable, '\\');
        $table = self::tableOf($class);

        $this->entities[] = [
            'class' => $table !== null ? $class : null,
            'table' => $table ?? $classOrTable,
            'alias' => $alias === null ? null : self::assertAlias($alias),
        ];
    }

    public static function assertAlias(string $alias): string
    {
        if (!preg_match('/^[A-Za-z_]\w*$/', $alias)) {
            throw new \InvalidArgumentException("Alias inválido: '{$alias}' (use letras, números e _)");
        }

        return $alias;
    }

    /** Tabela de uma classe de model, ou null se não for uma classe com #[Table]. */
    private static function tableOf(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        $table = Model::getTable($class);

        return is_string($table) && $table !== '' ? $table : null;
    }

    public function root(): array
    {
        return $this->entities[0];
    }

    /** Nome a usar como prefixo no SQL: o alias, se houver, senão a tabela. */
    public function refName(array $entity): string
    {
        return $entity['alias'] ?? $entity['table'];
    }

    // -------------------------------------------------------------------------
    // Resolução de prefixo e de coluna
    // -------------------------------------------------------------------------

    /**
     * Entidade para um prefixo: as registradas na query ou, como último recurso, qualquer classe
     * de model (referenciada por Order::class sem estar num join). Null se não resolver.
     */
    public function entityFor(?string $prefix): ?array
    {
        if ($prefix === null || $prefix === '') {
            return $this->entities[0];
        }

        $found = $this->findRegistered($prefix);
        if ($found !== null) {
            return $found;
        }

        $class = ltrim($prefix, '\\');
        if (($table = self::tableOf($class)) !== null) {
            return ['class' => $class, 'table' => $table, 'alias' => null];
        }

        if ($this->strict && str_contains($prefix, '\\')) {
            throw new \InvalidArgumentException("Classe '{$prefix}' não encontrada ou sem #[Table]");
        }

        return null;
    }

    private function findRegistered(string $prefix): ?array
    {
        $normalized = ltrim($prefix, '\\');

        $tiers = [
            fn(array $e) => $e['alias'] === $prefix,
            fn(array $e) => $e['class'] !== null && $e['class'] === $normalized,
            fn(array $e) => $e['class'] !== null && self::shortName($e['class']) === $normalized,
            fn(array $e) => $e['table'] === $prefix,
            fn(array $e) => $e['class'] !== null && strcasecmp(self::shortName($e['class']), $normalized) === 0,
        ];

        foreach ($tiers as $matches) {
            $hits = array_values(array_filter($this->entities, $matches));

            if (count($hits) > 1 && $this->strict) {
                throw new \InvalidArgumentException(
                    "Referência ambígua '{$prefix}': mais de uma entidade na query (use um alias)"
                );
            }

            if ($hits !== []) {
                return $hits[0];
            }
        }

        return null;
    }

    private static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Nome da coluna para `$name` (propriedade ou coluna) numa entidade.
     * Propriedade primeiro; se não for propriedade, o nome é mantido como coluna literal.
     */
    public function column(array $entity, string $name): string
    {
        if ($name === '*' || $entity['class'] === null) {
            return $name;
        }

        $map = MetadataCache::getColumnMap($entity['class']);

        if (isset($map[$name])) {
            if ($this->strict) {
                // `$name` é propriedade (→ outra coluna) e também o nome da coluna de outra propriedade
                $other = array_search($name, $map, true);
                if ($map[$name] !== $name && $other !== false && $other !== $name) {
                    throw new \InvalidArgumentException(
                        "Referência ambígua '{$name}' em " . self::shortName($entity['class'])
                        . ": é a propriedade da coluna '{$map[$name]}' e a coluna da propriedade '{$other}'"
                    );
                }
            }

            return $map[$name];
        }

        if ($this->strict && !in_array($name, $map, true)) {
            throw new \InvalidArgumentException(
                "'{$name}' não é propriedade nem coluna de " . self::shortName($entity['class'])
            );
        }

        return $name;
    }

    // -------------------------------------------------------------------------
    // Fragmentos SQL crus (where em string, ON de join, expressões)
    // -------------------------------------------------------------------------

    /**
     * Traduz as referências dentro de um fragmento SQL. Literais entre aspas/backticks são
     * preservados; `Prefixo.nome` é reconhecido em qualquer posição (após `(`, `=`, `,`, dentro de
     * funções). O que não resolve é deixado como está (modo estrito: erro).
     */
    public function resolveFragment(string $sql): string
    {
        $parts = preg_split(
            '/(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`)/s',
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        foreach ($parts as $i => $part) {
            // índices ímpares são os literais capturados
            if ($i % 2 === 1 || $part === '') {
                continue;
            }

            $parts[$i] = $this->resolveSegment($part);
        }

        return implode('', $parts);
    }

    private function resolveSegment(string $segment): string
    {
        $fqcn = '\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*';

        $pattern = '/(?<![\w.:@$\\\\])(?:'
            // 1) Prefixo.nome (não é chamada de função e não tem terceiro nível)
            . '(' . $fqcn . ')\.([A-Za-z_]\w*|\*)(?![\w(\\\\.])'
            // 2) nome de classe completo solto (com namespace) → tabela
            . '|(\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)(?![\w(\\\\.])'
            // 3) palavra solta → propriedade da entidade raiz
            . '|([A-Za-z_]\w*)(?![\w.(\\\\])'
            . ')/';

        return (string) preg_replace_callback($pattern, function (array $m): string {
            if (($m[1] ?? '') !== '') {
                return $this->translateDotted($m[0], $m[1], $m[2]);
            }

            if (($m[3] ?? '') !== '') {
                return $this->translateClassName($m[0], $m[3]);
            }

            return $this->translateBareWord($m[0], $m[4] ?? $m[0]);
        }, $segment);
    }

    private function translateDotted(string $original, string $prefix, string $name): string
    {
        $entity = $this->entityFor($prefix);
        if ($entity === null) {
            return $original; // prefixo desconhecido (tabela não registrada, alias de subquery...): intocado
        }

        return $this->refName($entity) . '.' . $this->column($entity, $name);
    }

    private function translateClassName(string $original, string $class): string
    {
        $entity = $this->entityFor($class);

        return $entity === null ? $original : $this->refName($entity);
    }

    private function translateBareWord(string $original, string $word): string
    {
        if (in_array(strtoupper($word), self::SQL_WORDS, true)) {
            return $original;
        }

        $root = $this->entities[0];
        if ($root['class'] === null) {
            return $original;
        }

        $map = MetadataCache::getColumnMap($root['class']);

        // Só palavras que são propriedades da raiz com coluna de nome diferente
        if (isset($map[$word]) && $map[$word] !== $word) {
            return $this->refName($root) . '.' . $map[$word];
        }

        return $original;
    }
}
