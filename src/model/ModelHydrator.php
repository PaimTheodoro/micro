<?php

namespace Psf\Model;

class ModelHydrator
{
    private static function isAttributeType($attribute, string $type): bool
    {
        $className = $attribute->getName();
        return substr_compare($className, $type, -strlen($type)) === 0 || $className === $type;
    }

    private static function findAttributeByType($attributes, string $type): array
    {
        return array_values(array_filter($attributes, fn($attr) => self::isAttributeType($attr, $type)));
    }

    public static function getPropByColumn($class, $column): string|bool
    {
        $refClass = new \ReflectionClass($class);
        foreach ($refClass->getProperties() as $prop) {
            $attrs = $prop->getAttributes();
            if (!empty($attrs)) {
                $findColumn = self::findAttributeByType($attrs, 'Column');
                if (!empty($findColumn) && isset($findColumn[0])) {
                    $args    = $findColumn[0]->getArguments();
                    $colName = $args[0] ?? $args['name'] ?? null;
                    if ($colName === $column) {
                        return $prop->getName();
                    }
                }
            }
        }
        return false;
    }

    public static function getColumnByProp($class, $prop = null): string|array|bool
    {
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
