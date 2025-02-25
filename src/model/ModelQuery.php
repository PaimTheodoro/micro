<?php

namespace Psf\Model;

use \Psf\Enumerators\{DBDriver};

class ModelQuery{
    private $obj;
    private $query;
    private $configDb;

    public function __construct($class){
        $this->obj = new $class;

        $this->query = [
            'fields'        => NULL,
            'wheres'        => NULL,
            'orWheres'      => NULL,
            'innerJoins'    => NULL,
            'leftJoins'     => NULL,
            'order'         => NULL,
            'groupBy'       => NULL,
            'limit'         => NULL,
            'offset'        => NULL,
            'parses'        => NULL,
            'freequery'     => NULL,
            'isCount'       => FALSE,
            'asArray'       => FALSE,
            'database'      => Model::getDatabase($class) ?? 'default'
        ];

        $this->configDb = \PSF::getConfig()->db[Model::getDatabase($class)]; 
    }

    private function getDatabaseName() : string{
        return \PSF::getConfig()->db[Model::getDatabase($this->obj::class)]['database'];
    }

    private function handleTableName() : string{
        $driver = !empty($this->configDb['driver']) ? $this->configDb['driver'] : DBDriver::MySQL;
        
        if($driver == DBDriver::MySQL){
            return '`' . $this->getDatabaseName() . '`.`' . Model::getTable($this->obj::class) . '`';
        }

        if($driver == DBDriver::SQLServer){
            return '[' . $this->getDatabaseName() . '].[dbo].[' . Model::getTable($this->obj::class) . ']';
        }

        return $this->getDatabaseName() . '.' . Model::getTable($this->obj::class);
    }

    public static function getHandleTableName(string $table, string $database = 'default') : string{
        $configDb   = \PSF::getConfig()->db;
        $driver     = !empty($configDb[$database]['driver']) ? $configDb[$database]['driver'] : DBDriver::MySQL;

        if($driver == DBDriver::MySQL){
            return '`' . \PSF::getConfig()->db[$database]['database'] . '`.`' . $table . '`';
        }

        if($driver == DBDriver::SQLServer){
            return '[' . $table . ']';
        }

        return \PSF::getConfig()->db[$database]['database'] . '.' . $table;
    }

    private function getAcceptComparativeOperators() : array{
        return ['=', '<>', '>', '<', 'IS NULL', 'IS NOT NULL', 'LIKE'];
    }

    private function generateField($field){
        $driver = !empty($this->configDb['driver']) ? $this->configDb['driver'] : DBDriver::MySQL;
        $arrIgnoreRules = ['SUM', 'COUNT', 'MAX', 'LEFT'];

        if(is_array($field)){
            if($field[0] == 'subquery'){
                return $field[1];
            }
            
            if(class_exists($field[0])){
                $tableName = Model::getTable($field[0]);
                
                $getField = Model::getColumnByProp($field[0], $field[1]);
                if(!empty($getField)){
                    $field[1] = $getField;
                }
            }else{
                $tableName = $field[0];
            }

            if($driver == DBDriver::MySQL){
                $result = $tableName . ".`" . $field[1] . "`";
            }

            if($driver == DBDriver::SQLServer){
                $result =  "[" . $tableName . "].[" . $field[1] . "]";
            }

            return $result . (!empty($field[2]) ? (' AS ' . $field[2]): '');           
        }else if(is_string($field)){
            if(!empty(Model::getTable($this->obj)) && !in_array(substr($field, 0, 5), $arrIgnoreRules) && !in_array(substr($field, 0, 4), $arrIgnoreRules) && !in_array(substr($field, 0, 3), $arrIgnoreRules)){
                $explodeField = explode(".", $field);

                if(count($explodeField) == 2){
                    if(class_exists($explodeField[0])){
                        $getPropByColumn = Model::getColumnByProp($explodeField[0], $explodeField[1]);
                        if(!empty($getPropByColumn)){
                            $explodeField[1] = $getPropByColumn;
                        }

                        if($driver == DBDriver::MySQL){
                            $field = '`' . (new $explodeField[0])->getTableName() . '`' . ($this->handleAliasField($explodeField[1]));
                        }

                        if($driver == DBDriver::SQLServer){
                            $field = '[' . (new $explodeField[0])->getTableName() . ']' . $this->handleAliasField($explodeField[1]);
                        }

                        if(!isset($field)){
                            $field = (new $explodeField[0])->getTableName() . ($this->handleAliasField($explodeField[1]));
                        }
                    }else{
                        $field = ($explodeField[0] . $this->handleAliasField($explodeField[1]));
                    }

                    return $field;
                }else if(count($explodeField) > 2){
                    return $field;
                }else{
                    if($driver == DBDriver::MySQL){
                        return Model::getTable($this->obj::class) . ".`" . $field . "`";
                    }

                    if($driver == DBDriver::SQLServer){
                        // return '[' . $this->obj->tableName . "].[" . $field . "]";
                        return "[" . Model::getTable($this->obj::class) . "].[" . $field . "]";
                    }

                    return Model::getTable($this->obj::class) . "." . $field;
                }
            }
        }else if(is_object($field) && isset($field->Field)){    
            return $field->Field;
        }

        return $field;
    }

