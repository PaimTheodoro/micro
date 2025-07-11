<?php

namespace Psf\Database;

use \Psf\Enumerators\{DBDriver};

class Create extends Connect{
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
            $driver     = !empty($configDb[$database]['driver']) ? $configDb[$database]['driver'] : DBDriver::MySQL;

            if($driver == DBDriver::MySQL){
                $fields = "`" . implode('`, `', array_keys($obj->data)) . "`";
            }

            if($driver == DBDriver::SQLServer){
                $fields = "[" . implode('], [', array_keys($obj->data)) . "]";
            }

            $places = ':' . implode(', :', array_keys($obj->data));

            $obj->create = "INSERT INTO `{$obj->table}` ({$fields}) VALUES ({$places})";
            $obj->create = $obj->connection->prepare($obj->create);

            try{
                $obj->create->execute($obj->data);
                $obj->result = $obj->connection->lastInsertId();

                return $obj;
            }catch(\PDOException $e){
                if($obj->connection->inTransaction()){
                    $obj->connection->rollBack();
                }

                $obj->result = null;
                explodeException($e);
                        
                return FALSE;
            }
        }
    }

    public function getResult(){
        return $this->result;
    }
}