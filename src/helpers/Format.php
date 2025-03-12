<?php

namespace Psf\Helper;

class Format{
	public static function parseDate(string $date, string $outputFormat = 'd/m/Y') : bool|string{
		$formats = ['d/m/Y', 'd/m/Y H', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d', 'Y-m', 'm-d', 'Y-m-d H', 'Y-m-d H:i', 'Y-m-d H:i:s', 'H:i:s', 'H:i'];

		if(in_array($outputFormat, $formats)){
			foreach($formats as $format){
				$dateObj = \DateTime::createFromFormat($format, $date);

				if($dateObj !== false){
					return $dateObj->format($outputFormat);
					break;
				}
			}
		}

		return FALSE;
	}

	public static function phone($number = null) : bool|string|null{
		if(empty($number)){
			return NULL;
		}

		$number = preg_replace("/[^0-9]/", "", $number);

		if(!empty($number)){
			$firstDigits = substr($number, 0, 4);
			$specials = ['0300', '0500', '0800', '0900', '3003', '4003', '4004'];

			$size = strlen($number);

			if(in_array($firstDigits, $specials)){
				$formated = "";

				if($size == 11){
					$formated = substr($number, 0, 4) . ' ' . substr($number, 4, 3) . ' ' . substr($number, -4);
				}else if($size == 8){
					$formated = substr($number, 0, 4) . " " . substr($number, -4);
				}else{
					return false;
				}
			}else{
				$formated = substr($number, 2, ($size == 11 ? 5 : 4)) . '-' . substr($number, -4);
				if($size > 8){
					$formated = "(" . substr($number, 0, 2) . ") " . $formated;
				}
			}

			return $formated;
		}

		return FALSE;
	}

	public static function document($number) : null|string{
		if(empty($number)){
			return NULL;
		}

		$number = preg_replace("/[^0-9]/", "", $number);

		if(strlen($number) === 11){
			return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $number);
		}else if(strlen($number) === 14){
			return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $number);
		}

		return $number;	
	}

	public static function cep($number) : int|string|null{
		if(empty($number)){
			return NULL;
		}

		$number = preg_replace("/[^0-9]/", "", $number);

        $matches = [];
        preg_match('/([0-9]{1,2})([0-9]{3,3})([0-9]{3})?$/', $number, $matches);
        
        if($matches){
            return  $matches[1] . '' . $matches[2] . '-' . $matches[3];
        }
    
        return $number;
	}

	public static function ITF14Format($code){ // Formatar Código de Barras de Boleto - Formato Brasileiro ITF14
		$stringReturn = "";

		$stringCode = $code;

		$stringReturn .= substr($code, 0, 5);
		$stringReturn .= '.';

		$stringReturn .= substr($code, 5, 5);
		$stringReturn .= ' ';

		$stringReturn .= substr($code, 10, 5);
		$stringReturn .= '.';

		$stringReturn .= substr($code, 15, 6);
		$stringReturn .= ' ';

		$stringReturn .= substr($code, 21, 5);
		$stringReturn .= '.';

		$stringReturn .= substr($code, 26, 6);
		$stringReturn .= ' ';

		$stringReturn .= substr($code, 32, 1);
		$stringReturn .= ' ';

		$stringReturn .= substr($code, 33, 14);

		return $stringReturn;
	}

	public static function cardNumber(int|string $number) : int|string{
    	return trim(chunk_split($number, 4, ' '));
	}
}