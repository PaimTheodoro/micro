<?php

namespace Psf\Helper;

class Miscellaneous{
	public static function calculeAge($date){
		$dateStart = new \DateTime($date); 
		$dateNow = new \DateTime(date('Y-m-d H:i:s'));

		$dateDiff = $dateStart->diff($dateNow);

		return $dateDiff;
	}

	public static function nextDayUtil($date = null){
		$returnData = null;

		if(empty($date)){
			$date = date("Y-m-d");
		}

		for($i = 1; $i < 15; $i++){
			$nextDay = date('Y-m-d', strtotime('+' . $i . ' days', strtotime($date)));
			$nextWeekDay = date('w', strtotime($nextDay));
					
			if($nextWeekDay != 0 && $nextWeekDay != 6){

				// Verifica se não é feriado nacional
				$fileName = "holidays" . date("Y", strtotime($nextDay)) . ".json";

				$yearHolidays = [];

				if(is_file(__DIR__ . "/../storage/" . $fileName)){
					$yearHolidays = json_decode(file_get_contents(__DIR__ . "/../storage/" . $fileName), true) ?? [];
				}else{
					$Apis = new \Psf\Helper\Apis;
					$getHolidays = $Apis->BrasilApiHolidays(date("Y", strtotime($nextDay)));

					if($getHolidays != false){
						$arrToSave = [];

						foreach($getHolidays as $item){
							$arrToSave[$item['date']] = $item['name'];
						}

						$file = fopen(__DIR__ . "/../storage/" . $fileName, 'w');
				        fwrite($file, json_encode($arrToSave));
				        fclose($file);

				        $yearHolidays = $arrToSave;
					}
				}

				if(!empty($yearHolidays) && count($yearHolidays) > 0){
					if(!isset($yearHolidays[date("Y-m-d", strtotime($nextDay))])){
						$returnData = date("Y-m-d", strtotime($nextDay));
						break;
					}
				}else{
					$returnData = date("Y-m-d", strtotime($nextDay));
					break;
				}
			}
		}

		return $returnData;
	}

	public static function monthName($number){
		$arrMonths = array(
			"01" => "Janeiro",
			"02" => "Favereiro",
			"03" => "Março",
			"04" => "Abril",
			"05" => "Maio",
			"06" => "Junho",
			"07" => "Julho",
			"08" => "Agosto",
			"09" => "Setembro",
			"10" => "Outubro",
			"11" => "Novembro",
			"12" => "Dezembro"
		);

		if(isset($arrMonths[$number])){
			return strtolower($arrMonths[$number]);
		}else{
			return "";
		}
	}

	public static function pixKeyType($key){
		if(!empty($key)){
			if(Valid::email($key)){
				return 'email';
			}else if(Valid::cpf($key)){
				return 'cpf';
			}else if(Valid::cnpj($key)){
				return 'cnpj';
			}else if(Valid::pixRandomKey($key)){
				return 'randomic';
			}
		}

		return false;
	}
}