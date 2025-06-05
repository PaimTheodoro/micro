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
    public static function getColumnByProp(string $class, string $prop): ?string{
        $columns = self::getColumnMap($class);
        return $columns[$prop] ?? $prop;
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
                $attributes = $reflectionProp->getAttributes(Column::class);
                if (count($attributes) > 0) {
                    // Retorna o argumento 'type' se ele foi passado, caso contrário null.
                    return $attributes[0]->newInstance()->type;
                }
            } catch (\ReflectionException $e) {
                return null;
            }
            return null;
        });
    }
} 