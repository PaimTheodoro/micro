<?php

namespace Psf\Database;

class Read extends Connect{
    private $select;
    private $places;
    private $result;

    private $read;
    private $connection;
    
    public function exe($table, $string = null, array|string|null $parseString = null, $database = 'default', $free = false){
        if (!empty($parseString)) {
            if (is_array($parseString)) {
                $this->places = $parseString;
            } else {
                $this->places = [];
                foreach (explode('&', $parseString) as $item) {
                    $parts = explode('=', $item, 2);
                    if (isset($parts[1])) {
                        $this->places[$parts[0]] = $parts[1];
                    }
                }
            }
        }
        
        if($free == false){
            $databaseName = \PSF::getConfig()->db[$database]['database'];
            $this->select = "SELECT * FROM `{$databaseName}`.`{$table}` {$string}";
        }else{
            if(empty($string)){
                return false;
            }else{
                $this->select = $string;
            }
        }
        
        $this->connection = parent::getConnection($database);

        $this->read = $this->connection->prepare($this->select);
        $this->read->setFetchMode(\PDO::FETCH_ASSOC);

        if(in_array($table, parent::$tables[$database]) || $table === NULL || str_starts_with($table, 'v_') || str_starts_with($table, 'view_')){
            $this->execute();
            return $this;
        }else{
            return false;
        }
    }
    
    public function getResult(){
        return $this->result;
    }
    
    public function getRowCount() {
        return $this->read->rowCount();
    }
    
    private function getSyntax(){
        if(!empty($this->places)){
            foreach($this->places as $key => $value){
                $pattern = '/%/';
                if (preg_match($pattern, $value)) {
                    $likeInicial = substr($value, 0, 2) == "'%";
                    $likeFinal   = substr($value, -2) == "%'";

                    if($likeInicial || $likeFinal){
                        $stripped = str_replace(["'", "%"], "", $value);

                        if($likeInicial && $likeFinal){
                            $this->read->bindValue(":{$key}", "%{$stripped}%", \PDO::PARAM_STR);
                        }else if($likeInicial){
                            $this->read->bindValue(":{$key}", "%{$stripped}", \PDO::PARAM_STR);
                        }else{
                            $this->read->bindValue(":{$key}", "{$stripped}%", \PDO::PARAM_STR);
                        }
                    }else{
                        // Valor comum que só por coincidência contém '%' (ex.: "50% OFF"),
                        // não um padrão LIKE — precisa ser bindado como está, senão o
                        // placeholder fica sem valor e o PDO estoura HY093.
                        $this->read->bindValue(
                            ":{$key}", $value, (is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR )
                        );
                    }
                }else{
                    $this->read->bindValue(
                        ":{$key}", $value, (is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR )
                    );
                }
            }
        }
    }

    private function execute(){
        try{
            $this->getSyntax();
            $this->read->execute();
            $this->result = $this->read->fetchAll();
        }catch (\PDOException $e){
            $this->result = null;
            explodeException($e);
        }
    }

    public function closeCursor(){
        if($this->read instanceof \PDOStatement){
            $this->read->closeCursor();
        }
    }
}