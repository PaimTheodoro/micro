<?php

namespace Psf\Model;

class QueryHydrator
{
    private string $rootClass;

    public function __construct(string $rootClass)
    {
        $this->rootClass = $rootClass;
    }

    public function queryResult($Read, array $query): object|bool|array|int
    {
        if ($query['isCount'] === true) {
            return $Read->getResult()[0]['qtd'] ?? 0;
        }

        if ($Read->getRowCount() == 0) {
            // ->one() (limit 1) mantém o contrato documentado "false = nada encontrado".
            // Qualquer outra chamada multi-linha (->all(), sem limit ou limit > 1) precisa
            // devolver array vazio — devolver false aqui quebra todo `foreach($x->all() as ...)`
            // que não checa o retorno antes de iterar.
            return $query['limit'] == 1 ? false : [];
        }

        $result          = $Read->getResult();
        $joinsWithSelect = array_values(array_filter(
            $query['leftJoins'] ?? [],
            fn($join) => !empty($join['andSelect'])
        ));

        if (!empty($joinsWithSelect)) {
            $result = $this->hydrateWithJoinCollections($result, $joinsWithSelect, $query['asArray'] === true);
        } else {
            foreach ($result as &$item) {
                $item = Model::serializeData($this->rootClass, $item, $query['asArray'], $query['leftJoins']);
            }
        }

        if ($Read->getRowCount() == 1 && $query['limit'] == 1) {
            return $query['asArray'] === true ? (array) $result[0] : $result[0];
        } elseif ($Read->getRowCount() >= 1) {
            return $result;
        }

        return false;
    }

    private function hydrateWithJoinCollections(array $rows, array $joinsWithSelect, bool $asArray): array
    {
        $rootClass      = $this->rootClass;
        $rootColumnsMap = MetadataCache::getColumnMap($rootClass);
        $rootColumns    = array_values($rootColumnsMap);

        $joinMeta = array_values(array_filter(array_map(function ($join) {
            $relationClass = is_array($join['table']) ? $join['table'][0] : $join['table'];
            if (!class_exists($relationClass)) {
                return null;
            }

            $columnsMap = MetadataCache::getColumnMap($relationClass);
            $columns    = array_values($columnsMap);

            return [
                'attr'         => $join['andSelect'],
                'parts'        => explode('.', $join['andSelect']),
                'prefix'       => str_replace('.', '#', $join['andSelect']) . '_',
                'relationClass' => $relationClass,
                'columns'      => $columns,
                'columnToProp' => array_flip($columnsMap),
            ];
        }, $joinsWithSelect), fn($meta) => $meta !== null));

        if (empty($joinMeta)) {
            return array_map(fn($row) => Model::serializeData($rootClass, $row, $asArray, []), $rows);
        }

        $grouped        = [];
        $relationsSeen  = [];

        foreach ($rows as $row) {
            $rootSignatureData = [];
            foreach ($rootColumns as $column) {
                $rootSignatureData[$column] = $row[$column] ?? null;
            }
            $signature = md5(json_encode($rootSignatureData));

            if (!isset($grouped[$signature])) {
                if ($asArray) {
                    $rootEntityArray = Model::serializeData($rootClass, $row, true, []);
                    foreach ($joinMeta as $meta) {
                        foreach ($meta['columns'] as $column) {
                            unset($rootEntityArray[$meta['prefix'] . $column]);
                        }
                    }
                    $grouped[$signature] = $rootEntityArray;
                } else {
                    $grouped[$signature] = Model::serializeData($rootClass, $row, false, []);
                }

                foreach ($joinMeta as $meta) {
                    $this->setPathValue($grouped[$signature], $meta['parts'], []);
                }
            }

            foreach ($joinMeta as $meta) {
                $relationPayload = $this->extractRelationPayload($row, $meta);
                if ($relationPayload === null) {
                    continue;
                }

                $relationUniq = md5(json_encode($relationPayload['byProp']));
                $relationPath = implode('.', $meta['parts']);

                if (!isset($relationsSeen[$signature][$relationPath][$relationUniq])) {
                    $relationsSeen[$signature][$relationPath][$relationUniq] = true;

                    $itemToAppend = $asArray
                        ? $relationPayload['byProp']
                        : Model::serializeData($meta['relationClass'], $relationPayload['byColumn'], false, []);

                    $this->appendPathListValue($grouped[$signature], $meta['parts'], $itemToAppend);
                }
            }
        }

        return array_values($grouped);
    }

    private function extractRelationPayload(array $row, array $meta): ?array
    {
        $byProp   = [];
        $byColumn = [];
        $hasValue = false;

        foreach ($meta['columns'] as $column) {
            $alias = $meta['prefix'] . $column;
            if (!array_key_exists($alias, $row)) {
                continue;
            }
            $value = $row[$alias];
            if ($value !== null) {
                $hasValue = true;
            }
            $prop          = $meta['columnToProp'][$column] ?? $column;
            $byProp[$prop] = $value;
            $byColumn[$column] = $value;
        }

        if (!$hasValue) {
            return null;
        }

        return ['byProp' => $byProp, 'byColumn' => $byColumn];
    }

    private function setPathValue(&$target, array $parts, $value): void
    {
        $this->traverseNestedPath($target, $parts, static function (&$leaf, string $key, bool $isArray) use ($value) {
            if ($isArray) {
                $leaf[$key] = $value;
            } else {
                $leaf->{$key} = $value;
            }
        });
    }

    private function appendPathListValue(&$target, array $parts, $value): void
    {
        $this->traverseNestedPath($target, $parts, static function (&$leaf, string $key, bool $isArray) use ($value) {
            if ($isArray) {
                if (!isset($leaf[$key]) || !is_array($leaf[$key])) {
                    $leaf[$key] = [];
                }
                $leaf[$key][] = $value;
            } else {
                if (!isset($leaf->{$key}) || !is_array($leaf->{$key})) {
                    $leaf->{$key} = [];
                }
                $leaf->{$key}[] = $value;
            }
        });
    }

    /**
     * Traverses a nested array or object structure following $parts as path segments,
     * creating intermediate nodes as needed, then calls $operation on the parent of the
     * last segment so the caller can set or append without knowing the container type.
     */
    private function traverseNestedPath(&$target, array $parts, callable $operation): void
    {
        if (empty($parts)) {
            return;
        }

        $isArray = is_array($target);
        $last    = count($parts) - 1;

        if ($isArray) {
            $current =& $target;
            foreach ($parts as $index => $part) {
                if ($index === $last) {
                    $operation($current, $part, true);
                    return;
                }
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current =& $current[$part];
            }
        } else {
            $current = $target;
            foreach ($parts as $index => $part) {
                if ($index === $last) {
                    $operation($current, $part, false);
                    return;
                }
                if (!isset($current->{$part}) || !is_object($current->{$part})) {
                    $current->{$part} = new \stdClass();
                }
                $current = $current->{$part};
            }
        }
    }
}
