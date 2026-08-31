<?php

namespace Psf\Http;

use \Psf\Enumerators\{HTTPMethod, HTTPBodyEncoded};

class Request{
	private $settings;
	private $error;

	public function __construct(){
		return $this;
	}

	public function url(string $url) : Request{
		$this->settings['url'] = $url; 

		return $this;
	}

	public function body(array|string $body, null|HTTPBodyEncoded $encoded = NULL) : bool|Request{
		if(!empty($body)){
			if(!empty($encoded) && $encoded != HTTPBodyEncoded::JSON){
				if($encoded == HTTPBodyEncoded::URLEncoded){
					if(!isset($this->settings['url']) || empty($this->settings['url'])){
						echo "Explode Erro aqui";
						return FALSE;
					}

					$this->settings['url'] = sprintf("%s?%s", $this->settings['url'], http_build_query($body));
				}else if($encoded == HTTPBodyEncoded::Multipart){
					// Body fica como array PHP puro (sem json_encode) — o cURL, ao
					// receber um array em CURLOPT_POSTFIELDS (ver send()), monta o
					// multipart/form-data com boundary sozinho. Não setar um header
					// Content-Type manual pra multipart junto com isso: o boundary
					// gerado pelo cURL não bateria com um Content-Type fixo.
					$this->settings['body'] = $body;
				}
			}else{
				$this->settings['body'] = json_encode($body);
			}
		}

		return $this;
	}

	public function headers(array $headers) : Request{
		if(!empty($headers)){
			foreach ($headers as $key => $value) {
				$this->settings['headers'][] = $key . ": " . $value;
			}
		}

		return $this;
	}

	public function send(){
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->settings['method']->name);
		curl_setopt($curl, CURLOPT_URL, $this->settings['url']);

		if(isset($this->settings['body']) && !empty($this->settings['body'])){
			curl_setopt($curl, CURLOPT_POSTFIELDS, $this->settings['body']);
		}
		
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);

		if(isset($this->settings['headers']) && !empty($this->settings['headers'])){
			curl_setopt($curl, CURLOPT_HTTPHEADER, $this->settings['headers']);
		}

		$execute = curl_exec($curl);

		if($execute === false){
			$errno = curl_errno($curl);
			$error = curl_error($curl);
			// curl_close($curl);
			$this->error = "Erro (código " . $errno . "): " . $error;
			return FALSE;
		}else{
			$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
			$timing = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
			// curl_close($curl);

			$response = new \stdClass;
			$response->code = $code;
			$response->contentType = $contentType;
			$response->timing = $timing;
	
			if(str_starts_with($response->contentType, "application/json")){
				$encoding = mb_detect_encoding($execute, 'UTF-8', true);

				if ($encoding !== 'UTF-8') {
				    $execute = mb_convert_encoding($execute, 'UTF-8');
				}

				$response->body = json_decode($execute, true);
			}else{
				$response->body = $execute;
			}	

			return $response;
		}
	}

	public static function get() : Request{
		$newRequest = new Request;
		$newRequest->settings['method']	= HTTPMethod::GET;

		return $newRequest; 
	}

	public static function post() : Request{
		$newRequest = new Request;
		$newRequest->settings['method']	= HTTPMethod::POST;

		return $newRequest;
	}

	public static function put() : Request{
		$newRequest = new Request;
		$newRequest->settings['method']	= HTTPMethod::PUT;

		return $newRequest;
	}

	public static function delete() : Request{
		$newRequest = new Request;
		$newRequest->settings['method']	= HTTPMethod::DELETE;

		return $newRequest;
	}
}
