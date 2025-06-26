<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para definir o nome da tabela no banco de dados.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Table{
    /**
     * @param string $name O nome da tabela no banco de dados.
     */
    public function __construct(
        public string $name
    ){
    }
} 