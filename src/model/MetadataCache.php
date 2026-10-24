<?php

namespace Psf\Model;

use Psf\Model\Attributes\Column;

/**
 * Cache para metadados de classes Model.
 *
 * Armazena em um array estático os resultados da análise por Reflection
 * (nomes de tabelas, mapeamento de colunas, etc.), evitando a sobrecarga
 * de repetir essa análise a cada chamada. O cache dura o tempo da requisição.
 */
class MetadataCache{
    /**
     * @var array Cache estático para armazenar os metadados.
     */
    private static array $cache = [];

    /**
     * @var array<string, string> Versão (mtime do arquivo da classe) usada na chave do APCu,
     * para que um deploy invalide os metadados antigos em vez de servi-los até o TTL.
     */
    private static array $classVersions = [];

    private static function apcuKey(string $class, string $key): string
    {
        if (!isset(self::$classVersions[$class])) {
            $file = class_exists($class) ? (new \ReflectionClass($class))->getFileName() : false;
            self::$classVersions[$class] = $file !== false ? (string) @filemtime($file) : '0';
        }

        return 'psf_meta_' . md5($class . '|' . self::$classVersions[$class]) . '_' . $key;
    }

    /**
     * Verifica se um atributo é do tipo especificado (pelo sufixo do nome da classe,
     * para aceitar tanto `Column` quanto `Psf\Model\Attributes\Column`).
     */
    private static function isAttributeType($attribute, string $type): bool {
        $className = $attribute->getName();
        return substr_compare($className, $type, -strlen($type)) === 0 || $className === $type;
    }

    /**
     * Filtra os atributos (ReflectionAttribute[]) do tipo especificado.
     * Implementação única — Model, ModelSerializer e ModelHydrator delegam para cá.
     */
    public static function findAttributeByType($attributes, string $type): array {
        return array_values(array_filter($attributes, function($attr) use ($type) {
            return self::isAttributeType($attr, $type);
        }));
    }

    /**
     * Busca um valor do cache. L1 = array estático (por requisição). L2 = APCu (cross-request).
     * Se não existir em nenhum nível, produz o valor via $producer, armazena em ambos e retorna.
     */
    private static function fetch(string $class, string $key, callable $producer): mixed
    {
        // L1 — memória da requisição atual (instantâneo, sem serialização)
        // array_key_exists (não isset) para que resultados null/false também sejam cacheados
        if (isset(self::$cache[$class]) && array_key_exists($key, self::$cache[$class])) {
            return self::$cache[$class][$key];
        }

        // L2 — APCu cross-request (quando disponível e habilitado)
        $apcu        = extension_loaded('apcu') && apcu_enabled();
        $apcuKey     = $apcu ? self::apcuKey($class, $key) : '';

        if ($apcu) {
            $value = apcu_fetch($apcuKey, $success);
            if ($success) {
                return self::$cache[$class][$key] = $value;
            }
        }

        $value = $producer();
        self::$cache[$class][$key] = $value;

        if ($apcu) {
            apcu_store($apcuKey, $value, 3600);
        }

        return $value;
    }

    /**
     * Obtém o nome da tabela para uma classe, usando o cache se disponível.
     */
    public static function getTable(string $class): string{
        return self::fetch($class, 'table', fn() => Model::getTable($class));
    }

    /**
     * Obtém o nome da coluna para uma propriedade, usando o cache.
     */
    public static function getColumnByProp($class, $prop = null) : array|string|false {
        return self::fetch($class, 'columns_' . ($prop ?? 'all'), function() use ($class, $prop) {
            $refClass = new \ReflectionClass($class);
            $columns = [];
            foreach($refClass->getProperties() as $property){
                $attributes = $property->getAttributes();
                $column = self::findAttributeByType($attributes, 'Column');
                if(!empty($column) && isset($column[0])){
                    $args = $column[0]->getArguments();
                    if (isset($args[0])) {
                        $colName = $args[0];
                    } elseif (isset($args['name'])) {
                        $colName = $args['name'];
                    } else {
                        $colName = null;
                    }
                    $columns[$property->getName()] = $colName;
                }
            }
            if ($prop !== null) {
                // Retorna o nome da coluna para a propriedade específica, ou false se não existir
                return $columns[$prop] ?? false;
            }
            // Retorna o array associativo completo
            return $columns;
        });
    }

