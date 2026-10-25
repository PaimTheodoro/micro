<?php

namespace Psf\Http;

use Attribute;
use \Psf\Http\{StatusCode, Http, ApiDocsGenerator, RouteCacheManager};
use \Psf\Enumerators\HttpStatusCode;
use \Psf\Helper\CheckFields;
use \Psf\Monitor\{Monitor, Config as MonitorConfig};

#[Attribute]
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
		$this->method = filter_input(\INPUT_SERVER, 'REQUEST_METHOD', \FILTER_SANITIZE_SPECIAL_CHARS) ?: ($_SERVER['REQUEST_METHOD'] ?? null);

		Monitor::boot($this->method);

		$cacheManager = new RouteCacheManager();
        $this->routes = $cacheManager->getRoutes();
	}

	private function setRoute(array $route){
		$this->routes[] = $route;
	}

	/** URL da requisição (reescrita pelo servidor web em `_url`); vazia quando ausente. */
	private function requestUrl() : string{
		return (string) ($_GET['_url'] ?? '');
	}

	/**
	 * Divide um path de rota em segmentos por '/', ignorando as '/' dentro de `{...}` —
	 * necessário para regex inline como `{codigo:/^[A-Z]{3}-\d{4}$/}`.
	 */
	private function splitPath(string $path) : array{
		$pieces = [];
		$buffer = '';
		$depth  = 0;

		foreach(str_split($this->clearUrl($path)) as $char){
			if($char === '{'){
				$depth++;
			}elseif($char === '}' && $depth > 0){
				$depth--;
			}

			if($char === '/' && $depth === 0){
				$pieces[] = $buffer;
				$buffer   = '';
				continue;
			}

			$buffer .= $char;
		}
		$pieces[] = $buffer;

		return $pieces;
	}

	/**
	 * Converte a expressão de um parâmetro (`{nome:expr}`) em regex PCRE.
	 * Aceita pattern pré-definido, regex delimitada (`/^...$/`) ou expressão sem delimitador
	 * (ancorada, para não casar por substring).
	 */
	private function resolvePattern(string $expression) : string{
		if(isset(self::$patterns[$expression])){
			return self::$patterns[$expression];
		}

		if(strlen($expression) > 1 && $expression[0] === '/' && strrpos($expression, '/') > 0){
			return $expression;
		}

		return '/^(?:' . str_replace('/', '\/', $expression) . ')$/';
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
						// Sem credenciais em claro; getallheaders() existe em todo SAPI (shim no settings.php)
						'headers'	=> $this->loggableHeaders(),
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

	/** Headers da requisição para o log, sem credenciais em claro. */
	private function loggableHeaders() : array{
		$headers = getallheaders() ?: [];

		foreach($headers as $name => $value){
			if(in_array(strtolower((string) $name), ['authorization', 'proxy-authorization', 'cookie'], true)){
				$headers[$name] = '[redacted]';
			}
		}

		return $headers;
	}

	private function callMethodRoute(){
        $urlFind = self::clearUrl($this->requestUrl());
        $matchedRoute = $this->findMatchingRoute($urlFind);

        if(empty($matchedRoute)){
            Monitor::route('(unmatched)', (string) $this->method, (int) $this->version);
            throw new \Exception("Não foi possível encontrar a rota correspondente.", HttpStatusCode::NOT_FOUND->value);
        }

        Monitor::route($matchedRoute['attributes']['arguments']['path'], (string) $this->method, (int) $this->version);

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
        // Prefixo de versão: `v<N>` ou `v<N>/...` (qualquer quantidade de dígitos)
        if(preg_match('#^v(\d+)(?:/(.*))?$#', $url, $versionMatch)){
            $this->version = (int) $versionMatch[1];
            $url = $this->clearUrl($versionMatch[2] ?? '');
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

        // 2. Se não encontrou exata, faz o matching dinâmico
        $potentialRoutes = array_filter($this->routes, function ($item){
            if($item['attributes']['arguments']['method'] !== $this->method) return false;
            if(!empty($this->version) && $item['attributes']['arguments']['version'] !== $this->version) return false;
            if(count($this->splitPath($item['attributes']['arguments']['path'])) !== $this->pieces) return false;
            return true;
        });

        foreach($potentialRoutes as $route){
            $pathPieces = $this->splitPath($route['attributes']['arguments']['path']);
            $isMatch = true;

            for($i = 0; $i < $this->pieces; $i++){
                $routePiece = $pathPieces[$i];
                $urlPiece = $this->piecesArr[$i];

                if(str_starts_with($routePiece, '{') && str_ends_with($routePiece, '}')){
                    $paramParts = explode(":", substr($routePiece, 1, -1), 2);
                    $expression = $paramParts[1] ?? null;
                    // Regex inválida ou sem match → a rota não casa (preg_match devolve false/0)
                    if($expression && @preg_match($this->resolvePattern($expression), $urlPiece) !== 1){
                        $isMatch = false;
                        break;
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
            Monitor::user(self::$auth);
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
        $pathPieces = $this->splitPath($route['attributes']['arguments']['path']);
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

        // Uma única instância: o construtor lê e faz o parse do body
        $controller = new $className();

        if(!is_callable([$controller, $methodName])){
            throw new \Exception("Controller ou método não encontrado.", HttpStatusCode::NOT_FOUND->value);
        }

        try{
            // Se a rota declarou docs.fields, valida os campos `required` antes de
            // executar o método — fonte única pra doc e validação, sem chamada manual.
            $docsFields = $route['attributes']['arguments']['docs']['fields'] ?? null;
            if(!empty($docsFields)){
                CheckFields::checkFromDocsFields($docsFields, $controller->data);
            }

            $response = call_user_func_array([$controller, $methodName], $this->fields);
            $this->saveLoggin($this->requestUrl(), $route, $response);
            return $response;
        } catch (HttpResponseException $e){
            // Resposta HTTP deliberada (ex.: validação de campos) — não é falha da rota
            throw $e;
        } catch (\Exception $e){
            // Exceção com código 4xx explícito é intenção do controller: preserva status e mensagem
            $code = $e->getCode();
            if(is_int($code) && $code >= 400 && $code < 500){
                throw $e;
            }

            // Falha real (5xx): registra a exceção original, com a cadeia, antes de embrulhar
            \Psf\Monitor\Monitor::exception($e);

            throw new \Exception("Erro ao executar a rota.", HttpStatusCode::INTERNAL_SERVER_ERROR->value, $e);
        } catch (\Throwable $e){
            // \Error/TypeError do controller: registra e propaga sem alterar (como antes)
            \Psf\Monitor\Monitor::exception($e);
            throw $e;
        }
    }

	private function getBody(): array{
		return RequestParser::parseBody();
	}

	/**
	 * Aplica CORS conforme settings.cors e responde o preflight (204) sem chegar às rotas.
	 */
	private function applyCors(array $config) : void{
		// Expõe o X-Request-Id ao browser apenas quando o monitor está ativo e configurado para isso
		if(MonitorConfig::enabled() && MonitorConfig::get('expose_request_id')){
			$config = Cors::withExposedHeader($config, 'X-Request-Id');
		}

		$cors = Cors::resolve(
			$config,
			$_SERVER['HTTP_ORIGIN'] ?? null,
			(string) $this->method,
			$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? null
		);

		foreach($cors['headers'] as $name => $value){
			header($name . ': ' . $value);
		}

		if($cors['preflight']){
			http_response_code(HttpStatusCode::NO_CONTENT->value);
			exit;
		}
	}

	public function handle(){
		$corsConfig = \PSF::getConfig()->settings['cors'] ?? null;
		if(!empty($corsConfig) && is_array($corsConfig)){
			$this->applyCors($corsConfig);
		}

		$docsConfig = \PSF::getConfig()->settings['docsapi'] ?? null;

		if($docsConfig){
			// Aceita o formato antigo (string = só o path, sem autenticação) e o novo
			// (array com 'path', e opcionalmente 'username'/'password' pra exigir Basic Auth).
			$docsPath = is_array($docsConfig) ? ($docsConfig['path'] ?? null) : $docsConfig;

			if($docsPath && self::clearUrl($this->requestUrl()) === $docsPath){
				if(is_array($docsConfig) && !empty($docsConfig['username'])){
					Http::requireBasicAuth($docsConfig['username'], $docsConfig['password'] ?? '', 'Documentação da API');
				}

				$generator = new ApiDocsGenerator($this->routes);
				$generator->generate();
				die;
			}
		}

		return $this->callMethodRoute();
	}
}