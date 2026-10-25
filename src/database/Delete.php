<?php

namespace Psf\Database;

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Monitor;

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
            $sql   = "DELETE FROM " . $dialect->quoteIdentifier($obj->table) . " {$obj->terms}";
            $start = null;

            try{
                $obj->delete = $obj->connection->prepare($sql);

                // Monitor: registra uma única vez (no sucesso ou no catch)
                $start = Monitor::queryStart();
                $obj->delete->execute(array_merge($obj->places, $termsParams));
                Monitor::queryEnd($start, $sql, (string) $database);
                $obj->result = true;

                return $obj;
            }catch (\PDOException $e){
                // Registra antes do explodeException(), que termina com exit
                Monitor::queryEnd($start, $sql, (string) $database);
                Monitor::exception($e);

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