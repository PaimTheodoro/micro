<?php

namespace Psf\Database;

class Delete extends Connect{
    private $table;
    private $terms;
    private $places;
    private $result;
    private $delete;
    private $connection;
    private $database;

    public static function exe($table, $terms, $parseString = null, $database = 'default', array $termsParams = []){
        $obj = new Delete;

        if(empty(self::$connection)){
            $obj->connection = parent::getConnection($database);
        }

        $obj->table = (string) $table;
        $obj->terms = (string) $terms;

        if(self::verifyTableExist($table, $database)){
            parse_str($parseString, $obj->places);

            $obj->delete = "DELETE FROM {$obj->table} {$obj->terms}";

            try{
                $obj->delete = $obj->connection->prepare($obj->delete);

                $obj->delete->execute(array_merge($obj->places, $termsParams));
                $obj->result = true;

                return $obj;
            }catch (\PDOException $e){
                if($obj->connection->inTransaction()){
                    $obj->connection->rollBack();
                }

                $obj->result = null;
                explodeException($e);

                return false;
            }
        }
    }
    
    public function getResult(){
        return $this->delete;
    }
    
    public function getRowCount(){
        return $this->delete->rowCount();
    }
}