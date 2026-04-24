<?php

namespace Psf\Database;

use Psf\Database\Dialect\DialectFactory;

class Update extends Connect{
    private $table;
    private $data;
    private $terms;
    private $places;
    private $result;
    private $update;    
    private $connection;
    private $database;

    public static function exe($table, array $data, $terms, $parseString = null, $database = 'default', array $termsParams = []){
        $configDb   = \PSF::getConfig()->db;
        $obj        = new Update;

        if(empty(self::$connection)){
            $obj->connection = parent::getConnection($database);
        }

        $obj->table = (string) $table;
        $obj->data = $data;
        $obj->terms = $terms;  

        if(self::verifyTableExist($table, $database)){
            $dialect = DialectFactory::fromConfig($configDb[$database]);

            if(!empty($parseString) && !empty($obj->places)){
                parse_str($parseString, $obj->places);
            }else{
                $obj->places = [];
            }

            foreach($obj->data as $key => $value) {
                $places[] = $dialect->quoteIdentifier($key) . ' = :' . $key;
            }
            $places = implode(', ', $places);

            $quotedTable = $dialect->quoteIdentifier($obj->table);
            $obj->update = "UPDATE {$quotedTable} SET {$places} {$obj->terms}";

            $obj->update = $obj->connection->prepare($obj->update);
        
            try{
                $obj->update->execute(array_merge($obj->data, $obj->places, $termsParams));
                $obj->result = true;

                return $obj;
            }catch(\PDOException $e){
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
    
    public function getRowCount() {
        return $this->update->rowCount();
    }
}