    private function handleAliasField(string $field) : string{
        $driver = !empty($this->configDb['driver']) ? $this->configDb['driver'] : DBDriver::MySQL;

        $explodeAs = explode(' ', $field);

        if(in_array('as', $explodeAs) || in_array('AS', $explodeAs)){
            if($driver == DBDriver::MySQL){
                return '.`' . $explodeAs[0] . '` AS `' . $explodeAs[2] . '`';
            }

            if($driver == DBDriver::SQLServer){
                return '.[' . $explodeAs[0] . '] AS [' . $explodeAs[2] . ']';
            }            

            return '.' . $explodeAs[0] . ' AS ' . $explodeAs[2];
        }   

        if($field == '*'){
            return '.' . $field;
        }

        if($driver == DBDriver::MySQL){
            return '.`' . $field . '`';
        }

        if($driver == DBDriver::SQLServer){
            return '.[' . $field . ']';
        }
    }   

    private function handleExtraQuery($query){
        $explodeSpaces = explode(' ', $query);

        if(count($explodeSpaces) > 0){
            foreach ($explodeSpaces as &$itemSpaced) {
                $explodeField = explode('.', $itemSpaced);
                
                if(count($explodeField) > 0){
                    foreach ($explodeField as &$itemField) {
                        if (strpos($itemField, "\\") !== false) {
                            if(class_exists($itemField)){
                                $class = $itemField;
                                $itemField = (new $itemField)->getTableName();
                            }
                        }

                        if(!in_array($itemField, ['=', '<>'])){
                            if(isset($class)){
                                $getField = Model::getColumnByProp($class, $itemField);
                                if(!empty($getField)){
                                    $itemField = $getField;
                                }
                            }
                        }
                    }

                    $itemSpaced = implode('.', $explodeField);
                }
            }

            $query = implode(' ', $explodeSpaces); 
        }

        return $query;
    }   

