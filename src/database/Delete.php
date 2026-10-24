<?php

namespace Psf\Database;

use Psf\Database\Dialect\DialectFactory;

class Delete extends Connect{
    private $table;
    private $terms;
    private $places;
    private $result;
    private $delete;
    private $connection;
    private $database;

    public static function exe($table, $terms, $parseString = '', $database = 'default', array $termsParams = []){
        $obj = new Delete;

        $obj->connection = parent::getConnection($database);

        $obj->table = (string) $table;
        $obj->terms = (string) $terms;

        if(self::verifyTableExist($table, $database)){
            parse_str($parseString, $obj->places);

            $dialect = DialectFactory::fromConfig(\PSF::getConfig()->db[$database]);
            $obj->delete = "DELETE FROM " . $dialect->quoteIdentifier($obj->table) . " {$obj->terms}";

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
        return $this->result;
    }
    
    public function getRowCount(){
        return $this->delete->rowCount();
    }
}