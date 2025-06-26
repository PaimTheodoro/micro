<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para definir se uma propriedade pode ser nula.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Nullable{
    /**
     * @param bool $nullable Se a propriedade pode ser nula (padrão: true).
     */
    public function __construct(
        public bool $nullable = true
    ){
    }
} 