<?php

namespace Psf\Http;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

class RouteCacheManager{
    private string $strategy;
    private string $key;
    private array $scanDirs;
    private string $appEnv;

    public function __construct(){
        $this->strategy = 'apcu';
        $this->key = 'psf_routes_v1';

        // Busca o diretório dos controllers. Garante que seja um array.
        $controllersPath = Psf->config('settings', 'controllers');
        $this->scanDirs = $controllersPath ? (array)$controllersPath : [];

        // Busca o ambiente da aplicação. O padrão é 'dev'.
        $this->appEnv = Psf->config('environment') ?? 'dev';
    }

    public function getRoutes(): array{
        if(!$this->strategy || ($this->strategy === 'apcu' && !extension_loaded('apcu'))){
            // Se o cache está desabilitado ou APCu não está disponível, gera na hora.
            return $this->generateRoutesFromFiles();
        }
        
        $cacheKey = $this->getCacheKey();
        
        // Tenta buscar do cache primeiro
        $routes = apcu_fetch($cacheKey, $success);
        if($success === true){
            return $routes;
        }

        // Se não encontrou no cache, gera as rotas e as armazena
        $routes = $this->generateRoutesFromFiles();
        apcu_store($cacheKey, $routes, 3600); // Cache por 1 hora, por segurança

        return $routes;
    }

    private function getCacheKey(): string{
        if($this->appEnv === 'prd'){
            // Em produção, a chave é estática para máxima performance
            return $this->key;
        }

        // Em desenvolvimento, a chave é dinâmica baseada no estado dos arquivos
        $fileInfo = [];
        foreach($this->scanDirs as $dir){
            if(!is_dir($dir)) continue;

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file){
                if($file->getExtension() !== 'php'){
                    continue;
                }
                $fileInfo[$file->getPathname()] = $file->getMTime();
            }
        }

        return 'dev_routes_' . md5(json_encode($fileInfo));
    }

    private function generateRoutesFromFiles(): array{
        $routes = [];
        foreach($this->scanDirs as $dir){
            if(!is_dir($dir)) continue;

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach($iterator as $file){
                if($file->getExtension() !== 'php'){
                    continue;
                }

                $methods = $this->getMethodsFromFile($file->getPathname());
                if(!empty($methods)){
                    $routes = array_merge($routes, $methods);
                }
            }
        }
        return $routes;
    }
    
    private function getMethodsFromFile(string $file): array{
        $content = file_get_contents($file);
        $namespace = '';
        if(preg_match('/namespace\s+([^;]+);/', $content, $matches)){
            $namespace = $matches[1];
        }

        $className = '';
        if(preg_match('/class\s+([^\s]+)/', $content, $matches)){
            $className = $matches[1];
        }

        if(empty($namespace) || empty($className)){
            return [];
        }

        $fullClassName = $namespace . '\\' . $className;
        if(!class_exists($fullClassName)){
            require_once $file;
        }

        $reflectionClass = new ReflectionClass($fullClassName);
        $methodsMap = [];

        foreach($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            if($method->getDeclaringClass()->getName() !== $fullClassName){
                continue;
            }

            $attributes = $method->getAttributes(Router::class);
            if(count($attributes) > 0){
                 $methodsMap[] = [
                    'class' => $fullClassName,
                    'name'  => $method->getName(),
                    'attributes' => [
                        'name' => $attributes[0]->getName(),
                        'arguments' => $attributes[0]->getArguments(),
                    ]
                ];
            }
        }
        return $methodsMap;
    }
} 