    public function andWhere(string|array $query, array|null $parses = null) : ModelQuery{
        $parse = uniqid();

        if(is_array($query)){
            if(count($query) === 1){
                $this->query['parses'][$parse] = $query[array_keys($query)[0]];
                $this->query['wheres'][] = $this->generateField(array_keys($query)[0]) . " = :" . $parse;
            }

            if(count($query) === 2){
                if($query[0] == "OR" && is_array($query[1])){
                    $stringFinal = "(";
                    $countItens = 0;
                    foreach($query[1] as $key => $value){
                        if(is_array($value)){
                            if(count($value) == 1){
                                $parse = uniqid();
                                $this->query['parses'][$parse] = $value[array_keys($value)[0]];
                                $stringFinal .= $this->generateField(array_keys($value)[0]) . " = :" . $parse;
                                if($countItens < count($query[1]) - 1){
                                    $stringFinal .= " OR ";
                                }
                                $countItens++;
                            }else if(count($value) == 3){
                                if(in_array($value[1], $this->getAcceptComparativeOperators())){
                                    if($value[1] == "IS NULL" || $value[1] == "IS NOT NULL"){
                                        $stringFinal .= $this->generateField($value[0]) . " " . $value[1];
                                    }else{
                                        $parse = uniqid();
                                        $this->query['parses'][$parse] = $value[2];
                                        $stringFinal .= $this->generateField($value[0]) . " " . $value[1] . " :" . $parse;
                                    }
                                    if($countItens < count($query[1]) - 1){
                                        $stringFinal .= " OR ";
                                    }
                                    $countItens++;
                                }else{
                                    //Estourar erro
                                }
                            }
                        }else{
                            $stringFinal .= $value;
                            if($countItens < count($query[1]) - 1){
                                $stringFinal .= " OR ";
                            }
                            $countItens++;
                        }
                    }
                    $stringFinal .= ")";
                    if(!empty($stringFinal)){
                        $this->query['wheres'][] = $stringFinal;
                    }
                }

                if($query[0] == 'AND' && is_array($query[1])){
                    $stringFinal = "(";
                    $countItens = 0;
                    foreach($query[1] as $key => $value){
                        if(count($value) == 1){
                            $parse = uniqid();
                            $this->query['parses'][$parse] = $value[array_keys($value)[0]];
                            $stringFinal .= $this->generateField(array_keys($value)[0]) . " = :" . $parse;
                            if($countItens < count($query[1]) - 1){
                                $stringFinal .= " AND ";
                            }
                            $countItens++;
                        }else if(count($value) == 3){
                            if(in_array($value[1], $this->getAcceptComparativeOperators())){
                                if($value[1] == "IS NULL" || $value[1] == "IS NOT NULL"){
                                    $stringFinal .= $this->generateField($value[0]) . " " . $value[1];
                                }else{
                                    $parse = uniqid();
                                    $this->query['parses'][$parse] = $value[2];
                                    $stringFinal .= $this->generateField($value[0]) . " " . $value[1] . " :" . $parse;
                                }
                                if($countItens < count($query[1]) - 1){
                                    $stringFinal .= " AND ";
                                }
                                $countItens++;
                            }else{
                                //Estourar erro
                            }
                        }
                    }
                    $stringFinal .= ")";
                    if(!empty($stringFinal)){
                        $this->query['wheres'][] = $stringFinal;
                    }
                }

                if(in_array($query[1], ['IS NULL', 'IS NOT NULL'])){
                    $this->query['wheres'][] = $this->generateField($query[0]) . " " . $query[1];
                }
            }

            if(count($query) === 3){
                if(in_array($query[1], $this->getAcceptComparativeOperators())){
                    if(strtoupper($query[1]) == "IS NULL" || strtoupper($query[1]) == "IS NOT NULL"){
                        $this->query['wheres'][] = $this->generateField($query[0]) . " " . $query[1];
                    }else{  
                        $this->query['parses'][$parse] = $query[2];
                        $this->query['wheres'][] = $this->generateField($query[0]) . " " . $query[1] . " :" . $parse;
                    }
                }else if(in_array(strtoupper($query[1]), ['IN', 'NOT IN']) && is_array($query[2])){
                    $string = '';

                    foreach($query[2] as $itemIn){
                        $parse = uniqid();

                        $string .= ':' . $parse . ',';
                        $this->query['parses'][$parse] = $itemIn;
                    }

                    if(!empty($string)){
                        $this->query['wheres'][] = $this->generateField($query[0]) . ' ' . $query[1] . ' (' . substr($string, 0, -1) . ')';
                    }
                }else{
                    //Estourar erro
                }
            }
        }

        if(is_string($query)){
            if(!empty($parses)){
                foreach($parses as $key => $item){
                    $parse = uniqid();
                    $query = str_replace(':' . $key . ':', ':' . $parse, $query);
                    $this->query['parses'][$parse] = $item;
                }
                $this->query['wheres'][] = $query;
            }else{
                $this->query['wheres'][] = $query;
            }
        }

        return $this;
    }

