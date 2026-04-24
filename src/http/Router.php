<?php

namespace Psf\Http;

use \Psf\Http\{StatusCode, Http, ApiDocsGenerator, RouteCacheManager};
use \Psf\Enumerators\HttpStatusCode;

class Router{
	private array 	$routes 	= [];
	private ?string $method 	= null;
	private ?int 	$version 	= 0;
	private ?int 	$pieces 	= 0;
	private ?array 	$piecesArr 	= [];
	private ?array 	$fields 	= [];
	public static 	$auth 		= [];
	private static  $patterns  	= [
		'uuid4' 	=> "/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/i",
		'int'       => "/^\\d+$/", // Apenas números inteiros positivos
		'string'    => "/^[a-zA-Z0-9_@=\-;]+$/", // Alfanumérico e underscore
		'slug'      => "/^[a-z0-9-]+$/", // Slug de URL (letras minúsculas, números e hífen)
	];

	public function __construct(...$args){
		$this->method = filter_input(\INPUT_SERVER, 'REQUEST_METHOD', \FILTER_SANITIZE_SPECIAL_CHARS);

		$cacheManager = new RouteCacheManager();
        $this->routes = $cacheManager->getRoutes();
	}

	private function setRoute(array $route){
		$this->routes[] = $route;
	}

	private function clearUrl(string $url) : string{
		if(substr($url, 0, 1) == '/'){
			$url = substr($url, 1);
		}
		
		if(substr($url, -1) == '/'){
			$url = substr($url, 0, -1);
		}

		return $url;
	}

	private function saveLoggin(string $url, array $route, null|array $response = []){
		$middlewares = isset($route['attributes']['arguments']['middlewares']) && is_array($route['attributes']['arguments']['middlewares']) ? $route['attributes']['arguments']['middlewares'] : [];

		if(in_array('loggin', $middlewares) && !in_array('webview', $middlewares)){
			$logRequest = \PSF::getConfig()->settings['logrequest'] ?? FALSE;
			if(!empty($logRequest) && $logRequest != FALSE){
				$logObj = new $logRequest[0];
				if(is_callable([$logObj, $logRequest[1]])){
					call_user_func_array([$logObj, $logRequest[1]], [
						'endpoint'	=> $url,
						'version'	=> $route['attributes']['arguments']['version'],
						'method'	=> $route['attributes']['arguments']['method'],
						'body'		=> $this->getBody(),
						'headers'	=> apache_request_headers() ?? [], 
						'response' 	=> isset($response[1]) && !empty($response[1]) ? [
							'message' 	=> $response[0],
							'data'		=> $response[1],
						] : [
							'message' => $response[0]
						],
						'httpCode' 	=> $response[2] ?? HttpStatusCode::OK->value,
					]);
				}
			}
		}
	}

	private function callMethodRoute(){
        $urlFind = self::clearUrl($_GET['_url']);
        $matchedRoute = $this->findMatchingRoute($urlFind);

        if(empty($matchedRoute)){
            throw new \Exception("Não foi possível encontrar a rota correspondente.", HttpStatusCode::NOT_FOUND->value);
        }

        $this->executeMiddlewares($matchedRoute, $urlFind);
        
        $this->fields = $this->extractUrlParameters($matchedRoute, $this->piecesArr);

        return $this->dispatchController($matchedRoute);
    }

    /**
     * Encontra a rota que corresponde à URL e ao método da requisição.
     * @param string $url A URL da requisição.
     * @return array|null A rota correspondente ou nulo se não encontrada.
     */
    private function findMatchingRoute(string $url): ?array{
        if(substr($url, 0, 1) == 'v' && is_numeric(substr($url, 1, 1))){
            $this->version = (int) substr($url, 1, 1);
            $url = substr($url, 3);
        }

        $this->piecesArr = explode("/", $url);
        $this->pieces = count($this->piecesArr);

        // 1. Primeiro, tenta encontrar uma rota EXATA (sem parâmetros dinâmicos)
        foreach($this->routes as $route){
            if(
                $route['attributes']['arguments']['method'] === $this->method &&
                (empty($this->version) || $route['attributes']['arguments']['version'] === $this->version)
            ){
                $routePath = $route['attributes']['arguments']['path'];
                // Verifica se a rota não tem parâmetros dinâmicos
                if(!str_contains($routePath, '{') && $this->clearUrl($routePath) === $url){
                    return $route;
                }
            }
        }

        // 2. Se não encontrou exata, faz o matching dinâmico (como já faz hoje)
        $potentialRoutes = array_filter($this->routes, function ($item){
            if($item['attributes']['arguments']['method'] !== $this->method) return false;
            if(!empty($this->version) && $item['attributes']['arguments']['version'] !== $this->version) return false;
            if(count(explode("/", $item['attributes']['arguments']['path'])) !== $this->pieces) return false;
            return true;
        });

        foreach($potentialRoutes as $route){
            $pathPieces = explode("/", $route['attributes']['arguments']['path']);
            $isMatch = true;

            for($i = 0; $i < $this->pieces; $i++){
                $routePiece = $pathPieces[$i];
                $urlPiece = $this->piecesArr[$i];

                if(str_starts_with($routePiece, '{') && str_ends_with($routePiece, '}')){
                    $paramParts = explode(":", substr($routePiece, 1, -1), 2);
                    $paramName = $paramParts[0];
                    $expression = $paramParts[1] ?? null;
                    if($expression){
                        if(isset(self::$patterns[$expression])){
                            $pattern = self::$patterns[$expression];
                        } else {
                            $pattern = '/' . $expression . '/';
                        }
                        set_error_handler(function() {}, E_WARNING);
                        $isValidRegex = @preg_match($pattern, $urlPiece) !== false;
                        restore_error_handler();
                        if(!$isValidRegex || !preg_match($pattern, $urlPiece)){
                            $isMatch = false;
                            break;
                        }
                    }
                } else if ($routePiece !== $urlPiece){
                    $isMatch = false;
                    break;
                }
            }

            if($isMatch){
                return $route;
            }
        }

        return null;
    }

