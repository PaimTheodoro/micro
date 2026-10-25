<?php

namespace Psf\Monitor;

/**
 * Normaliza SQL para virar chave de agrupamento sem dados sensíveis.
 *
 * O SQL executado pelo framework pode trazer valores interpolados no texto
 * (`Read::exe(free: true)`, QueryBuilder), então nenhum literal pode
 * sobreviver: strings, números, hexadecimais e dollar quotes viram `?`.
 * Também colapsa listas `IN (...)` só de literais/placeholders em `IN (?)`,
 * tuplas repetidas de `VALUES (...)` em `VALUES (?)`, remove comentários,
 * colapsa espaços e corta em 2000 caracteres (sem quebrar UTF-8).
 *
 * Implementado como um tokenizador de passada única (sem regex global), com
 * custo linear no tamanho da entrada. Nunca lança: em caso de falha
 * inesperada devolve string vazia. Na dúvida, o tokenizador sempre erra para
 * o lado de redigir demais (ex.: `\` escapa dentro de qualquer string, aspas
 * não terminadas consomem o resto da entrada).
 *
 * Limitação conhecida: `"..."` é tratado como identificador (padrão SQL e
 * PostgreSQL) e copiado sem alteração, exceto em posição de valor (ver
 * doubleQuoteIsValue(): após comparação, LIKE/ILIKE, THEN/ELSE ou em lista
 * IN), onde vira `?`. No MySQL sem ANSI_QUOTES, uma string entre aspas duplas
 * fora dessas posições (ex.: argumento de função) sobreviveria.
 */
final class SqlNormalizer
{
    /** Tamanho máximo (em caracteres) do SQL normalizado (`sql_text`). */
    public const MAX_LENGTH = 2000;

    /** Teto em bytes da saída intermediária: 4 bytes por caractere UTF-8 no máximo. */
    private const BYTE_BUDGET = self::MAX_LENGTH * 4 + 4;

    /** Palavras após as quais `-5`/`+5` é um número com sinal (e não subtração). */
    private const SIGN_KEYWORDS = [
        'SELECT' => true, 'WHERE' => true, 'AND' => true, 'OR' => true, 'NOT' => true,
        'ON' => true, 'SET' => true, 'THEN' => true, 'ELSE' => true, 'WHEN' => true,
        'CASE' => true, 'BY' => true, 'LIMIT' => true, 'OFFSET' => true, 'BETWEEN' => true,
        'IS' => true, 'LIKE' => true, 'ILIKE' => true, 'IN' => true, 'VALUES' => true,
        'HAVING' => true, 'RETURN' => true, 'AS' => true, 'TOP' => true, 'DEFAULT' => true,
    ];

    // Estados do renderizador
    private const NORMAL = 0;
    private const AFTER_IN = 1;
    private const IN_LIST = 2;
    private const AFTER_VALUES = 3;
    private const VALUES_TUPLE = 4;
    private const AFTER_TUPLE = 5;
    private const AFTER_TUPLE_COMMA = 6;

    private static ?string $wordChars = null;

    private string $s;
    private int $n;
    private int $i = 0;
    private ?string $prevType = null;
    private string $prevUpper = '';
    private string $out = '';

    private int $state = self::NORMAL;
    private string $buf = '';
    private bool $bufHasValue = false;
    private bool $lpSpace = false;
    private int $depth = 0;
    private bool $commaSpace = false;

    private function __construct(string $sql)
    {
        $this->s = $sql;
        $this->n = strlen($sql);
    }

