<?php

namespace Psf\Http;

use Psf\Enumerators\HttpStatusCode;
use Psf\Monitor\{ExternalTarget, Monitor};

class Http{

	/**
	 * @deprecated Use \Psf\Http\Request::get/post/put/delete() instead.
	 *
	 * @param int $timeout        Timeout total da chamada, em segundos (0 = sem limite).
	 * @param int $connectTimeout Timeout de conexão, em segundos (0 = sem limite).
	 */
	public static function request($method, $url, $body, $headers = [], int $timeout = Request::DEFAULT_TIMEOUT, int $connectTimeout = Request::DEFAULT_CONNECT_TIMEOUT){
		$curl = curl_init();

		if($method == "GET"){
			curl_setopt($curl, CURLOPT_POST, false);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
			$url = sprintf("%s?%s", $url, http_build_query($body));
			curl_setopt($curl, CURLOPT_URL, $url);
		}else if($method == "POST"){
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
		}else{
			curl_setopt($curl, CURLOPT_POST, false);
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
		}

		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, max(0, $connectTimeout));
		curl_setopt($curl, CURLOPT_TIMEOUT, max(0, $timeout));

		if(!empty($headers)){
			$headerSend = [];
			foreach ($headers as $key => $value) {
				$headerSend[] = $key . ":" . $value;
			}
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headerSend);
		}

		// Monitor: só o host vai para o rótulo; falha do cURL ou código 0 = status null
		$doRequest = Monitor::trackExternal(
			'http',
			ExternalTarget::fromUrl((string) $url),
			fn () => curl_exec($curl),
			function ($r) use ($curl) {
				$code = $r === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
				return $code === 0 ? null : $code;
			}
		);

		$arrReturn = [];
		$arrReturn["code"] = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if(curl_getinfo($curl, CURLINFO_CONTENT_TYPE) == "application/json"){
			$arrReturn["response"] = json_decode($doRequest, true);
		}else{
			$arrReturn["response"] = json_decode($doRequest, true);
		}	

		// curl_close($curl);

		return $arrReturn;
	}

	public static function response($message = "", $data = [], $status = 200, $headers = []){
		$statusCode = is_numeric($status) ? (int) $status : ($status instanceof HttpStatusCode ? $status->value : 200);
		if($statusCode < 100 || $statusCode > 599){
			$statusCode = 500;
		}

		$response = [];

		if(!empty($message)){
			$response["message"] = $message;
		}

		if(!empty($data)){
			$response['data'] = $data;
		}

		if(defined('PSF_TESTING') && PSF_TESTING){
			throw new HttpResponseException($response, $statusCode);
		}

		// SUBSTITUTE troca bytes UTF-8 inválidos por U+FFFD em vez de fazer o encode falhar
		$json = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
		if($json === false){
			$statusCode = 500;
			$json       = json_encode(['message' => 'Erro ao codificar a resposta']);
		}

		header('Content-Type: application/json');
		http_response_code($statusCode);

		if(!empty($headers)){
			foreach($headers as $header => $value){
				// CR/LF no nome ou valor permitiria injetar headers extras
				header(str_replace(["\r", "\n"], '', (string) $header) . ": " . str_replace(["\r", "\n"], '', (string) $value));
			}
		};

		echo $json;
		exit;
	}

	/**
	 * Exige autenticação HTTP Basic (prompt nativo do navegador). Se as credenciais
	 * não baterem, responde 401 + WWW-Authenticate e encerra a execução.
	 */
	public static function requireBasicAuth(string $username, string $password, string $realm = 'Restricted'): void{
		$authUser = $_SERVER['PHP_AUTH_USER'] ?? null;
		$authPass = $_SERVER['PHP_AUTH_PW'] ?? null;

		if($authUser === null){
			$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
			if($header && str_starts_with($header, 'Basic ')){
				$decoded = base64_decode(substr($header, 6));
				[$authUser, $authPass] = array_pad(explode(':', $decoded, 2), 2, null);
			}
		}

		// hash_equals: comparação em tempo constante, sem vazar o tamanho/prefixo correto
		if(!hash_equals($username, (string) $authUser) || !hash_equals($password, (string) $authPass)){
			header('WWW-Authenticate: Basic realm="' . $realm . '"');
			http_response_code(401);
			echo 'Acesso não autorizado.';
			exit;
		}
	}

}