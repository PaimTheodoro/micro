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

        // Busca o diretório dos controllers. Garante que seja um array.
        $controllersPath = Psf->config('settings', 'controllers');
        $this->scanDirs = $controllersPath ? (array)$controllersPath : [];

        // A chave inclui um hash do app (raiz + diretórios de controllers): duas aplicações no mesmo
        // pool do APCu não compartilham nem sobrescrevem a tabela de rotas uma da outra.
        $this->key = 'psf_routes_v1_' . substr(md5((defined('ROOT') ? ROOT : '') . '|' . implode('|', $this->scanDirs)), 0, 12);

        // Busca o ambiente da aplicação. O padrão é 'dev'.
        // config() devolve false (não null) quando a chave não existe
        $environment  = Psf->config('environment');
        $this->appEnv = is_string($environment) && $environment !== '' ? $environment : 'dev';
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

    /**
     * Descarta a tabela de rotas em cache. Chame no deploy: em `prd` a chave é estática e as
     * rotas só seriam relidas quando o TTL (1h) expirasse.
     */
    public function clear(): bool{
        if(!extension_loaded('apcu') || !apcu_enabled()){
            return false;
        }

        return apcu_delete($this->getCacheKey());
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
    
    /**
     * Namespace e nome da primeira classe declarada, via tokens (ignora a palavra "class" em
     * comentários/strings, `Foo::class` e classes anônimas — o que a regex anterior confundia).
     *
     * @return array{0: string, 1: string}
     */
    private function parseNamespaceAndClass(string $code): array{
        $namespace = '';
        $className = '';
        $tokens    = token_get_all($code);
        $count     = count($tokens);

        for($i = 0; $i < $count; $i++){
            $token = $tokens[$i];
            if(!is_array($token)){
                continue;
            }

            if($token[0] === T_NAMESPACE && $namespace === ''){
                for($j = $i + 1; $j < $count; $j++){
                    $next = $tokens[$j];
                    if(is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)){
                        $namespace = $next[1];
                        break;
                    }
                    if($next === ';' || $next === '{'){
                        break;
                    }
                }
            }

            if($token[0] === T_CLASS){
                // Foo::class (constante) e `new class` (anônima) não declaram classe nomeada
                $prev = $this->previousSignificantToken($tokens, $i);
                if($prev !== null && ($prev[0] === T_DOUBLE_COLON || $prev[0] === T_NEW)){
                    continue;
                }

                for($j = $i + 1; $j < $count; $j++){
                    $next = $tokens[$j];
                    if(is_array($next) && $next[0] === T_STRING){
                        $className = $next[1];
                        break 2;
                    }
                    if(is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
                        continue;
                    }
                    break;
                }
            }
        }

        return [$namespace, $className];
    }

    private function previousSignificantToken(array $tokens, int $index): ?array{
        for($i = $index - 1; $i >= 0; $i--){
            $token = $tokens[$i];
            if(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
                continue;
            }

            return is_array($token) ? $token : [null, $token];
        }

        return null;
    }

    private function getMethodsFromFile(string $file): array{
        [$namespace, $className] = $this->parseNamespaceAndClass((string) file_get_contents($file));

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