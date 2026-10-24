<?php

namespace Psf\Database;

use Psf\Database\Dialect\DialectFactory;

class Connect{
    static $connect = null;
    static $tables = null;

    /** @var array<string, array<string, array>> Colunas por [database][tabela], memorizadas na requisição. */
    private static array $columnsCache = [];

    private static function doConnect($database = 'default'){
        $configDb = \PSF::getConfig()->db;

        if(isset($configDb[$database]) && !empty($configDb[$database])){
            // Aceita as duas grafias de config (username/password e user/pass); SQLite não usa credenciais
            $username = $configDb[$database]['username'] ?? $configDb[$database]['user'] ?? null;
            $password = $configDb[$database]['password'] ?? $configDb[$database]['pass'] ?? null;
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

            // A lista de tabelas é lida uma vez por conexão (antes: um SHOW TABLES a cada operação).
            // verifyTableExist() a relê quando não encontra a tabela (ex.: criada por migration).
            if(!isset(self::$tables[$database])){
                self::listTables($database);
            }

            return self::$connect[$database];
        }else{
            throw new \Exception("Database not found");
        }
    }

    public static function getConnection($database = 'default'){
        return self::doConnect($database);
    }

    /**
     * @param bool $refresh Ignora o cache do APCu e consulta o banco (ex.: depois de criar tabelas).
     */
    public static function listTables($database = 'default', bool $refresh = false){
        $configDb    = \PSF::getConfig()->db[$database];
        $saveCache   = false;
        $stringCache = null;

        if(extension_loaded('apcu')){
            $saveCache = isset($configDb['savecache']) && $configDb['savecache'] == TRUE;
            
            if($saveCache && !$refresh){
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
                    $stringCache = "db_" . $configDb['database'] . "_cache_" . $database;
                    apcu_store($stringCache, array_values($itens), 604800);
                }
            }
        }catch (\PDOException $e){
            throw new \Exception("Unable to recover database tables");
        }
    }

    public static function getColunsForTable($table, $database = 'default') : array{
        self::getConnection($database);

        // Memorizado na requisição: Model::toArray() chamava isto (uma ida ao banco) a cada uso
        if(isset(self::$columnsCache[$database][$table])){
            return self::$columnsCache[$database][$table];
        }

        $configDb    = \PSF::getConfig()->db[$database];
        $saveCache   = false;
        $stringCache = null;
        $arrReturn   = [];

        if(extension_loaded('apcu')){
            $saveCache = isset($configDb['savecache']) && $configDb['savecache'] == TRUE;
            
            if($saveCache){
                $stringCache = 'db_' . $configDb['database'] . '_cache_' . $configDb['database'] . '_' . $table;
                $itens = apcu_fetch($stringCache, $recoverOnApcu);

                if($recoverOnApcu){
                    return self::$columnsCache[$database][$table] = $itens;
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

            return self::$columnsCache[$database][$table] = $arrReturn;
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
        if(self::inTransactionQuery($database)){
            self::$connect[$database]->commit();
        }
    }

    public static function rollBackTransaction($database = 'default'){
        if(self::inTransactionQuery($database)){
            self::$connect[$database]->rollBack();
        }
    }

    public static function inTransactionQuery($database = 'default'){
        return (self::$connect[$database] ?? null) instanceof \PDO
            && self::$connect[$database]->inTransaction();
    }

    /** Descarta as colunas memorizadas (ex.: depois de alterar o schema no mesmo processo). */
    public static function clearColumnsCache(){
        self::$columnsCache = [];
    }

    public static function verifyTableExist($table, $database = 'default'){
        if(in_array($table, self::$tables[$database] ?? [])){
            return true;
        }

        // Não achou: a lista pode estar velha (tabela criada depois da conexão) — relê uma vez
        if(self::$connect[$database] ?? null){
            self::listTables($database, true);
            self::clearColumnsCache();
        }

        return in_array($table, self::$tables[$database] ?? []);
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