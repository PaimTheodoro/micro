<?php

namespace Psf\Http;

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

		curl_close($curl);

		return $arrReturn;
	}

	public static function response($message = "", $data = [], $status = 200, $headers = []){
		header('Content-Type: application/json');
		http_response_code($status);
		
		if(!empty($headers)){
			foreach($headers as $header => $value){
				header($header . ": " . $value);
			}
		};

		$response = [];

		if(!empty($message)){
			$response["message"] = $message;
		}

		if(!empty($data)){
			$response['data'] = $data;
		}

		echo json_encode($response);
		exit;
	}

}