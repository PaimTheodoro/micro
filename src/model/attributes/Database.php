<?php

namespace Psf\Model\Attributes;

use Attribute;

/**
 * Atributo para definir qual banco de dados usar para a classe.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Database{
    /**
     * @param string $name O nome da configuração do banco de dados (padrão: 'default').
     */
    public function __construct(
        public string $name = 'default'
    ){
    }
} 