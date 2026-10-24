<?php

namespace Psf\Utils;

class JWT{
    private static function base64urlEncode($data){
        return str_replace(['+','/','='], ['-','_',''], base64_encode($data));
    }
 
    private static function base64urlDescode($string){
        return base64_decode(str_replace(['-','_'], ['+','/'], $string));
    }
 
    /** Tamanho mínimo da secret HS256 (bytes) — RFC 7518 §3.2 recomenda >= tamanho do hash (256 bits). */
    private const MIN_SECRET_LENGTH = 16;

    private static function secret(): string{
        $secret = \PSF::getConfig()->jwt['secret'] ?? null;

        if(!is_string($secret) || strlen($secret) < self::MIN_SECRET_LENGTH){
            throw new \RuntimeException('JWT: config jwt.secret ausente ou curta demais (mínimo ' . self::MIN_SECRET_LENGTH . ' caracteres)');
        }

        return $secret;
    }

    public static function encode(array $payload){
        $header = json_encode([
            "alg" => "HS256",
            "typ" => "JWT"
        ]);

        $payload = json_encode($payload);

        $header_payload = static::base64urlEncode($header) . '.'.
                        static::base64urlEncode($payload);

        $signature = hash_hmac('sha256', $header_payload, self::secret(), true);

        return static::base64urlEncode($header) . '.' .
        static::base64urlEncode($payload) . '.' .
        static::base64urlEncode($signature);
    }

    /**
     * Valida assinatura, `exp` e `nbf` e devolve o payload (array) ou false.
     * Use este método para autenticar requisições.
     */
    public static function verify(string $token): array|false{
        $payload = static::decode($token, true);

        return is_array($payload) ? $payload : false;
    }

    /**
     * Com `$valid = false` o payload é devolvido SEM verificar assinatura nem expiração —
     * serve apenas para inspecionar um token (ex.: ler `exp` de um token já expirado).
     * Nunca use `$valid = false` para autenticar: prefira `verify()`.
     */
    public static function decode(string $token, bool $valid = true){
        $parts = explode('.', $token);
        if(count($parts) !== 3){
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        if($valid){
            $expectedSig = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, self::secret(), true);
            // hash_equals previne timing attacks por comparação em tempo constante
            if(!hash_equals($expectedSig, static::base64urlDescode($signatureB64))){
                return false;
            }
        }

        $payload = json_decode(static::base64urlDescode($payloadB64), true);

        if($valid){
            // Assinatura válida mas payload que não é objeto JSON não é um token utilizável
            if(!is_array($payload)){
                return false;
            }

            $now = time();
            if(isset($payload['exp']) && (!is_numeric($payload['exp']) || $now > $payload['exp'])){
                return false;
            }
            if(isset($payload['nbf']) && (!is_numeric($payload['nbf']) || $now < $payload['nbf'])){
                return false;
            }
        }

        return $payload;
    }
}