    public function orWhere(string|array $query, array|null $parses = null) : ModelQuery{
        $parse = uniqid();
        if(is_array($query)){
            if(count($query) == 1){
                $this->query['parses'][$parse] = $query[array_keys($query)[0]];
                $this->query['orWheres'][] = $this->generateField(array_keys($query)[0]) . " = :" . $parse;
            }else if(count($query) == 3){
                if(in_array($query[1], $this->getAcceptComparativeOperators())){
                    if($query[1] == "IS NULL" || $query[1] == "IS NOT NULL"){
                        $this->query['orWheres'][] = $this->generateField($query[0]) . " " . $query[1];
                    }else{
                        $this->query['parses'][$parse] = $query[2];
                        $this->query['orWheres'][] = $this->generateField($query[0]) . " " . $query[1] . " :" . $parse;
                    }
                }else{
                    //Estourar erro
                }
            }else if(count($query) == 2){
                if($query[0] == "OR" && is_array($query[1])){
                    $stringFinal = "(";
                    $countItens = 0;
                    foreach($query[1] as $key => $value){
                        if(count($value) == 1){
                            $parse = uniqid();
                            $this->query['parses'][$parse] = $value[array_keys($value)[0]];
                            $stringFinal .= $this->generateField(array_keys($value)[0]) . " = :" . $parse;
                            if($countItens < count($query[1]) - 1){
                                $stringFinal .= " OR ";
                            }
                            $countItens++;
                        }else if(count($value) == 3){
                            if(in_array($value[1], $this->getAcceptComparativeOperators())){
                                if($value[1] == "IS NULL" || $value[1] == "IS NOT NULL"){
                                    $stringFinal .= $this->generateField($value[0]) . " " . $value[1];
                                }else{
                                    $parse = uniqid();
                                    $this->query['parses'][$parse] = $value[2];
                                    $stringFinal .= $this->generateField($value[0]) . " " . $value[1] . " :" . $parse;
                                }
                                if($countItens < count($query[1]) - 1){
                                    $stringFinal .= " OR ";
                                }
                                $countItens++;
                            }else{
                                //Estourar erro
                            }
                        }
                    }
                    $stringFinal .= ")";
                    if(!empty($stringFinal)){
                        $this->query['orWheres'][] = $stringFinal;
                    }
                }else if($query[0] == 'AND' && is_array($query[1])){
                    $stringFinal = "(";
                    $countItens = 0;
                    foreach($query[1] as $key => $value){
                        if(count($value) == 1){
                            $parse = uniqid();
                            $this->query['parses'][$parse] = $value[array_keys($value)[0]];
                            $stringFinal .= $this->generateField(array_keys($value)[0]) . " = :" . $parse;
                            if($countItens < count($query[1]) - 1){
                                $stringFinal .= " AND ";
                            }
                            $countItens++;
                        }else if(count($value) == 3){
                            if(in_array($value[1], $this->getAcceptComparativeOperators())){
                                if($value[1] == "IS NULL" || $value[1] == "IS NOT NULL"){
                                    $stringFinal .= $this->generateField($value[0]) . " " . $value[1];
                                }else{
                                    $parse = uniqid();
                                    $this->query['parses'][$parse] = $value[2];
                                    $stringFinal .= $this->generateField($value[0]) . " " . $value[1] . " :" . $parse;
                                }
                                if($countItens < count($query[1]) - 1){
                                    $stringFinal .= " AND ";
                                }
                                $countItens++;
                            }else{
                                //Estourar erro
                            }
                        }
                    }
                    $stringFinal .= ")";
                    if(!empty($stringFinal)){
                        $this->query['orWheres'][] = $stringFinal;
                    } 
                }
            }
        }else if(is_string($query)){
            if(!empty($parses)){
                foreach($parses as $key => $item){
                    $parse = uniqid();
                    $query = str_replace(':' . $key . ':', ':' . $parse, $query);
                    $this->query['parses'][$parse] = $item;
                }
                $this->query['orWheres'][] = $query;
            }else{
                $this->query['orWheres'][] = $query;
            }
        }
        return $this;
    }

    public function dump(){

        echo "<pre>";
        var_dump($this);
        die;

    }

    public function fields(array|null $fields = null) : ModelQuery{
        if(empty($fields)){
            $this->query['fields'] = [];
            return $this;    
        }

        if(is_array($fields)){
            foreach($fields as $item){
                $this->query['fields'][] = $this->generateField($item);
            }
        }

        return $this;
    }

    public function one(){
        $this->query['limit'] = 1;
        return $this->execute();
    }

