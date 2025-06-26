<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para marcar propriedades de data de atualização.
 * Automaticamente define a data/hora atual quando um registro é atualizado.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnUpdatedDate{
    public function __construct(){
    }
} 