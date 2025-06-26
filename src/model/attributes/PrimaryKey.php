<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para marcar uma propriedade como chave primária.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PrimaryKey{
    public function __construct(){
    }
} 