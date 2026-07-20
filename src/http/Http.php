<?php

namespace Psf\Http;

use Psf\Enumerators\HttpStatusCode;

class Http{

	/**
	 * @deprecated Use \Psf\Http\Request::get/post/put/delete() instead.
	 */
	public static function request($method, $url, $body, $headers = []){
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

		if(!empty($headers)){
			$headerSend = [];
			foreach ($headers as $key => $value) {
				$headerSend[] = $key . ":" . $value;
			}
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headerSend);
		}

		$doRequest = curl_exec($curl);

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
		$statusCode = is_numeric($status) ? $status : ($status instanceof HttpStatusCode ? $status->value : 200);

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

		header('Content-Type: application/json');
		http_response_code($statusCode);

		if(!empty($headers)){
			foreach($headers as $header => $value){
				header($header . ": " . $value);
			}
		};

		echo json_encode($response);
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

		if($authUser !== $username || $authPass !== $password){
			header('WWW-Authenticate: Basic realm="' . $realm . '"');
			header('HTTP/1.1 401 Unauthorized');
			echo 'Acesso não autorizado.';
			exit;
		}
	}

}