    /**
     * Plano de hidratação: uma entrada por propriedade da classe (mesma ordem e conjunto de
     * ReflectionClass::getProperties()), com o que serializer/hydrator precisam por linha —
     * assim a Reflection roda uma vez por classe, não uma vez por linha de resultado.
     *
     * @return list<array{prop: string, hasColumn: bool, column: ?string, static: bool}>
     */
    public static function getPropertyPlan(string $class): array {
        return self::fetch($class, 'property_plan', function () use ($class) {
            $plan = [];
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                $column  = self::findAttributeByType($property->getAttributes(), 'Column');
                $colName = null;
                if (!empty($column)) {
                    $args    = $column[0]->getArguments();
                    $colName = $args[0] ?? $args['name'] ?? null;
                }

                $plan[] = [
                    'prop'      => $property->getName(),
                    'hasColumn' => !empty($column),
                    'column'    => $colName,
                    'static'    => $property->isStatic(),
                ];
            }
            return $plan;
        });
    }

    /**
     * Nome da propriedade mapeada para uma coluna (a primeira, se houver duplicidade), ou false.
     */
    public static function getPropByColumn(string $class, string $column): string|false {
        $reverse = self::fetch($class, 'column_to_prop', function () use ($class) {
            $map = [];
            foreach (self::getPropertyPlan($class) as $entry) {
                if ($entry['column'] !== null && !isset($map[$entry['column']])) {
                    $map[$entry['column']] = $entry['prop'];
                }
            }
            return $map;
        });

        return $reverse[$column] ?? false;
    }

    /**
     * Obtém todo o mapa de colunas para uma classe, usando o cache.
     * Retorna ['nomeDaPropriedade' => 'nome_da_coluna_no_banco'].
     */
    public static function getColumnMap(string $class): array{
        return self::getColumnByProp($class) ?: [];
    }

    /**
     * Obtém as chaves primárias de uma classe, usando o cache.
     */
    public static function getPrimaryKey(string $class): array{
        return self::fetch($class, 'primary_key', fn() => Model::getPrimaryKey($class));
    }
    
    /**
     * Nome da PROPRIEDADE marcada com #[ColumnDeletedDate] (soft delete), ou null se o model não usa.
     */
    public static function getSoftDeleteProp(string $class): ?string{
        return self::fetch($class, 'soft_delete_prop', function () use ($class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                if (!empty(self::findAttributeByType($property->getAttributes(), 'ColumnDeletedDate'))) {
                    return $property->getName();
                }
            }
            return null;
        });
    }

    /**
     * Obtém o nome do banco de dados para uma classe, usando o cache se disponível.
     */
    public static function getDatabase(string $class): string{
        return self::fetch($class, 'database', fn() => Model::getDatabase($class) ?? 'default');
    }

    /**
     * Obtém o tipo da coluna para uma propriedade, usando o cache.
     */
    public static function getColumnType(string $class, string $prop): ?string{
        return self::fetch($class, 'col_type_' . $prop, function () use ($class, $prop) {
            try {
                $reflectionProp = new \ReflectionProperty($class, $prop);
                $attributes = $reflectionProp->getAttributes();
                
                // Usa o método auxiliar local para encontrar o atributo Type
                $typeAttribute = self::findAttributeByType($attributes, 'Type');
                if (!empty($typeAttribute) && isset($typeAttribute[0])) {
                    $args = $typeAttribute[0]->getArguments();
                    if (isset($args[0])) {
                        return $args[0];
                    } elseif (isset($args['type'])) {
                        return $args['type'];
                    }
                }
                
                // Se não encontrar Type, tenta buscar no atributo Column
                $columnAttribute = self::findAttributeByType($attributes, 'Column');
                if (!empty($columnAttribute)) {
                    $columnInstance = $columnAttribute[0]->newInstance();
                    return $columnInstance->type;
                }
                
            } catch (\ReflectionException $e) {
                return null;
            }
            return null;
        });
    }

    /**
     * Obtém o valor padrão de uma propriedade, usando o cache.
     */
    public static function getDefaultValue(string $class, string $prop): mixed{
        return self::fetch($class, 'default_' . $prop, function () use ($class, $prop) {
            try {
                $reflectionProp = new \ReflectionProperty($class, $prop);
                $attributes = $reflectionProp->getAttributes();
                
                // Usa o método auxiliar local para encontrar o atributo Standard
                $standardAttribute = self::findAttributeByType($attributes, 'Standard');
                if (!empty($standardAttribute) && isset($standardAttribute[0])) {
                    $args = $standardAttribute[0]->getArguments();
                    if (isset($args[0])) {
                        return $args[0];
                    } elseif (isset($args['value'])) {
                        return $args['value'];
                    }
                }
                
            } catch (\ReflectionException $e) {
                return null;
            }
            return null;
        });
    }

    /**
     * Verifica se uma propriedade é nullable, usando o cache.
     */
    public static function isNullable(string $class, string $prop): bool{
        return self::fetch($class, 'nullable_' . $prop, function () use ($class, $prop) {
            try {
                $reflectionProp = new \ReflectionProperty($class, $prop);
                $attributes = $reflectionProp->getAttributes();
                
                // Usa o método auxiliar local para encontrar o atributo Nullable
                $nullableAttribute = self::findAttributeByType($attributes, 'Nullable');
                if (!empty($nullableAttribute) && isset($nullableAttribute[0])) {
                    $args = $nullableAttribute[0]->getArguments();
                    if (isset($args[0])) {
                        return $args[0] ?? true;
                    } elseif (isset($args['nullable'])) {
                        return $args['nullable'] ?? true;
                    }
                }
                
            } catch (\ReflectionException $e) {
                return true; // Por padrão, assume que é nullable
            }
            return true; // Por padrão, assume que é nullable
        });
    }

    /**
     * Obtém a classe enum de uma propriedade, usando o cache.
     */
    public static function getEnumClass(string $class, string $prop): ?string{
        return self::fetch($class, 'enum_' . $prop, function () use ($class, $prop) {
            try {
                $reflectionProp = new \ReflectionProperty($class, $prop);
                $attributes = $reflectionProp->getAttributes();
                
                // Usa o método auxiliar local para encontrar o atributo Enum
                $enumAttribute = self::findAttributeByType($attributes, 'Enum');
                if (!empty($enumAttribute) && isset($enumAttribute[0])) {
                    $args = $enumAttribute[0]->getArguments();
                    if (isset($args[0])) {
                        return $args[0];
                    } elseif (isset($args['enumClass'])) {
                        return $args['enumClass'];
                    }
                }
                
            } catch (\ReflectionException $e) {
                return null;
            }
            return null;
        });
    }

    /**
     * Verifica se uma propriedade tem um atributo específico, usando o cache.
     */
    public static function hasAttribute(string $class, string $prop, string $attributeType): bool{
        return self::fetch($class, 'has_' . $attributeType . '_' . $prop, function () use ($class, $prop, $attributeType) {
            try {
                $reflectionProp = new \ReflectionProperty($class, $prop);
                $attributes = $reflectionProp->getAttributes();
                
                // Usa o método auxiliar local para verificar se tem o atributo
                $foundAttribute = self::findAttributeByType($attributes, $attributeType);
                return !empty($foundAttribute);
                
            } catch (\ReflectionException $e) {
                return false;
            }
        });
    }

    /**
     * Limpa o cache L1 (e APCu L2 quando disponível) para uma classe específica ou tudo.
     */
    public static function clearCache(?string $class = null): void
    {
        $apcu = extension_loaded('apcu') && apcu_enabled();

        if ($class !== null) {
            if ($apcu) {
                // Limpa todas as entradas APCu daquela classe
                foreach (array_keys(self::$cache[$class] ?? []) as $key) {
                    apcu_delete(self::apcuKey($class, $key));
                }
            }
            unset(self::$cache[$class], self::$classVersions[$class]);
        } else {
            if ($apcu) {
                foreach (self::$cache as $cls => $keys) {
                    foreach (array_keys($keys) as $key) {
                        apcu_delete(self::apcuKey($cls, $key));
                    }
                }
            }
            self::$cache = [];
            self::$classVersions = [];
        }
    }
} 