    public function all(){
        $this->query['limit'] = null;
        return $this->execute();
    }

    public function innerJoin(array|string $table, string $query) : ModelQuery{
        $this->query['innerJoins'][] = [
            "table" => $table, 
            "query" => $query
        ];

        return $this;
    }

    public function leftJoin(array|string|null $table, string $query) : ModelQuery{
        $this->query['leftJoins'][] = [
            "table" => $table, 
            "query" => $query
        ];

        return $this;
    }

    public function orderBy(string $field, string|null $type = "ASC") : ModelQuery{
        if($type == "ASC" || $type == "DESC"){
            $this->query['order'][] = $this->generateField($field) . " " . $type;
        }else{
            $this->query['order'][] = $field;
        }
        return $this;
    }

    public function limit(int $limit) : ModelQuery{
        $this->query['limit'] = $limit;
        return $this;
    }

    public function groupBy(string $field) : ModelQuery{
        $this->query['groupBy'][] = $this->generateField($field);
        return $this;
    }

    public function database(string $database){
        $this->query['database'] = $database;
        return $this;
    }

    public function execute(){
        $refClass = new \ReflectionClass($this->obj::class);
        foreach($refClass->getProperties() as $property){
            $attributes = $property->getAttributes();

            $column = array_values(array_filter($attributes, function($attr) use ($property){
                return $attr->getName() === 'Column';
            }));

            if(!empty($column)){
                $column = $column[0]->getArguments()[0];

                $columnDeleted = array_values(array_filter($attributes, function($attr) use ($property){
                    return $attr->getName() === 'ColumnDeletedDate';
                }));

                if(!empty($columnDeleted)){
                    $this->andWhere([$this->obj::class . '.' . $column, 'IS NULL']);
                    break;
                }
            }
        }

        $Read = new \Psf\Database\Read();
        $Read->exe(
            Model::getTable($this->obj::class),
            $this->writeQuery(),
            $this->getParses(),
            Model::getDatabase($this->obj::class),
            true
        );
        return $this->queryResult($Read);
    }

