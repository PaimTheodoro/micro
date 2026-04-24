<?php

namespace Psf\Model;

use Psf\Helper\UUID;

class ModelSerializer
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

    public static function serializeFields($object, $removePrimarys = false): array
    {
        $refClass = new \ReflectionClass($object::class);
        $fields   = [];

        foreach ($refClass->getProperties() as $property) {
            $attributes = $property->getAttributes();
            $column     = self::findAttributeByType($attributes, 'Column');

            if (empty($column)) {
                continue;
            }

            $args    = $column[0]->getArguments();
            $colName = $args[0] ?? $args['name'] ?? null;
            $column  = $colName;

            if ($removePrimarys) {
                $isPrimaryKey = self::findAttributeByType($attributes, 'PrimaryKey');
                if (!empty($isPrimaryKey)) {
                    continue;
                }
            }

            $typeValue = self::findAttributeByType($attributes, 'Type');
            if (!empty($typeValue) && isset($typeValue[0])) {
                $tArgs     = $typeValue[0]->getArguments();
                $typeValue = $tArgs[0] ?? $tArgs['type'] ?? null;
            } else {
                $typeValue = null;
            }

            $standardValue = self::findAttributeByType($attributes, 'Standard');
            if (!empty($standardValue) && isset($standardValue[0]) && empty($object->{$property->getName()})) {
                $sArgs    = $standardValue[0]->getArguments();
                $stdValue = $sArgs[0] ?? $sArgs['value'] ?? null;

                if ($stdValue !== null) {
                    if (is_numeric($stdValue)) {
                        $object->{$property->getName()} = $stdValue;
                    } elseif (property_exists($stdValue, 'value')) {
                        $object->{$property->getName()} = $stdValue->value;
                    } else {
                        if (strtoupper($stdValue) === 'NOW()') {
                            $object->{$property->getName()} = date('Y-m-d H:i:s');
                        } elseif (strtoupper($stdValue) === 'UUIDV4') {
                            $object->{$property->getName()} = UUID::generate('4');
                        } else {
                            $object->{$property->getName()} = $stdValue;
                        }
                    }
                    $fields[$column] = $object->{$property->getName()};
                }
            }

            $columnCreatedDate = self::findAttributeByType($attributes, 'ColumnCreatedDate');
            if (!empty($columnCreatedDate) && empty($object->{$property->getName()})) {
                $newValue = in_array($typeValue, ['timestamp', 'bigint']) ? time() : date('Y-m-d H:i:s');
                $object->{$property->getName()} = $newValue;
                $fields[$column] = $newValue;
            }

            $columnUpdatedDate = self::findAttributeByType($attributes, 'ColumnUpdatedDate');
            if (!empty($columnUpdatedDate) && empty($object->{$property->getName()})) {
                $newValue = in_array($typeValue, ['timestamp', 'bigint']) ? time() : date('Y-m-d H:i:s');
                $object->{$property->getName()} = $newValue;
                $fields[$column] = $newValue;
            }

            $required = self::findAttributeByType($attributes, 'Nullable');
            if (!empty($required) && isset($required[0])) {
                $rArgs    = $required[0]->getArguments();
                $required = $rArgs[0] ?? $rArgs['nullable'] ?? true;
            }

            if ($required === false && empty($object->{$property->getName()})) {
                throw new \Exception("O campo '" . $property->getName() . "' não pode ser nulo");
            } elseif (!empty($required)) {
                $fields[$column] = $object->{$property->getName()};
            }

            if ((!empty($object->{$property->getName()}) || $object->{$property->getName()} == 0) && empty($fields[$column])) {
                $fields[$column] = $object->{$property->getName()};
            }
        }

        return $fields;
    }

    public static function serializeData($class, array $data, bool $asArray = false, null|array $joins = []): object|array|null
    {
        $refClass = new \ReflectionClass($class);

        if (!$asArray) {
            $response = new $class;

            foreach ($refClass->getProperties() as $property) {
                $attributes = $property->getAttributes();
                $column     = self::findAttributeByType($attributes, 'Column');

                if (!empty($column)) {
                    $args       = $column[0]->getArguments();
                    $columnName = $args[0] ?? $args['name'] ?? null;
                    $response->{$property->getName()} = (!empty($data[$columnName]) || (isset($data[$columnName]) && $data[$columnName] == 0))
                        ? $data[$columnName]
                        : null;
                } else {
                    if (!empty($joins)) {
                        $findJoinForProperty = array_filter(
                            $joins,
                            fn($item) => !empty($item['andSelect']) && $item['andSelect'] == $property->getName()
                        );

                        if (!empty($findJoinForProperty)) {
                            $findJoinForProperty = reset($findJoinForProperty);
                            foreach ($data as $key => $value) {
                                if (str_starts_with($key, $findJoinForProperty['andSelect'] . '_')) {
                                    $classRelation = is_array($findJoinForProperty['table'])
                                        ? $findJoinForProperty['table'][0]
                                        : $findJoinForProperty['table'];

                                    if (!is_object($response->{$findJoinForProperty['andSelect']})) {
                                        $response->{$findJoinForProperty['andSelect']} = new $classRelation;
                                    }

                                    $objectField = explode('_', $key);
                                    unset($objectField[0]);
                                    $getColumnRelation = ModelHydrator::getPropByColumn($classRelation, implode('_', $objectField));
                                    if (!empty($getColumnRelation)) {
                                        $response->{$findJoinForProperty['andSelect']}->{$getColumnRelation} = $value;
                                    }
                                }
                            }
                        }
                    }

                    if (isset($data[$property->getName()]) && !$property->isStatic()) {
                        $response->{$property->getName()} = $data[$property->getName()];
                    }
                }
            }

            return $response;
        }

        // asArray mode
        $response = [];

        foreach (array_keys($data) as $key) {
            $findColumnExist = array_values(array_filter(
                $refClass->getProperties(),
                function ($prop) use ($key) {
                    $column = self::findAttributeByType($prop->getAttributes(), 'Column');
                    if (!empty($column) && isset($column[0])) {
                        $args    = $column[0]->getArguments();
                        $colName = $args[0] ?? $args['name'] ?? null;
                        return $colName === $key;
                    }
                    return false;
                }
            ));

            if (!empty($findColumnExist)) {
                $response[$findColumnExist[0]->getName()] = $data[$key];
                // enum coercion hook (intentionally empty — handled by callers when needed)
                ModelHydrator::propIsEnum($class, ModelHydrator::getPropByColumn($class, $key));
            } else {
                if (!empty($joins)) {
                    $findJoinForProperty = array_filter($joins, fn($item) => !empty($item['andSelect']) && $item['andSelect']);

                    if (!empty($findJoinForProperty)) {
                        $findJoinForProperty = reset($findJoinForProperty);

                        if (str_starts_with($key, $findJoinForProperty['andSelect'] . '_')) {
                            $classRelation = is_array($findJoinForProperty['table'])
                                ? $findJoinForProperty['table'][0]
                                : $findJoinForProperty['table'];

                            $objectField = explode('_', $key);
                            unset($objectField[0]);
                            $getColumnRelation = ModelHydrator::getPropByColumn($classRelation, implode('_', $objectField));
                            if (!empty($getColumnRelation)) {
                                $response[$findJoinForProperty['andSelect']][$getColumnRelation] = $data[$key];
                                continue;
                            }
                        } elseif (str_starts_with($key, $findJoinForProperty['andSelect'] . '#')) {
                            $classRelation = is_array($findJoinForProperty['table'])
                                ? $findJoinForProperty['table'][0]
                                : $findJoinForProperty['table'];

                            $objectField    = explode('#', $key);
                            $newFields      = $objectField;
                            unset($newFields[0]);
                            $newFields        = implode('_', $newFields);
                            $objectFieldAttr  = explode('_', $newFields);

                            $findJoinForPropertyTwo = array_values(array_filter(
                                $joins,
                                fn($item) => !empty($item['andSelect']) && ($item['andSelect'] == ($objectField[0] . '.' . $objectFieldAttr[0]))
                            ));

                            if ($findJoinForPropertyTwo) {
                                $findJoinForPropertyTwo = $findJoinForPropertyTwo[0];
                                $classRelationTwo       = is_array($findJoinForPropertyTwo['table'])
                                    ? $findJoinForPropertyTwo['table'][0]
                                    : $findJoinForPropertyTwo['table'];

                                $fieldTwo = $objectFieldAttr[0];
                                unset($objectFieldAttr[0]);
                                $objectFieldAttr   = array_values($objectFieldAttr);
                                $getColumnRelation = ModelHydrator::getPropByColumn($classRelationTwo, implode('_', $objectFieldAttr));

                                if (!empty($getColumnRelation)) {
                                    $response[$findJoinForProperty['andSelect']][$fieldTwo][$getColumnRelation] = $data[$key];
                                    continue;
                                }
                            }
                        }
                    }
                }

                $response[$key] = $data[$key];
            }
        }

        return $response;
    }
}
