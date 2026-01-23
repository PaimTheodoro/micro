<?php

namespace Psf\Helper;

use \Psf\Http\Http;

class IP{
	protected static $url = "https://ipwho.is/";

	public static function getInfo(string|null $ip = null) : array{
		$framework = true;

		if(empty($ip)){
			$client = @$_SERVER['HTTP_CLIENT_IP'];
			$forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
			$remote = $_SERVER['REMOTE_ADDR'];

			if(filter_var($client, FILTER_VALIDATE_IP)){
				$ip = $client;
			}else if(filter_var($forward, FILTER_VALIDATE_IP)){
				$ip = $forward;
			}else{
				$ip = $remote;
			}
		}else{
			$framework = false;
		}

		if($ip == '127.0.0.1'){
			$ip = '8.8.8.8';
		}

		$request = Http::request("GET", self::$url . $ip, [], []);

		if(isset($request['response'])){
			$responseIp = $request['response'];

			if(isset($responseIp['success'])){
				if($responseIp['success'] == true){
					return [
						"framework" => $framework,
						"ip" => [
							"send" => $ip,
							"apiFind" => $responseIp['ip'],
							"changed" => $ip != $responseIp['ip'] ? true : false
						],
						"geolocation" => [
							"city" => $responseIp['city'] ?? NULL,
							"region" => [
								"name" => $responseIp['region'] ?? NULL,
								"code" => $responseIp['region_code'] ?? NULL
							],
							"country" => [
								"name" => $responseIp['country'] ?? NULL,
								"code" => $responseIp['country_code'] ?? NULL
							],
							"coordenates" => [
								"lat" => $responseIp['latitude'] ?? NULL,
								"lng" => $responseIp['longitude'] ?? NULL,
							]
						],
						"timezone" => $responseIp['timezone']['id'] ?? NULL,
					];
				}
			}
		}
		
		return [
			"framework" 	=> false,
			"ip"			=> [
				"send"		=> $ip
			],
		];
	}


	public static function getIp(){
		$ip = null;

		$client = @$_SERVER['HTTP_CLIENT_IP'];
		$forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
		$remote = $_SERVER['REMOTE_ADDR'];

		if(filter_var($client, FILTER_VALIDATE_IP)){
			$ip = $client;
		}else if(filter_var($forward, FILTER_VALIDATE_IP)){
			$ip = $forward;
		}else{
			$ip = $remote;
		}

		return $ip;
	}
}