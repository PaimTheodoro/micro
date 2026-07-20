<?php

namespace Psf\Helper;

use \Psf\Http\Http;

class CheckFields{

	public static function check($fields){
		$errorsArr = [];

		foreach($fields as $key => $item){
			if(isset($item['isBoolean']) && $item['isBoolean'] == true){
				if($item['content'] !== false 
					&& $item['content'] !== true
					&& $item['content'] != "false"
					&& $item['content'] != "true"
				){
					$errorsArr[] = [
						"field" => $key,
						"msg" => "O campo '" . $key . "' precisa ser do tipo booleano (true/false)"
					];
				}
			}else{
				$contentCheck = null;

				if(is_array($item)){
					if(empty($item['content']) || $item['content'] == ""){
						$errorsArr[] = [
							"field" => $key,
							"msg" => "O campo '" . $key . "' não foi informado ou está nulo"
						];
					}else{
						$contentCheck = $item['content'];
					}
				}else{
					if(empty($item) || strlen($item) == 0){
						$errorsArr[] = [
							"field" => $key,
							"msg" => "O campo '" . $key . "' não foi informado ou está nulo"
						];
					}else{
						$contentCheck = $item;
					}
				}

				if($contentCheck != null){
					if(!empty($item['specialCheck'])){
						if($item['specialCheck'] == "email"){
							if(!Valid::email($item['content'])){
								$errorsArr[] = [
									"field" => $key,
									"msg" => "O campo '" . $key . "' precisa conter um endereço e-mail válido"
								];
							}
						}else if($item['specialCheck'] == "cpf"){
							if(!Valid::cpf($item['content'])){
								$errorsArr[] = [
									"field" => $key,
									"msg" => "O campo '" . $key . "' precisa conter CPF válido"
								];
							}
						}else if($item['specialCheck'] == "cnpj"){
							if(!Valid::cnpj($item['content'])){
								$errorsArr[] = [
									"field" => $key,
									"msg" => "O campo '" . $key . "' precisa conter CNPJ válido"
								];
							}
						}else if($item['specialCheck'] == "date"){
							if(!Valid::date($item['content'])){
								$errorsArr[] = [
									"field" => $key,
									"msg" => "O campo '" . $key . "' precisa conter uma data válida"
								];
							}
						}else if($item['specialCheck'] == "isArray"){
							if(!is_array($item['content'])){
								$errorsArr[] = [
									"field" => $key,
									"msg" => "O campo '" . $key . "' espera receber um array de dados"
								];
							}
						}else if($item['specialCheck'] == "array"){
							if(is_array($item['content']) && isset($item['fields'])){
								foreach ($item['fields'] as $value){
									if(empty($item['content'][$value])){
										$errorsArr[] = [
											"field" => $key,
											"msg" => "O campo '" . $key . "'->'". $value ."' precisa ser preenchido"
										];
									}
								}
							}else{
								$errorsArr[] = [
									"field" => $key,
									"msg" => "O campo '" . $key . "' espera receber um array de dados"
								];
							}
						}
					}	

					if(isset($item['accept']) && count($item['accept']) > 0){
						$isAcceptable = FALSE;

						if(isset($item['caseSensitive']) && $item['caseSensitive'] === FALSE){
							$caseSensetive = FALSE;
						}else{
							$caseSensetive = TRUE;
						}

						foreach($item['accept'] as $itemAccept){
							if(is_string($item['content'])){
								if($caseSensetive){
									if($itemAccept == $item['content']){
										$isAcceptable = TRUE;
										break;
									}
								}else{
									if(strtolower($itemAccept) == strtolower($item['content'])){
										$isAcceptable = TRUE;
										break;
									}
								}
							}else{
								if($itemAccept == $item['content']){
									$isAcceptable = TRUE;
									break;
								}
							}							
						}

						if($isAcceptable == FALSE){
							$errorsArr[] = [
								"field" => $key,
								"msg" => "O campo '" . $key . "' não contém um valor aceito por esta API",
							];
						}
					}
				}
			}
		}

		if(count($errorsArr) > 0){
			Http::response("Error validating protected items in request", $errorsArr, 400);
		}else{
			return true;
		}
	}

	/**
	 * Valida os campos marcados como `required` numa lista no formato de
	 * #[Router(docs: ['fields' => [...]])]. Ignora location 'urlcomponent'
	 * (já garantido pelo casamento de rota em Router::findMatchingRoute()).
	 */
	public static function checkFromDocsFields(array $fields, array $data): void{
		$input = [];

		foreach($fields as $field){
			if(empty($field['required']) || ($field['location'] ?? null) === 'urlcomponent'){
				continue;
			}

			$entry = ['content' => $data[$field['name']] ?? null];
			foreach(['specialCheck', 'accept', 'caseSensitive', 'isBoolean', 'fields'] as $key){
				if(isset($field[$key])){
					$entry[$key] = $field[$key];
				}
			}

			$input[$field['name']] = $entry;
		}

		if(!empty($input)){
			self::check($input);
		}
	}
}