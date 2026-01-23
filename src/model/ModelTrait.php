<?php

namespace Psf\Model;

trait ModelTrait{
    public static function find($startWith = NULL) : ModelQuery {
        return new ModelQuery(self::class, $startWith);
    }

    public static function findById(int $id) {
        if(!property_exists(self::class, 'id')){
            throw new \Exception("A propriedade 'id' não foi encontrada na classe '" . self::class . "'.");
        }
        
        return (new ModelQuery(self::class))
        ->andWhere([self::class . '.id' => $id])
        ->one();
    }

    public function __call($function, $value){
        $attrb = strtolower(substr($function, 3));
        $func = strtolower(substr($function, 0, 3));

        if($func == "set"){
            $this->$attrb = $value[0];
            return $this;
        }else if($func == "get"){
            return $this->$attrb;
        }else if(function_exists($function)){
            call_user_func($function);
        }else{
            // Http::response("A variável/função '" . $function . "' não foi encontrada...", [], 500);
        }
    }

    public function hasError(){
        if(property_exists($this, 'error')){
            if(!empty($this->error)){
                return TRUE;
            }
        }

        return FALSE;
    }
}