    private function writeQuery() : string{
        $driver = !empty($this->configDb['driver']) ? $this->configDb['driver'] : DBDriver::MySQL;
        $primaryKeys = Model::getPrimaryKey($this->obj::class);

        $stringQuery = "SELECT ";

        if($driver == DBDriver::SQLServer){
            if(!empty($this->query['limit']) && $this->query['offset'] === NULL){
                $stringQuery .= " TOP " . $this->query['limit'] . ' ';
            }
        } 

        if($driver == DBDriver::SQLServer && (isset($this->query['offset']) && $this->query['offset'] !== NULL) && (isset($this->query['limit']) && $this->query['limit'] !== NULL)){

            if($this->query['order'] === NULL && !empty($this->obj->getIdentityColumn())){
                $this->orderBy($this->obj::class . '.' . $this->obj->getIdentityColumn(), 'ASC');
                // $stringQuery .= ' ORDER BY [' . $this->obj->tableName . '].[' . $this->obj->getIdentityColumn() . ']';
            }
        }

        if(isset($this->query['isCount']) && $this->query['isCount'] === true){
            if(!empty($primaryKeys)){
                $fieldsQuery = "COUNT(" . $this->generateField($primaryKeys[0]) . ") as qtd";

                if($driver == DBDriver::SQLServer){
                    $this->query['order'] = NULL;
                }
            }
        }else if(!isset($this->query['fields']) || empty($this->query['fields'])){
            if((isset($this->query['innerJoins']) && !empty($this->query['innerJoins'])) || (isset($this->query['leftJoins']) && !empty($this->query['leftJoins']))){

                if($driver == DBDriver::MySQL){
                    $fieldsQuery = '`' . Model::getTable($this->obj::class) . '`.*';
                }

                if($driver == DBDriver::SQLServer){
                    $fieldsQuery = '[' . Model::getTable($this->obj::class) . '].*';
                }
            }else{
                $fieldsQuery = '*';
            }
        }else{
            $fieldsQuery = implode(", ", $this->query['fields']);
        }

        $stringQuery .= $fieldsQuery;
        $stringQuery .= " FROM " . $this->handleTableName() . " ";

        if(isset($this->query['innerJoins']) && !empty($this->query['innerJoins'])){
            foreach($this->query['innerJoins'] as $itemJoin){
                if(is_array($itemJoin['table'])){
                    $tableName = class_exists($itemJoin['table'][0]) ? (new $itemJoin['table'][0])->getTableName() : $itemJoin['table'][0];
                    $stringQuery .= " INNER JOIN " . $tableName . " AS " .  $itemJoin['table'][1] . " ON " . $this->handleExtraQuery($itemJoin['query']) . " ";
                }else{
                    $tableName = class_exists($itemJoin['table']) ? (new $itemJoin['table'])->getTableName() : $itemJoin['table'];
                    $stringQuery .= " INNER JOIN " . $tableName . " ON " . $this->handleExtraQuery($itemJoin['query']) . " ";
                }
            }
        }

        if(isset($this->query['leftJoins']) && !empty($this->query['leftJoins'])){
            foreach($this->query['leftJoins'] as $itemJoin){
                if(is_array($itemJoin['table'])){
                    $tableName = class_exists($itemJoin['table'][0]) ? (new $itemJoin['table'][0])->getTableName() : $itemJoin['table'][0];

                    $stringQuery .= " LEFT JOIN " . $tableName . " AS " .  $itemJoin['table'][1] . " ON " . $this->handleExtraQuery($itemJoin['query']) . " ";
                }else{
                    if(!empty($itemJoin['table'])){
                        $tableName = class_exists($itemJoin['table']) ? (new $itemJoin['table'])->getTableName() : $itemJoin['table'];
                    
                        $stringQuery .= " LEFT JOIN " . $tableName . " ON " . $this->handleExtraQuery($itemJoin['query']) . " ";
                    }else{
                        $stringQuery .= " LEFT JOIN " . $this->handleExtraQuery($itemJoin['query']) . " ";
                    }   
                }
            }
        }

        if(isset($this->query['wheres']) && !empty($this->query['wheres'])){
            $stringWhere = "WHERE ";
            $countWheres = 0;
            $countOrWheres = 0;

            foreach($this->query['wheres'] as $item){
                $stringWhere .= $item;
                if($countWheres < (count($this->query['wheres']) - 1)){
                    $stringWhere .= " AND ";
                }
                $countWheres++;
            }

            if(!empty($this->query['wheres']) && !empty($this->query['orWheres'])){
                $stringWhere .= " OR ";
            }

            if(!empty($this->query['orWheres'])){
                foreach ($this->query['orWheres'] as $item) {
                    $stringWhere .= $item;
                    if($countOrWheres < (count($this->query['orWheres']) - 1)){
                        $stringWhere .= " OR ";
                    }
                    $countOrWheres++;
                }               
            }

        }

        $stringQuery .= ($stringWhere ?? "");

        if(isset($this->query['groupBy']) && !empty($this->query['groupBy'])){
            if(count($this->query['groupBy']) == 1){
                $stringQuery .= " GROUP BY " . $this->query['groupBy'][0];
            }else{
                $countGroup = 0;
                $stringQuery .= " GROUP BY ";
                foreach($this->query['groupBy'] as $item){
                    if($countGroup > 0){
                        $stringQuery .= ", " . $item;
                    }else{
                        $stringQuery .= $item;
                    }
                    $countGroup++;
                }
            }
        }
        
        if(isset($this->query['order']) && !empty($this->query['order'])){
            $stringQuery .= " ORDER BY ";
            $countOrder = 0;

            foreach($this->query['order'] as $item){
                if(count($this->query['order']) > 1 && $countOrder > 0){
                    $stringQuery .= ", ";
                }

                $stringQuery .= $item;
                $countOrder++;
            }

        }

        if($driver == DBDriver::MySQL){
            if(isset($this->query['limit']) && !empty($this->query['limit'])){
                $stringQuery .= " LIMIT " . $this->query['limit'];
            }

            if(isset($this->query['offset']) && !empty($this->query['offset'])){
                $stringQuery .= " OFFSET " . $this->query['offset'];
            }
        }

        if($driver == DBDriver::SQLServer && (isset($this->query['offset']) && $this->query['offset'] !== NULL) && (isset($this->query['limit']) && $this->query['limit'] !== NULL) && (!isset($this->query['isCount']) || $this->query['isCount'] === false)){
            $stringQuery .= " OFFSET " . $this->query['offset'] . " ROWS";
            $stringQuery .= " FETCH NEXT " . $this->query['limit'] . " ROWS ONLY";
        }

        return trim(str_replace("  ", " ", $stringQuery));
    }