    /**
     * Executa os middlewares definidos para uma rota.
     * @param array $route A rota a ser processada.
     * @param string $url A URL original para fins de log.
     */
    private function executeMiddlewares(array $route, string $url): void{
        $middlewares = $route['attributes']['arguments']['middlewares'] ?? [];

        if(in_array('authentication', $middlewares)){
            $this->runAuthMiddleware($route, $url);
        }
    }

    /**
     * Executa o middleware de autenticação.
     */
    private function runAuthMiddleware(array $route, string $url): void{
        $verifyAuth = \PSF::getConfig()->settings['verifyauth'] ?? false;
        if(empty($verifyAuth)){
            throw new \Exception("Middleware de autenticação não configurado.", HttpStatusCode::UNAUTHORIZED->value);
        }

        $objVerify = new $verifyAuth[0]();
        if(!is_callable([$objVerify, $verifyAuth[1]])){
            throw new \Exception("Método de autenticação não é chamável.", HttpStatusCode::INTERNAL_SERVER_ERROR->value);
        }

        $doValid = call_user_func([$objVerify, $verifyAuth[1]]);
        if($doValid === true || is_object($doValid)){
            self::$auth = $doValid;
        } else {
            $responseData = is_bool($doValid) ? null : (is_array($doValid) ? $doValid : ['msg' => $doValid]);
            $this->saveLoggin($url, $route, ["Erro ao validar a autenticação", $responseData, HttpStatusCode::UNAUTHORIZED->value]);
            Http::response("Erro ao validar a autenticação", $responseData, HttpStatusCode::UNAUTHORIZED->value);
            throw new \Exception("Autenticação falhou.", HttpStatusCode::UNAUTHORIZED->value);
        }
    }
    
    /**
     * Extrai os parâmetros dinâmicos da URL.
     * @param array $route A definição da rota.
     * @param array $urlPieces Os segmentos da URL da requisição.
     * @return array Os parâmetros extraídos.
     */
    private function extractUrlParameters(array $route, array $urlPieces): array{
        $fields = [];
        $pathPieces = explode("/", $route['attributes']['arguments']['path']);
        foreach($pathPieces as $key => $piece){
            if(str_starts_with($piece, '{') && str_ends_with($piece, '}')){
                $paramName = explode(":", substr($piece, 1, -1))[0];
                $fields[$paramName] = $urlPieces[$key];
            }
        }
        return $fields;
    }

    /**
     * Instancia e chama o método do controller da rota.
     * @param array $route A rota a ser despachada.
     * @return mixed O resultado do método do controller.
     */
    private function dispatchController(array $route){
        $className = $route['class'];
        $methodName = $route['name'];

        if(!is_callable([new $className(), $methodName])){
            throw new \Exception("Controller ou método não encontrado.", HttpStatusCode::NOT_FOUND->value);
        }
        
        try{
            $response = call_user_func_array([new $className(), $methodName], $this->fields);
            $this->saveLoggin($_GET['_url'], $route, $response);
            return $response;
        } catch (\Exception $e){
            // Opcional: Logar o erro $e
            throw new \Exception("Erro ao executar a rota.", HttpStatusCode::INTERNAL_SERVER_ERROR->value, $e);
        }
    }

	private function getBody(): array{
		return RequestParser::parseBody();
	}

	public function handle(){
		if(isset(\PSF::getConfig()->settings['docsapi'])){
			$docsApiUrl = \PSF::getConfig()->settings['docsapi'];


			if(self::clearUrl($_GET['_url']) === $docsApiUrl){
				$generator = new ApiDocsGenerator($this->routes);
                $generator->generate();
				die;
			}
		}

		return $this->callMethodRoute();
	}
}