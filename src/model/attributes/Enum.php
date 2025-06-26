<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para definir a classe enum associada a uma propriedade.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Enum{
    /**
     * @param string $enumClass O nome da classe enum.
     */
    public function __construct(
        public string $enumClass
    ){
    }
} 