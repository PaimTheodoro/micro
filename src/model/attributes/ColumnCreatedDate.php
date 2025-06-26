<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para marcar propriedades de data de criação.
 * Automaticamente define a data/hora atual quando um registro é criado.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnCreatedDate{
    public function __construct(){
    }
} 