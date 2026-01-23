<?php

namespace Psf\Database;

use \Psf\Enumerators\{DBDriver};

class Update extends Connect{
    private $table;
    private $data;
    private $terms;
    private $places;
    private $result;
    private $update;    
    private $connection;
    private $database;

    public static function exe($table, array $data, $terms, $parseString = null, $database = 'default'){
        $configDb   = \PSF::getConfig()->db;
        $obj        = new Update;

        if(empty(self::$connection)){
            $obj->connection = parent::getConnection($database);
        }

        $obj->table = (string) $table;
        $obj->data = $data;
        $obj->terms = $terms;  

        if(self::verifyTableExist($table, $database)){
            $configDb   = \PSF::getConfig()->db;
            $driver     = !empty($configDb[$database]['driver']) ? $configDb[$database]['driver'] : DBDriver::MySQL;

            if(!empty($parseString) && !empty($obj->places)){
                parse_str($parseString, $obj->places);
            }else{
                $obj->places = [];
            }

            // parse_str($parseString, $obj->places);

            if($driver == DBDriver::MySQL){
                foreach($obj->data as $key => $value) {
                    $places[] = '`' . $key . '` = :' . $key;
                }

                $obj->table = '`' . $obj->table . '`';
            }

            if($driver == DBDriver::SQLServer){
                foreach($obj->data as $key => $value) {
                    $places[] = '[' . $key . '] = :' . $key;
                }
            }
            
            $places = implode(', ', $places);

            $obj->update = "UPDATE {$obj->table} SET {$places} {$obj->terms}";

            $obj->update = $obj->connection->prepare($obj->update);
        
            try{
                $obj->update->execute(array_merge($obj->data, $obj->places));
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