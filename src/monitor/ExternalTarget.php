<?php

namespace Psf\Monitor;

/**
 * Gera rótulos seguros (`target`) para as chamadas externas registradas pelo monitor.
 *
 * Nunca lança e nunca deixa segredo passar: HTTP vira somente o host em
 * minúsculas (sem esquema, usuário:senha, porta, caminho, query nem fragmento;
 * IPv6 sem colchetes), SMTP vira o host do servidor e storage vira
 * `provedor/operação`. Entrada vazia ou inválida vira `(invalido)` (em storage,
 * só a parte inválida é substituída). A saída tem no máximo 191 caracteres
 * (corte sem quebrar UTF-8).
 *
 * A URL é analisada à mão, em passada única e sem regex com quantificadores
 * aninhados, para não depender das peculiaridades do `parse_url` (esquema
 * relativo, `@` na senha, colchetes do IPv6) e manter custo linear.
 */
final class ExternalTarget
{
    /** Rótulo usado quando a entrada é vazia ou inválida. */
    public const INVALID = '(invalido)';

    /** Tamanho máximo (em caracteres) do rótulo, igual à coluna `target`. */
    public const MAX_LENGTH = 191;

    /** Bytes lidos da URL: o host sempre aparece antes, o resto é descartado. */
    private const MAX_INPUT_BYTES = 4096;

    /** Rótulo seguro para uma URL HTTP/HTTPS: somente o host. */
    public static function fromUrl(string $url): string
    {
        try {
            $host = self::hostFromUrl(substr($url, 0, self::MAX_INPUT_BYTES), strlen($url) > self::MAX_INPUT_BYTES);
        } catch (\Throwable) {
            $host = null;
        }
        return self::finish($host);
    }

    /** Rótulo seguro para o servidor SMTP: somente o host. */
    public static function smtp(string $host): string
    {
        try {
            $h = strlen($host) > self::MAX_INPUT_BYTES ? null : self::validHost($host);
        } catch (\Throwable) {
            $h = null;
        }
        return self::finish($h);
    }

    /** Rótulo seguro para storage: `provedor/operação`. */
    public static function storage(string $provider, string $operation): string
    {
        try {
            // acima do teto a entrada é inválida (nada de corte silencioso)
            $p = strlen($provider) > self::MAX_INPUT_BYTES ? '' : $provider;
            $o = strlen($operation) > self::MAX_INPUT_BYTES ? '' : $operation;
            $p = ($p !== '' && strspn($p, self::PROVIDER_CHARS) === strlen($p)) ? strtolower($p) : self::INVALID;
            $o = ($o !== '' && strspn($o, self::OPERATION_CHARS) === strlen($o)) ? strtolower($o) : self::INVALID;
            return self::cut($p . '/' . $o);
        } catch (\Throwable) {
            return self::INVALID . '/' . self::INVALID;
        }
    }

    private const PROVIDER_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';
    private const OPERATION_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
    private const SCHEME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+.-';

    private static function hostFromUrl(string $url, bool $truncated): ?string
    {
        if (str_starts_with($url, '//')) {
            $rest = substr($url, 2);
        } else {
            $pos = strpos($url, '://');
            if ($pos === false || $pos === 0) {
                return null;
            }
            $scheme = substr($url, 0, $pos);
            if (strspn($scheme, self::SCHEME_CHARS) !== $pos) {
                return null;
            }
            if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
                return null;
            }
            $rest = substr($url, $pos + 3);
        }

        $authLen = strcspn($rest, '/?#');
        // Seção da autoridade que passa do corte: o `@` pode ter sido cortado
        // e o userinfo viraria host. Na dúvida, inválido.
        if ($truncated && $authLen === strlen($rest)) {
            return null;
        }
        $authority = substr($rest, 0, $authLen);
        // `\` não é terminador: curl (RFC 3986) o mantém no userinfo, e
        // navegadores o tratam como `/`. Como os dois discordam, inválido.
        if (str_contains($authority, '\\')) {
            return null;
        }
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if ($authority === '') {
            return null;
        }

        if ($authority[0] === '[') {
            $end = strpos($authority, ']');
            if ($end === false) {
                return null;
            }
            $tail = substr($authority, $end + 1);
            if ($tail !== '' && !self::validPort($tail)) {
                return null;
            }
            $ip = substr($authority, 1, $end - 1);
            // entre colchetes só vale IPv6 (sempre com `:`)
            return str_contains($ip, ':') ? self::validHost($ip) : null;
        }

        $colon = strrpos($authority, ':');
        if ($colon !== false) {
            if (!self::validPort(substr($authority, $colon))) {
                return null;
            }
            $authority = substr($authority, 0, $colon);
        }
        return self::validHost($authority);
    }

    /** Aceita `:` ou `:dígitos` (o chamador já passa a partir do `:`). */
    private static function validPort(string $s): bool
    {
        if ($s === '' || $s[0] !== ':') {
            return false;
        }
        $digits = substr($s, 1);
        return $digits === '' || (strlen($digits) <= 5 && ctype_digit($digits));
    }

    /** Host minúsculo, sem ponto final, ou null se inválido (controle, espaço, `@`, etc.). */
    private static function validHost(string $host): ?string
    {
        if ($host === '') {
            return null;
        }
        if (str_contains($host, ':')) {
            // só IPv6 puro pode ter `:`
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? strtolower($host) : null;
        }
        $host = rtrim($host, '.');
        if ($host === '') {
            return null;
        }
        // Só ASCII seguro e bytes de UTF-8 (IDN cru); o resto é rejeitado.
        $len = strlen($host);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($host[$i]);
            $ok = $c >= 0x80
                || ($c >= 0x30 && $c <= 0x39) || ($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A)
                || $c === 0x2E || $c === 0x2D || $c === 0x5F;
            if (!$ok) {
                return null;
            }
        }
        if (!mb_check_encoding($host, 'UTF-8') || preg_match('/[\p{Z}\p{C}]/u', $host) === 1) {
            return null;
        }
        return mb_strtolower($host, 'UTF-8');
    }

    private static function finish(?string $host): string
    {
        return $host === null || $host === '' ? self::INVALID : self::cut($host);
    }

    private static function cut(string $s): string
    {
        return mb_strlen($s, 'UTF-8') > self::MAX_LENGTH ? mb_substr($s, 0, self::MAX_LENGTH, 'UTF-8') : $s;
    }
}
