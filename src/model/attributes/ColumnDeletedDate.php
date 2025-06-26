<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para marcar propriedades de data de exclusão (soft delete).
 * Automaticamente define a data/hora atual quando um registro é marcado como deletado.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnDeletedDate{
    public function __construct(){
    }
} 