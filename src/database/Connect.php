<?php

namespace Psf\Database;

use Psf\Database\Dialect\DialectFactory;

class Connect{
    static $connect = null;
    static $tables = null;

    private static function doConnect($database = 'default'){
        $configDb = \PSF::getConfig()->db;

        if(isset($configDb[$database]) && !empty($configDb[$database])){
            $username = $configDb[$database]['username'];
            $password = $configDb[$database]['password'];
            $extras   = $configDb[$database]['extras'] ?? [];

            try{
                if(empty(self::$connect[$database])){
                    $dialect = DialectFactory::fromConfig($configDb[$database]);
                    self::$connect[$database] = new \PDO(
                        $dialect->buildDsn($configDb[$database]),
                        $username,
                        $password,
                        $extras
                    );
                }
            }catch(\PDOException $e){
                explodeException($e);
            }

            self::$connect[$database]->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::listTables($database);
            return self::$connect[$database];
        }else{
            throw new \Exception("Database not found");
        }
    }

    public static function getConnection($database = 'default'){
        return self::doConnect($database);
    }

    public static function listTables($database = 'default'){
        $configDb = \PSF::getConfig()->db[$database];

        if(extension_loaded('apcu')){
            $saveCache = isset($configDb['savecache']) && $configDb['savecache'] == TRUE;
            
            if($saveCache){
                $stringCache = "db_" . $configDb['database'] . "_cache_" . $database;
                $itens = apcu_fetch($stringCache, $recoverOnApcu);

                if($recoverOnApcu){
                    self::$tables[$database] = array_values($itens);
                    return TRUE;
                }
            }
        }

        try{
            $dialect   = DialectFactory::fromConfig($configDb);
            $statement = self::$connect[$database]->prepare($dialect->listTablesQuery());

            if($statement instanceof \PDOStatement){
                $statement->execute();
                $tables = $statement->fetchAll(\PDO::FETCH_NUM);

                $itens = [];
                foreach($tables as $item){        
                    $itens[] = $item[0];
                }
                
                self::$tables[$database] = array_values($itens);

                if(extension_loaded('apcu') && $saveCache){
                    apcu_store($stringCache, array_values($itens), 604800);
                }
            }
        }catch (\PDOException $e){
            throw new \Exception("Unable to recover database tables");
        }
    }

    public static function getColunsForTable($table, $database = 'default') : array{
        self::getConnection($database);

        $configDb = \PSF::getConfig()->db[$database];

        if(extension_loaded('apcu')){
            $saveCache = isset($configDb['savecache']) && $configDb['savecache'] == TRUE;
            
            if($saveCache){
                $stringCache = 'db_' . $configDb['database'] . '_cache_' . $configDb['database'] . '_' . $table;
                $itens = apcu_fetch($stringCache, $recoverOnApcu);

                if($recoverOnApcu){
                    return $itens;
                }
            }
        }

        try{
            $dialect   = DialectFactory::fromConfig($configDb);
            $statement = self::$connect[$database]->prepare($dialect->columnsQuery($table));
            $statement->execute($dialect->columnsQueryParams($table));
            $coluns = $statement->fetchAll(\PDO::FETCH_ASSOC);

            foreach($coluns as $item){ 
                $arrReturn[] = (object) $item;
            }
            
            if(extension_loaded('apcu') && $saveCache){
                apcu_store($stringCache, $arrReturn, 604800);
            }

            return $arrReturn;
        }catch (\PDOException $e){
            throw new \Exception("Unable to retrieve fields from database table");
        }
    }

    public static function getConnect($database = 'default'){
        return self::$connect[$database];
    }

    public static function initTransaction($database = 'default'){
        self::getConnection($database);
        self::$connect[$database]->beginTransaction();
        return self::$connect[$database];
    }

    public static function commitTransaction($database = 'default'){
        if(self::$connect[$database]->inTransaction()){
            self::$connect[$database]->commit();
            self::$connect[$database] = null;
        }
    }

    public static function rollBackTransaction($database = 'default'){
        if(self::$connect[$database]->inTransaction()){
            self::$connect[$database]->rollBack();
            self::$connect[$database] = null;
        }
    }

    public static function inTransactionQuery($database = 'default'){
        if(self::$connect[$database]->inTransaction()){
            return true;
        }
        return false;
    }

    public static function verifyTableExist($table, $database = 'default'){
        if(in_array($table, self::$tables[$database])){
            return true;
        }else{
            return false;
        }
    }
    
    public static function Command($query){
        $Read = new Read;
        
        $execute = $Read->exe(
            table: NULL, 
            string: $query,
            free: TRUE
        );

        if(!$execute || $execute->getRowCount() == 0){
            return false;
        }

        $Read->closeCursor();

        return $execute->getResult();
    }
}