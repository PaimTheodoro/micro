<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para mapear uma propriedade a uma coluna do banco de dados.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column{
    /**
     * @param string $name O nome da coluna no banco de dados.
     * @param string|null $type A definição completa do tipo da coluna (ex: "VARCHAR(100) NOT NULL").
     */
    public function __construct(
        public string $name,
        public ?string $type = null
    ){
    }
} 