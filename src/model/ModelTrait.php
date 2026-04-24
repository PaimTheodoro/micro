<?php

namespace Psf\Model;

trait ModelTrait{
    public static function find($startWith = NULL) : ModelQuery {
        return new ModelQuery(self::class, $startWith);
    }

    public static function findById(mixed $id) {
        $primaryKeys = \Psf\Model\Model::getPrimaryKey(self::class, 'prop');
        if(empty($primaryKeys)){
            throw new \Exception("Nenhuma chave primária encontrada na classe '" . self::class . "'.");
        }

        return (new ModelQuery(self::class))
            ->andWhere([self::class . '.' . $primaryKeys[0] => $id])
            ->one();
    }

    public function __call(string $function, array $value): mixed {
        $func = strtolower(substr($function, 0, 3));
        // Preserva camelCase: setFirstName → firstName, getFirstName → firstName
        $prop = lcfirst(substr($function, 3));

        if($func === "set"){
            $this->$prop = $value[0];
            return $this;
        }

        if($func === "get"){
            return $this->$prop ?? null;
        }

        throw new \BadMethodCallException("Método '" . $function . "' não encontrado em " . static::class . ".");
    }

    public function hasError(): bool {
        return property_exists($this, 'error') && !empty($this->error);
    }
}