    public function getRowQuery() : string {
        if(isset($this->configDb['fields']['status']) && !empty($this->configDb['fields']['status']) && property_exists($this->obj, $this->configDb['fields']['status'])){
            $this->andWhere([$this->obj::class . '.' . $this->configDb['fields']['status'], '<>' , -1]);
        }else{
            if(property_exists($this->obj, "status")){
                $this->andWhere([$this->obj::class . '.status', '<>' , -1]);
            }
        }

        if(isset($this->configDb['fields']['deletado']) && !empty($this->configDb['fields']['deletado']) && property_exists($this->obj, $this->configDb['fields']['deletado'])){
            $this->andWhere([$this->obj::class . '.' . $this->configDb['fields']['deletado'], 'IS NULL', NULL]);
        }else{
            if(property_exists($this->obj, 'deletado')){
                $this->andWhere([$this->obj::class . '.deletado', 'IS NULL', NULL]);
            }
        }

        $query = $this->writeQuery();
        if(!empty($this->query['parses']) && is_array($this->query['parses'])){
            foreach($this->query['parses'] as $key => $value){
                $query = str_replace(':' . $key, $value, $query);
            }
        }
        return $query;
    }

    public function getParses(){
        if(isset($this->query['parses']) && !empty($this->query['parses'])){
            $parseString = "";
            $countItens = 1;

            foreach($this->query['parses'] as $key => $value){
                $parseString .= $key . "=" . $value;
                if($countItens < count($this->query['parses'])){
                    $parseString .= "&";
                }
                $countItens++;
            }

            return $parseString;
        }else{
            return false;
        }
    }

    public function asArray(){
        $this->query['asArray'] = true;
        return $this;
    }

    private function queryResult($Read) : object|bool|array|int {
        if($this->query['isCount'] === true){ 
            return $Read->getResult()[0]['qtd'] ?? 0;
        }

        if($Read->getRowCount() == 0){
            return FALSE;
        }

        $result = $Read->getResult();

        foreach($result as &$item){
            $item = Model::serializeData($this->obj::class, $item, $this->query['asArray'], $this->query['leftJoins']);
        }

        if($Read->getRowCount() == 1 && $this->query['limit'] == 1){
            return $this->query['asArray'] === true ? (array) $result[0] : $result[0]; 
        }else if($Read->getRowCount() >= 1){
            return $result;
        }
    }

    public function count() : int{
        $this->query['isCount'] = true;
        return $this->execute();
    }

    public function countAll() : int{
        $countTotal = clone $this;
        $countTotal->query['isCount'] = TRUE;
        $countTotal->query['limit'] = NULL;
        $countTotal->query['offset'] = NULL;
        $countTotal->query['order'] = NULL;
        $countTotal->query['groupBy'] = NULL;

        return $countTotal->execute();
    }

    public function paginator(int $page = 1, int $itensPerPage = 25, $callbackFunction = null) : object|array|bool|null{
        if($page < 1){
            $page = 1;
        }

        $initIn = $page == 1 ? 0 : (($page - 1) * $itensPerPage);

        $this->query['asArray'] = true;
        $this->query['limit'] = $itensPerPage;
        $this->query['offset'] = $initIn;

        array_unshift($this->query['fields'], 'COUNT(' . $this->generateField([$this->obj::class, Model::getPrimaryKey($this->obj::class)[0]]) . ') OVER () AS totalItensFromPagination');

        $itens = $this->execute();
        if($itens == false){
            $itens = [];
        }

        $total = isset($itens[0]['totalItensFromPagination']) ? $itens[0]['totalItensFromPagination'] : 0;
        
        $estimatedPages = ceil(
            $total / $itensPerPage
        );

        $paginatorData = [
            'itens' => [
                'total' => $total,
                'perPage' => $itensPerPage,
                'inThisPage' => count($itens)
            ],
            'pages' => [
                'atual' => $page,
                'estimated' => $estimatedPages,
                'hasBefore' => $page <= 1 ? false : true,
                'hasAfter' => $page >= $estimatedPages ? false : true
            ]
        ];

        array_walk($itens, function (&$item) {
            unset($item['totalItensFromPagination']);
        });

        $result = [
            "itens" => $itens,
            "paginator" => $paginatorData
        ];

        if(empty($callbackFunction)){
            return $result;
        }else{
            return call_user_func($callbackFunction, $result);
        }
    }

