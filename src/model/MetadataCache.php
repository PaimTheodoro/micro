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
     * Verifica se um atributo é do tipo especificado
     */
    private static function isAttributeType($attribute, string $type): bool {
        $className = $attribute->getName();
        // Usa substr_compare para compatibilidade com PHP < 8.0
        return substr_compare($className, $type, -strlen($type)) === 0 || $className === $type;
    }

    /**
     * Encontra um atributo do tipo especificado
     */
    private static function findAttributeByType($attributes, string $type) {
        return array_values(array_filter($attributes, function($attr) use ($type) {
            return self::isAttributeType($attr, $type);
        }));
    }

    /**
     * Busca um valor do cache. Se não existir, o produz usando o callable,
     * armazena o resultado e o retorna.
     */
    private static function fetch(string $class, string $key, callable $producer){
        if (isset(self::$cache[$class][$key])) {
            return self::$cache[$class][$key];
        }

        return self::$cache[$class][$key] = $producer();
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
     * Obtém todo o mapa de colunas para uma classe, usando o cache.
     */
    public static function getColumnMap(string $class): array{
        return self::fetch($class, 'columns', fn() => Model::getColumnByProp($class));
    }

    /**
     * Obtém as chaves primárias de uma classe, usando o cache.
     */
    public static function getPrimaryKey(string $class): array{
        return self::fetch($class, 'primary_key', fn() => Model::getPrimaryKey($class));
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
     * Limpa o cache para uma classe específica ou todo o cache.
     */
    public static function clearCache(?string $class = null): void{
        if ($class !== null) {
            unset(self::$cache[$class]);
        } else {
            self::$cache = [];
        }
    }
} 