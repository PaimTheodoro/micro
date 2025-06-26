<?php

/**
 * Arquivo de índice para todos os atributos do Model Psf
 * 
 * Este arquivo facilita o import de todos os atributos em uma única linha:
 * use \Psf\Model\Attributes\{Column, Table, PrimaryKey, Type, Standard, Enum, Nullable, ColumnCreatedDate, ColumnUpdatedDate, ColumnDeletedDate, Database};
 */

namespace Psf\Model\Attributes;

// Re-exporta todos os atributos para facilitar o uso
class_alias(Column::class, 'Psf\Model\Attributes\Column');
class_alias(Table::class, 'Psf\Model\Attributes\Table');
class_alias(PrimaryKey::class, 'Psf\Model\Attributes\PrimaryKey');
class_alias(Type::class, 'Psf\Model\Attributes\Type');
class_alias(Standard::class, 'Psf\Model\Attributes\Standard');
class_alias(Enum::class, 'Psf\Model\Attributes\Enum');
class_alias(Nullable::class, 'Psf\Model\Attributes\Nullable');
class_alias(ColumnCreatedDate::class, 'Psf\Model\Attributes\ColumnCreatedDate');
class_alias(ColumnUpdatedDate::class, 'Psf\Model\Attributes\ColumnUpdatedDate');
class_alias(ColumnDeletedDate::class, 'Psf\Model\Attributes\ColumnDeletedDate');
class_alias(Database::class, 'Psf\Model\Attributes\Database'); 