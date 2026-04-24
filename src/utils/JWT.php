<?php

namespace Psf\Utils;

class JWT{
    private static function base64urlEncode($data){
        return str_replace(['+','/','='], ['-','_',''], base64_encode($data));
    }
 
    private static function base64urlDescode($string){
        return base64_decode(str_replace(['-','_'], ['+','/'], $string));
    }
 
    public static function encode(array $payload){
        $header = json_encode([
            "alg" => "HS256",
            "typ" => "JWT"
        ]);
 
        $payload = json_encode($payload);
     
        $header_payload = static::base64urlEncode($header) . '.'. 
                        static::base64urlEncode($payload);
 
        $signature = hash_hmac('sha256', $header_payload, \PSF::getConfig()->jwt['secret'], true);

        return static::base64urlEncode($header) . '.' .
        static::base64urlEncode($payload) . '.' .
        static::base64urlEncode($signature);
    }
 
    public static function decode(string $token, bool $valid){
        $parts = explode('.', $token);
        if(count($parts) !== 3){
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        if($valid){
            $expectedSig = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, \PSF::getConfig()->jwt['secret'], true);
            // hash_equals previne timing attacks por comparação em tempo constante
            if(!hash_equals($expectedSig, static::base64urlDescode($signatureB64))){
                return false;
            }
        }

        $payload = json_decode(static::base64urlDescode($payloadB64), true);

        if($valid && is_array($payload)){
            $now = time();
            if(isset($payload['exp']) && $now > $payload['exp']){
                return false;
            }
            if(isset($payload['nbf']) && $now < $payload['nbf']){
                return false;
            }
        }

        return $payload;
    }
}