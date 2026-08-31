<?php

namespace Psf\Model;

use \Psf\Database\Create;

trait ModelTrait{
    public static function find($startWith = NULL) : ModelQuery {
        return new ModelQuery(self::class, $startWith);
    }

    /**
     * Insere várias instâncias da mesma classe em uma única (ou poucas, se houver
     * chunking) query, via Create::batch(), em vez de uma chamada de `->create()`
     * por instância. Pensado para pontos de alta cardinalidade (ex.: ingestão de
     * eventos de tracking em lote) — para o caso comum (1 registro), `->create()`
     * continua sendo o caminho certo.
     *
     * Diferente de `->create()`, não preenche o `id` de volta em cada instância
     * (um `INSERT` multi-linha só devolve um `lastInsertId()`, não um por linha).
     */
    public static function createMany(array $instances): bool {
        if(empty($instances)){
            return true;
        }

        $rows = array_map(
            fn($instance) => Model::serializeFields($instance, TRUE),
            $instances
        );

        $result = Create::batch(
            table: Model::getTable(self::class),
            rows: $rows,
            database: Model::getDatabase(self::class)
        );

        return !empty($result);
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
