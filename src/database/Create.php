<?php

namespace Psf\Database;

use Psf\Database\Dialect\DialectFactory;

class Create extends Connect{
    /** Linhas por statement em Create::batch() — mantém a query dentro de limites seguros de placeholders/packet do MySQL mesmo para chamadores que não limitam o array de entrada. */
    private const BATCH_CHUNK_SIZE = 500;

    private $table;
    private $data;
    private $result;
    private $create;
    private $connection;
    private $database;

    public static function exe(string $table, array $data, $database = 'default'){
        $configDb = \PSF::getConfig()->db;

        $obj = new Create;

        if(empty(self::$connection)){
            $obj->connection = parent::getConnection($database);
        }

        $obj->table = (String) $table;
        $obj->data = $data;
        
        if(self::verifyTableExist($table, $database)){
            $dialect = DialectFactory::fromConfig($configDb[$database]);
            $fields  = implode(', ', array_map(
                fn($col) => $dialect->quoteIdentifier($col),
                array_keys($obj->data)
            ));
            $places = ':' . implode(', :', array_keys($obj->data));

            $obj->create = "INSERT INTO " . $dialect->quoteIdentifier($obj->table) . " ({$fields}) VALUES ({$places})";
            $obj->create = $obj->connection->prepare($obj->create);

            try{
                $obj->create->execute($obj->data);
                $obj->result = $obj->connection->lastInsertId();

                return $obj;
            }catch(\PDOException $e){
                if($obj->connection->inTransaction()){
                    $obj->connection->rollBack();
                }
                throw $e;
            }
        }
    }

    /**
     * Insere várias linhas em uma única query (um `INSERT INTO ... VALUES (...), (...), ...`
     * por chunk de BATCH_CHUNK_SIZE linhas), em vez de um `INSERT` + round-trip por linha
     * como `exe()`. Todas as linhas de `$rows` devem ter o mesmo conjunto de colunas (as
     * colunas são lidas da primeira linha).
     *
     * Não retorna os IDs individuais inseridos — `getResult()` aqui é o total de linhas
     * inseridas, não um `lastInsertId()`. Para casos que precisam do ID por linha, use `exe()`.
     */
    public static function batch(string $table, array $rows, string $database = 'default'){
        if(empty($rows)){
            return false;
        }

        $configDb = \PSF::getConfig()->db;

        $obj = new Create;
        $obj->connection = parent::getConnection($database);
        $obj->table = (string) $table;

        if(!self::verifyTableExist($table, $database)){
            return false;
        }

        $dialect = DialectFactory::fromConfig($configDb[$database]);
        $columns = array_keys(reset($rows));
        $quotedColumns = implode(', ', array_map(
            fn($col) => $dialect->quoteIdentifier($col),
            $columns
        ));
        $quotedTable = $dialect->quoteIdentifier($obj->table);

        $inserted = 0;

        try{
            foreach(array_chunk($rows, self::BATCH_CHUNK_SIZE) as $chunk){
                $rowPlaceholderGroups = [];
                $bindings = [];

                foreach(array_values($chunk) as $rowIndex => $row){
                    $rowPlaceholders = [];

                    foreach(array_values($columns) as $colIndex => $col){
                        $paramName = "r{$rowIndex}_c{$colIndex}";
                        $rowPlaceholders[] = ":{$paramName}";
                        $bindings[$paramName] = $row[$col] ?? null;
                    }

                    $rowPlaceholderGroups[] = '(' . implode(', ', $rowPlaceholders) . ')';
                }

                $sql = "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES " . implode(', ', $rowPlaceholderGroups);
                $statement = $obj->connection->prepare($sql);
                $statement->execute($bindings);

                $inserted += $statement->rowCount();
            }
        }catch(\PDOException $e){
            if($obj->connection->inTransaction()){
                $obj->connection->rollBack();
            }
            throw $e;
        }

        $obj->result = $inserted;

        return $obj;
    }

    public function getResult(){
        return $this->result;
    }
}