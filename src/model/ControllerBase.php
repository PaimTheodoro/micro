<?php

namespace Psf\Model;

use \Psf\Utils\JWT;
use \Psf\Database\Connect;
use \Psf\Http\RequestParser;

class ControllerBase{
	public ?string $method = null;
	public ?array  $data   = null;
	public ?string $token  = null;

	public function __construct(){
		$this->method = filter_input(\INPUT_SERVER, 'REQUEST_METHOD', \FILTER_SANITIZE_SPECIAL_CHARS) ?: ($_SERVER['REQUEST_METHOD'] ?? null);
		$this->data   = RequestParser::parseBody();
		$this->token  = RequestParser::extractBearerToken();
	}

	public function isGet(): bool {
		return strtoupper((string) $this->method) === "GET";
	}

	public function isPost(): bool {
		return strtoupper((string) $this->method) === "POST";
	}

	public function isPut(): bool {
		return strtoupper((string) $this->method) === "PUT";
	}

	public function isDelete(): bool {
		return strtoupper((string) $this->method) === "DELETE";
	}

	public function initTransaction($database = 'default'){
		Connect::initTransaction($database);
	}

	public function rollBackTransaction($database = 'default'){
		Connect::rollBackTransaction($database);
	}

	public function commitTransaction($database = 'default'){
		Connect::commitTransaction($database);
	}

	public function runServiceTransaction(callable $action, $database = 'default'): array {
		$this->initTransaction($database);

		try{
			$result = $action();
		}catch(\Throwable $e){
			// Sem isto a exceção deixaria a transação aberta
			$this->rollBackTransaction($database);
			throw $e;
		}

		if(isset($result[2]) && $result[2] >= 400){
			$this->rollBackTransaction($database);
		}else{
			$this->commitTransaction($database);
		}

		return $result;
	}
}