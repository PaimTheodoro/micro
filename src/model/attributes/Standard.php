<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para definir valores padrão para propriedades.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Standard{
    /**
     * @param mixed $value O valor padrão (pode ser um enum, string, ou constante).
     */
    public function __construct(
        public mixed $value
    ){
    }
} 