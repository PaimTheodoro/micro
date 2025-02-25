<?php

use Spatie\Ignition\Ignition;

Ignition::make()
->setTheme('dark')
->register();

define('DR', DIRECTORY_SEPARATOR);
define('ROOT', realpath($_SERVER['DOCUMENT_ROOT'] ?: dirname(__FILE__)));
define('Psf', (new PSF));

use \Psf\Http\Http;

class PSF{
    private static $config;

    public static function init(array $data){
        if(is_file($data['config'])){
            self::$config = include $data['config'];
        }else{
            Http::response('PGF: Framework config file not found', ["path" => $data['config']], 500);
            die;
        }
    }

    public static function getConfig(){
        return (object) self::$config;
    }

    public static function config(...$args){
        $value = self::$config;

        if(!empty($args)){
            foreach($args as $arg){
                if(!isset($value[$arg])){
                    return FALSE;
                }

                $value = $value[$arg];                
            }
        }

        return $value; 
    }
}

if(!function_exists('getallheaders')){
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function explodeException($error){
    // if(isset(\PSF::getConfig()->settings['debug']) && \PSF::getConfig()->settings['debug']){
        Http::response("Erro na execução da tarefa! Por favor, contacte o suporte.", [
            'code'  => $error->getCode(),
            'msg'   => $error->getMessage(),
            'file'  => $error->getFile(),
            'line'  => $error->getLine()
        ], 500);
    // }else{
        // Http::response('Erro na execução da tarefa! Por favor, contacte o suporte.', [], 500);
    // }
}