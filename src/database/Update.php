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

        $obj->connection = parent::getConnection($database);

        $obj->table = (string) $table;
        $obj->data = $data;
        $obj->terms = $terms;  

        if(self::verifyTableExist($table, $database)){
            if(empty($obj->data)){
                throw new \InvalidArgumentException('Update sem colunas para atualizar');
            }

            $dialect = DialectFactory::fromConfig($configDb[$database]);

            if(!empty($parseString)){
                parse_str($parseString, $obj->places);
            }else{
                $obj->places = [];
            }

            // Placeholders posicionais por índice: o nome da coluna (que pode vir de input) nunca
            // entra no SQL como parâmetro — só como identificador, já escapado pelo dialeto.
            $places = [];
            $setParams = [];
            $index = 0;
            foreach($obj->data as $key => $value) {
                $placeholder = '__set_' . $index++;
                $places[] = $dialect->quoteIdentifier((string) $key) . ' = :' . $placeholder;
                $setParams[$placeholder] = $value;
            }
            $places = implode(', ', $places);

            $quotedTable = $dialect->quoteIdentifier($obj->table);
            $obj->update = "UPDATE {$quotedTable} SET {$places} {$obj->terms}";
        
            try{
                // prepare() dentro do try: drivers como o SQLite validam o SQL já no prepare
                $obj->update = $obj->connection->prepare($obj->update);
                $obj->update->execute(array_merge($setParams, $obj->places, $termsParams));
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