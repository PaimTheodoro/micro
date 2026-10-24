<?php

namespace Psf\Model;

class ModelHydrator
{
    private static function findAttributeByType($attributes, string $type): array
    {
        return MetadataCache::findAttributeByType($attributes, $type);
    }

    public static function getPropByColumn($class, $column): string|bool
    {
        return MetadataCache::getPropByColumn($class, (string) $column);
    }

    public static function getColumnByProp($class, $prop = null): string|array|bool
    {
        // Caso mais comum (uma propriedade): resolvido pelo cache, sem Reflection
        if (is_string($prop) && $prop !== '') {
            return MetadataCache::getColumnByProp($class, $prop);
        }

        $refClass = new \ReflectionClass($class);

        if (!empty($prop)) {
            if (is_array($prop)) {
                $findPropertie = array_values(array_filter(
                    $refClass->getProperties(),
                    fn($item) => in_array($item->getName(), $prop)
                ));
            } else {
                $findPropertie = array_values(array_filter(
                    $refClass->getProperties(),
                    fn($item) => $prop === $item->getName()
                ));
            }
        } else {
            $findPropertie = $refClass->getProperties();
        }

        if (!empty($findPropertie)) {
            $columns = [];
            foreach ($findPropertie as $propItem) {
                $column = self::findAttributeByType($propItem->getAttributes(), 'Column');
                if (!empty($column) && isset($column[0])) {
                    $args    = $column[0]->getArguments();
                    $colName = $args[0] ?? $args['name'] ?? null;
                    if ($colName !== null) {
                        $columns[] = $colName;
                    }
                }
            }
            return empty($prop) || is_array($prop)
                ? $columns
                : (!empty($columns) ? $columns[0] : false);
        }

        return false;
    }

    public static function propIsEnum($class, $property): mixed
    {
        $refClass     = new \ReflectionClass($class);
        $findPropEnum = array_values(array_filter(
            array_map(function ($prop) use ($property) {
                if ($prop->getName() !== $property) {
                    return false;
                }
                $enum = self::findAttributeByType($prop->getAttributes(), 'Enum');
                if (empty($enum)) {
                    return false;
                }
                $args = $enum[0]->getArguments();
                return $args[0] ?? $args['enumClass'] ?? null;
            }, $refClass->getProperties()),
            fn($v) => !empty($v)
        ));

        return empty($findPropEnum) ? false : $findPropEnum[0];
    }
}