    public function query($query, $parseString = null, $database = 'default'){
        if(!isset($this->query['fields']) || empty($this->query['fields'])){
            $query = "* FROM " . $this->handleTableName() . " " . $query;
        }else{
            $fieldsQuery = implode(", ", $this->query['fields']);
            $query = $fieldsQuery . " FROM " . $this->handleTableName() . " " . $query;
        }

        if(!empty($parseString) && is_array($parseString)){
            $stringParseString = "";
            $countParses = 1;
            foreach($parseString as $key => $value){
                $stringParseString .= $key . "=" . $value;
                if($countParses < count($parseString)){
                    $stringParseString .= "&";
                }
                $countParses++;
            }
        }else{
            $stringParseString = null;
        }

        $Read = new \Prospera\Database\Read($this->obj->databaseConnect ?? null);
        $Read->exe(
            $this->obj->table,
            $query,
            $stringParseString,
            $database,
            true
        ); 

        return $this->queryResult($Read);
    }

    public function exist() : bool{
        $this->query['limit']       = 1;
        $this->query['fields']      = ['1'];
        $this->query['asArray']     = TRUE;
        $executeSelect = $this->execute();

        if($executeSelect && !empty($executeSelect)){
            return TRUE;
        }

        return FALSE;
    }

    public function sum(string $field) : float{
        $this->query['limit']       = 1;
        $this->query['fields']      = ['SUM(' . $this->generateField($field) . ') AS result'];
        $this->query['asArray']     = TRUE;
        $executeSelect = $this->execute();

        if($executeSelect && isset($executeSelect['result']) && !empty($executeSelect['result'])){
            return (float)$executeSelect['result'];
        }

        return 0;
    }

    public function getAllFields($class){
        $driver = !empty($this->configDb['driver']) ? $this->configDb['driver'] : DBDriver::MySQL;
        $columns = Model::getColumnByProp($class);

        if(!empty($columns) && is_array($columns)){
            if($driver == DBDriver::MySQL){
                return implode(',', array_map(function($item){
                    return '`' . Model::getTable($this->obj::class) . '`.`' . $item . '`';
                }, $columns));
            }

            if($driver == DBDriver::SQLServer){
                return implode(',', array_map(function($item){
                    return '[' . Model::getTable($this->obj::class) . '].[' . $item . ']';
                }, $columns));
            }
        }

        return '`' . Model::getTable($this->obj::class) . '`.*';
    }

    public function leftJoinAndSelect(array|string $table, string $attr, string $query, array $fields = []){
        $driver = !empty($this->configDb['driver']) ? $this->configDb['driver'] : DBDriver::MySQL;
        $columns = Model::getColumnByProp((is_array($table) ? $table[0] : $table), $fields);

        if(!empty($columns) && is_array($columns)){
            if($driver == DBDriver::MySQL){
                $fieldsToAdd = array_map(function($item) use ($table, $attr){
                    $field = (is_array($table) ? $table[1] : $table) . '.' . $item . ' AS ' . str_replace('.', '#', $attr) . '_' . $item;
                    return $this->generateField($field);
                }, $columns);
            }

            if($driver == DBDriver::SQLServer){
                $fieldsToAdd = implode(',', array_map(function($item){
                    return '[' . Model::getTable($this->obj::class) . '].[' . $item . ']';
                }, $columns));
            }
        }

        $this->query['fields'] = array_merge((!empty($this->query['fields']) ? $this->query['fields'] : []), $fieldsToAdd);

        $this->query['leftJoins'][] = [
            'table'         => $table, 
            'query'         => $query,
            'andSelect'     => $attr,
        ];

        return $this;
    }
}