<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para definir o tipo de dados da coluna.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Type{
    /**
     * @param string $type O tipo de dados (ex: 'timestamp', 'varchar', 'int', etc.).
     */
    public function __construct(
        public string $type
    ){
    }
} 