    /**
     * Normaliza o SQL. Nunca lança; resultado com no máximo 2000 caracteres.
     */
    public static function normalize(string $sql): string
    {
        try {
            if ($sql === '') {
                return '';
            }
            $normalizer = new self(self::scrubUtf8($sql));
            return self::truncate($normalizer->run());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Hash de agrupamento do SQL já normalizado (sha1, 40 hex).
     */
    public static function hash(string $normalized): string
    {
        return sha1($normalized);
    }

    /**
     * Substitui bytes UTF-8 inválidos por `?` (linear, sem warnings).
     */
    private static function scrubUtf8(string $sql): string
    {
        if (preg_match('//u', $sql) === 1) {
            return $sql;
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($sql, 'UTF-8');
        }
        // Sem mbstring: descarta qualquer byte não-ASCII (redige demais, nunca de menos).
        return (string) preg_replace('/[\x80-\xff]/', '?', $sql);
    }

    /**
     * Corta em MAX_LENGTH caracteres sem quebrar caractere multibyte.
     */
    private static function truncate(string $out): string
    {
        if (strlen($out) <= self::MAX_LENGTH) {
            return $out;
        }
        if (function_exists('mb_substr')) {
            $out = mb_substr($out, 0, self::MAX_LENGTH, 'UTF-8');
        } elseif (preg_match('/\A.{0,' . self::MAX_LENGTH . '}/su', $out, $m) === 1) {
            $out = $m[0];
        } else {
            $out = '';
        }
        return rtrim($out, ' ');
    }

    /**
     * Caracteres que continuam um identificador/palavra (inclui bytes UTF-8).
     */
    private static function wordChars(): string
    {
        if (self::$wordChars === null) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$@';
            for ($b = 0x80; $b <= 0xff; $b++) {
                $chars .= chr($b);
            }
            self::$wordChars = $chars;
        }
        return self::$wordChars;
    }

    private static function isWordStart(string $c): bool
    {
        if ($c === '') {
            return false;
        }
        $o = ord($c);
        return ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || $o === 95 || $o === 64 || $o >= 0x80;
    }

    private static function isDigit(string $c): bool
    {
        return $c !== '' && $c >= '0' && $c <= '9';
    }

    private function at(int $pos): string
    {
        return $pos < $this->n ? $this->s[$pos] : '';
    }

    // ------------------------------------------------------------------
    // Renderizador (máquina de estados sobre os tokens)
    // ------------------------------------------------------------------

    private function run(): string
    {
        while (strlen($this->out) < self::BYTE_BUDGET) {
            $token = $this->next();
            if ($token === null) {
                break;
            }
            $this->process($token[0], $token[1], $token[2]);
        }
        $this->finish();
        return $this->out;
    }

    private function emit(string $text, bool $space): void
    {
        if ($space && $this->out !== '') {
            $this->out .= ' ';
        }
        $this->out .= $text;
    }

    private static function render(string $type, string $text): string
    {
        return $type === 'lit' ? '?' : $text;
    }

    private function process(string $type, string $text, bool $space): void
    {
        switch ($this->state) {
            case self::AFTER_IN:
                if ($type === 'lp') {
                    $this->state = self::IN_LIST;
                    $this->buf = '';
                    $this->bufHasValue = false;
                    $this->lpSpace = $space;
                    return;
                }
                $this->state = self::NORMAL;
                break;

            case self::IN_LIST:
                if ($type === 'lit' || $type === 'ph' || $type === 'comma') {
                    if ($space && $this->buf !== '') {
                        $this->buf .= ' ';
                    }
                    $this->buf .= self::render($type, $text);
                    $this->bufHasValue = $this->bufHasValue || $type !== 'comma';
                    return;
                }
                if ($type === 'rp' && $this->bufHasValue) {
                    $this->emit('(?)', true);
                    $this->state = self::NORMAL;
                    return;
                }
                // Não é lista pura de literais: devolve o que foi segurado.
                $this->flushInList();
                $this->state = self::NORMAL;
                break;

            case self::AFTER_VALUES:
                if ($type === 'lp') {
                    $this->state = self::VALUES_TUPLE;
                    $this->depth = 1;
                    return;
                }
                $this->state = self::NORMAL;
                break;

            case self::VALUES_TUPLE:
                if ($type === 'lp') {
                    $this->depth++;
                } elseif ($type === 'rp' && --$this->depth === 0) {
                    $this->state = self::AFTER_TUPLE;
                }
                return;

            case self::AFTER_TUPLE:
                if ($type === 'comma') {
                    $this->state = self::AFTER_TUPLE_COMMA;
                    $this->commaSpace = $space;
                    return;
                }
                $this->emit('(?)', true);
                $this->state = self::NORMAL;
                break;

            case self::AFTER_TUPLE_COMMA:
                if ($type === 'lp') {
                    $this->state = self::VALUES_TUPLE;
                    $this->depth = 1;
                    return;
                }
                $this->emit('(?)', true);
                $this->emit(',', $this->commaSpace);
                $this->state = self::NORMAL;
                break;
        }

        // Estado NORMAL
        $this->emit(self::render($type, $text), $space);
        if ($type === 'word') {
            if ($this->prevUpper === 'IN') {
                $this->state = self::AFTER_IN;
            } elseif ($this->prevUpper === 'VALUES') {
                $this->state = self::AFTER_VALUES;
            }
        }
    }

    private function flushInList(): void
    {
        $this->emit('(', $this->lpSpace);
        $this->out .= $this->buf;
        $this->buf = '';
    }

    private function finish(): void
    {
        switch ($this->state) {
            case self::IN_LIST:
                $this->flushInList();
                break;
            case self::VALUES_TUPLE:
            case self::AFTER_TUPLE:
                $this->emit('(?)', true);
                break;
            case self::AFTER_TUPLE_COMMA:
                $this->emit('(?)', true);
                $this->emit(',', $this->commaSpace);
                break;
        }
        $this->state = self::NORMAL;
    }

    // ------------------------------------------------------------------
    // Tokenizador
    // ------------------------------------------------------------------

    /**
     * Próximo token como [tipo, texto, espaçoAntes] ou null no fim.
     * Tipos: word, lit, ph, qid, op, lp, rp, comma.
     *
     * @return array{0: string, 1: string, 2: bool}|null
     */
    private function next(): ?array
    {
        $space = false;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            $o = ord($c);
            if ($o <= 32 || $o === 127) {
                $space = true;
                $this->i++;
                continue;
            }
            $d = $this->at($this->i + 1);
            if (($c === '-' && $d === '-') || ($c === '#' && !self::isWordStart($d) && $d !== '#')) {
                $end = strpos($this->s, "\n", $this->i);
                $this->i = $end === false ? $this->n : $end + 1;
                $space = true;
                continue;
            }
            if ($c === '/' && $d === '*') {
                $end = strpos($this->s, '*/', $this->i + 2);
                $this->i = $end === false ? $this->n : $end + 2;
                $space = true;
                continue;
            }
            break;
        }
        if ($this->i >= $this->n) {
            return null;
        }

        [$type, $text] = $this->scan();
        $this->prevType = $type;
        $this->prevUpper = ($type === 'word' || $type === 'op') ? strtoupper($text) : '';
        return [$type, $text, $space];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scan(): array
    {
        $start = $this->i;
        $c = $this->s[$start];
        $d = $this->at($start + 1);

        if ($c === "'") {
            $this->i = $this->skipString($start);
            return ['lit', '?'];
        }

        if (self::isDigit($c) || ($c === '.' && self::isDigit($d))) {
            $this->i = $this->skipNumber($start);
            return ['lit', '?'];
        }

        if (($c === '-' || $c === '+') && $this->signAllowed()
            && (self::isDigit($d) || ($d === '.' && self::isDigit($this->at($start + 2))))
        ) {
            $this->i = $this->skipNumber($start + 1);
            return ['lit', '?'];
        }

        if (self::isWordStart($c) || $c === '#') {
            $p = $start + strspn($this->s, '#', $start);
            $p += strspn($this->s, self::wordChars(), $p);
            // Prefixos de string: E'..' (PostgreSQL), N'..', X'..', B'..'
            if ($p - $start === 1 && stripos('ENXB', $c) !== false && $this->at($p) === "'") {
                $this->i = $this->skipString($p);
                return ['lit', '?'];
            }
            $this->i = $p;
            return ['word', substr($this->s, $start, $p - $start)];
        }

        switch ($c) {
            case '"':
            case '`':
                return $this->scanQuotedIdentifier($start, $c);
            case '[':
                return $this->scanBracket($start);
            case '$':
                return $this->scanDollar($start);
            case '?':
                $this->i = $start + 1;
                return ['ph', '?'];
            case ':':
                if ($d === ':') {
                    $this->i = $start + 2;
                    return ['op', '::'];
                }
                if (self::isWordStart($d)) {
                    $p = $start + 1 + strspn($this->s, self::wordChars(), $start + 1);
                    $this->i = $p;
                    return ['ph', substr($this->s, $start, $p - $start)];
                }
                break;
            case '(':
                $this->i = $start + 1;
                return ['lp', '('];
            case ')':
                $this->i = $start + 1;
                return ['rp', ')'];
            case ',':
                $this->i = $start + 1;
                return ['comma', ','];
        }

        $this->i = $start + 1;
        return ['op', $c];
    }

    /**
     * `-`/`+` seguido de dígito é sinal (e não operador binário) quando vem
     * depois de operador, `(`, `,`, palavra-chave ou no início.
     */
    private function signAllowed(): bool
    {
        return match ($this->prevType) {
            null, 'op', 'lp', 'comma' => true,
            'word' => isset(self::SIGN_KEYWORDS[$this->prevUpper]),
            default => false,
        };
    }

    /**
     * Pula uma string '...' a partir da aspa de abertura; aceita '' e \'.
     * Não terminada: consome o resto da entrada.
     */
    private function skipString(int $open): int
    {
        $j = $open + 1;
        while (true) {
            $k = $j + strcspn($this->s, "'\\", $j);
            if ($k >= $this->n) {
                return $this->n;
            }
            if ($this->s[$k] === '\\') {
                $j = $k + 2;
                continue;
            }
            if ($this->at($k + 1) === "'") {
                $j = $k + 2;
                continue;
            }
            return $k + 1;
        }
    }

    /**
     * Pula um número (inteiro, decimal, expoente, 0x hex) e qualquer
     * caractere de palavra colado a ele (ex.: `123abc`).
     */
    private function skipNumber(int $p): int
    {
        $x = $this->at($p + 1);
        if ($this->s[$p] === '0' && ($x === 'x' || $x === 'X')) {
            $p += 2;
        } else {
            $p += strspn($this->s, '0123456789', $p);
            if ($this->at($p) === '.') {
                $p++;
                $p += strspn($this->s, '0123456789', $p);
            }
            $e = $this->at($p);
            if ($e === 'e' || $e === 'E') {
                $q = $p + 1;
                $sign = $this->at($q);
                if ($sign === '+' || $sign === '-') {
                    $q++;
                }
                if (self::isDigit($this->at($q))) {
                    $p = $q + strspn($this->s, '0123456789', $q);
                }
            }
        }
        return $p + strspn($this->s, self::wordChars(), $p);
    }

    /**
     * "..." ou `...` copiados sem alteração (aspas dobradas escapam).
     * Não terminado: vira `?` e consome o resto (pode esconder literais).
     *
     * @return array{0: string, 1: string}
     */
    private function scanQuotedIdentifier(int $open, string $q): array
    {
        $j = $open + 1;
        while (true) {
            $k = strpos($this->s, $q, $j);
            if ($k === false) {
                $this->i = $this->n;
                return ['lit', '?'];
            }
            if ($this->at($k + 1) === $q) {
                $j = $k + 2;
                continue;
            }
            $this->i = $k + 1;
            if ($q === '"' && $this->doubleQuoteIsValue()) {
                return ['lit', '?'];
            }
            return ['qid', substr($this->s, $open, $k + 1 - $open)];
        }
    }

    /**
     * No MySQL sem ANSI_QUOTES, "..." é string literal. Heurística: trata
     * "..." como valor (vira `?`) quando vem depois de comparação (`=`, `<>`,
     * `!=`, `<`, `>`, `<=`, `>=`), `LIKE`, `ILIKE`, `THEN`, `ELSE`, ou de
     * `(`/`,` dentro de uma lista `IN (`, e não é seguido de `.` (assim
     * `"t"."id" = "u"."tid"` do PostgreSQL fica intacto). Efeito colateral
     * aceito: comparação entre colunas sem qualificação, como `"a" = "b"`,
     * vira `"a" = ?` — só fica menos legível, nada vaza.
     *
     * Chamado com $this->i já depois da aspa de fechamento.
     */
    private function doubleQuoteIsValue(): bool
    {
        if ($this->at($this->i) === '.') {
            return false;
        }
        return match ($this->prevType) {
            'op' => $this->prevUpper === '=' || $this->prevUpper === '<' || $this->prevUpper === '>',
            'word' => in_array($this->prevUpper, ['LIKE', 'ILIKE', 'THEN', 'ELSE'], true),
            'lp', 'comma' => $this->state === self::IN_LIST,
            default => false,
        };
    }

    /**
     * [ident] do SQL Server copiado sem alteração. Se o conteúdo não parece
     * identificador (ex.: subscript de array `arr[1]`), `[` vira operador e o
     * conteúdo segue sendo tokenizado. Também cai no caminho normal quando o
     * conteúdo começa com espaço ou contém operador/pontuação de expressão
     * (`arr[ 5]`, `arr[i + 1]`, `x[a=99]`), para que números internos sejam
     * redigidos. A busca para no próximo `[`, aspa, vírgula ou operador,
     * então cada byte é examinado no máximo duas vezes.
     *
     * @return array{0: string, 1: string}
     */
    private function scanBracket(int $open): array
    {
        $k = $open + 1 + strcspn($this->s, "]['\",=<>+-*/%!|&^~:;()", $open + 1);
        $first = $this->at($open + 1);
        if (
            $k < $this->n && $this->s[$k] === ']' && $k > $open + 1
            && !self::isDigit($first) && $first !== '.' && ord($first) > 32 && ord($first) !== 127
        ) {
            $this->i = $k + 1;
            return ['qid', substr($this->s, $open, $k + 1 - $open)];
        }
        $this->i = $open + 1;
        return ['op', '['];
    }

    /**
     * `$1` (placeholder posicional do PostgreSQL) é mantido; `$$...$$` e
     * `$tag$...$tag$` viram `?` (não terminado consome o resto).
     *
     * @return array{0: string, 1: string}
     */
    private function scanDollar(int $open): array
    {
        $d = $this->at($open + 1);
        if (self::isDigit($d)) {
            $p = $open + 1 + strspn($this->s, '0123456789', $open + 1);
            $this->i = $p;
            return ['ph', substr($this->s, $open, $p - $open)];
        }
        $len = 0;
        if ($d !== '$' && self::isWordStart($d) && ord($d) < 0x80 && $d !== '@') {
            $len = strspn(
                $this->s,
                'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_',
                $open + 1
            );
        }
        if ($this->at($open + 1 + $len) === '$') {
            $tag = substr($this->s, $open, $len + 2);
            $end = strpos($this->s, $tag, $open + $len + 2);
            $this->i = $end === false ? $this->n : $end + strlen($tag);
            return ['lit', '?'];
        }
        $this->i = $open + 1;
        return ['op', '$'];
    }
}
