<?php

namespace Psf\Monitor;

use Throwable;

/**
 * Converte uma exceção (ou um fatal de `error_get_last()`) nos campos de uma
 * linha de `psf_monitor_exceptions`.
 *
 * Privacidade: o trace é montado a partir de `getTrace()` SEM os argumentos
 * dos frames (nunca `getTraceAsString()`, que imprime valores de argumentos).
 * Inclui a cadeia de `getPrevious()`, pois o Router embrulha falhas do
 * controller em outra exceção.
 *
 * Mensagens de `\PDOException` (a principal e as da cadeia) e de fatais passam
 * por `scrubMessage()`, pois os drivers costumam embutir valores das linhas
 * (ex.: `Duplicate entry 'jane@x.com'`). Mensagens da aplicação são gravadas
 * como vieram, a menos que `$storeMessage` seja false (aí nenhuma mensagem é
 * gravada, nem nas linhas "Caused by").
 *
 * Nunca lança: qualquer falha interna devolve campos mínimos válidos.
 */
final class ExceptionDescriber
{
    /** Limites das colunas de `psf_monitor_exceptions` (em caracteres; o trace em bytes). */
    public const MAX_CLASS = 191;
    public const MAX_MESSAGE = 500;
    public const MAX_FILE = 255;
    public const MAX_TRACE_BYTES = 16384;

    /** Máximo de elos da cadeia `getPrevious()` incluídos no trace. */
    public const MAX_PREVIOUS = 20;

    /** Quantos frames do topo entram no trace_hash (só "Classe::função"). */
    public const HASH_FRAMES = 3;

    private const SUFFIX = '... [truncado]';

    /** Teto em bytes da entrada de scrubMessage() (limita o custo de regex). */
    private const SCRUB_INPUT_BYTES = self::MAX_MESSAGE * 8;

    /**
     * Descreve uma exceção: classe, mensagem (limpa se for PDO), local, trace
     * sem argumentos (com a cadeia `getPrevious()`) e `trace_hash`.
     *
     * @param bool $storeMessage false grava mensagem vazia (inclusive na cadeia)
     * @return array{exception_class: string, message: string, file: string, line_no: int, trace: string, trace_hash: string}
     */
    public static function describe(Throwable $e, bool $storeMessage = true): array
    {
        try {
            $class = get_class($e);
            $file = (string) $e->getFile();
            $line = (int) $e->getLine();

            // Agrupamento: classe + ponto de lançamento + os 3 primeiros frames
            // como "Classe::função" (sem arquivo/linha dos chamadores), para que
            // o mesmo bug alcançado por caminhos diferentes tenha o mesmo hash.
            $hashParts = [];
            foreach (array_slice($e->getTrace(), 0, self::HASH_FRAMES) as $frame) {
                $hashParts[] = self::frameName($frame);
            }

            return [
                'exception_class' => self::cut($class, self::MAX_CLASS),
                'message' => self::messageOf($e, $storeMessage),
                'file' => self::cut($file, self::MAX_FILE),
                'line_no' => $line,
                'trace' => self::buildTrace($e, $storeMessage),
                'trace_hash' => sha1($class . '|' . $file . ':' . $line . '|' . implode('|', $hashParts)),
            ];
        } catch (Throwable) {
            return self::minimal('Exception', '', 0);
        }
    }

    /**
     * Descreve um erro fatal como `FatalError`: mensagem até `Stack trace:`,
     * limpa por scrubMessage(), trace vazio e hash por arquivo:linha.
     *
     * @param array<string, mixed> $error formato de error_get_last()
     * @param bool $storeMessage false grava mensagem vazia
     * @return array{exception_class: string, message: string, file: string, line_no: int, trace: string, trace_hash: string}
     */
    public static function fatal(array $error, bool $storeMessage = true): array
    {
        try {
            $message = (string) ($error['message'] ?? '');
            $pos = strpos($message, 'Stack trace:');
            if ($pos !== false) {
                $message = substr($message, 0, $pos);
            }
            $file = (string) ($error['file'] ?? '');
            $line = (int) ($error['line'] ?? 0);

            return [
                'exception_class' => 'FatalError',
                'message' => $storeMessage ? self::cut(self::scrubMessage(trim($message)), self::MAX_MESSAGE) : '',
                'file' => self::cut($file, self::MAX_FILE),
                'line_no' => $line,
                'trace' => '',
                'trace_hash' => sha1('FatalError|' . $file . ':' . $line),
            ];
        } catch (Throwable) {
            return self::minimal('FatalError', '', 0);
        }
    }

    /**
     * Remove valores de dados de uma mensagem de erro de banco/fatal,
     * mantendo as palavras estruturais. Troca por `?`:
     * - listas entre parênteses após `)=(` (`Key (col)=(...)`, PostgreSQL),
     *   `value is (` (SQL Server) e `row contains (` (PostgreSQL): do primeiro
     *   desses marcadores até o ÚLTIMO `)` da mensagem, atravessando quebras
     *   de linha; sem `)` depois dele, até o fim da mensagem;
     * - formatos do MySQL ancorados no texto seguinte (`Duplicate entry '...'
     *   for key`, `Incorrect ... value: '...' for column`, `Truncated
     *   incorrect ... value: '...'`): da aspa após `entry `/`value: ` até a
     *   última ocorrência de `' for key `/`' for column ` (sem âncora, até a
     *   última aspa), para que apóstrofos no valor não deixem fragmentos;
     * - conversão do SQL Server (`converting the varchar value '...' to data
     *   type`): da aspa após `value ` até a última ocorrência de
     *   `' to data type ` (sem âncora, regra genérica);
     * - `: "..."` (PostgreSQL, que não escapa aspas duplas internas): até a
     *   última `"` da mesma linha;
     * - o conteúdo de `'...'` e `"..."` (aspa dobrada `''` e `\'` não fecham;
     *   aspa sem fechamento consome o resto);
     * - números após `value`, `=` ou `:`.
     *
     * Na dúvida redige demais (ex.: `doesn't` consome o resto da mensagem).
     * Custo linear: a entrada é limitada a SCRUB_INPUT_BYTES antes de tudo; a
     * varredura de aspas só avança, e as buscas reversas (`strrpos`) são
     * limitadas: a última âncora de cada tipo é calculada uma vez por
     * mensagem, e a busca da última `"` de uma linha só percorre essa linha.
     * Nunca lança: em caso de falha devolve string vazia.
     */
    public static function scrubMessage(string $message): string
    {
        try {
            $s = mb_scrub(substr($message, 0, self::SCRUB_INPUT_BYTES), 'UTF-8');

            // 1. Listas de valores entre parênteses: do primeiro marcador até o
            //    último ")" da mensagem (inclusive quebras de linha) ou até o fim
            if (preg_match('/\)=\(|value is \(|row contains \(/i', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                $start = $m[0][1] + strlen($m[0][0]);
                $close = strrpos($s, ')');
                $s = substr($s, 0, $start) . '?'
                    . ($close !== false && $close >= $start ? substr($s, $close) : '');
            }

            // 2. Conteúdo entre aspas simples ou duplas
            $out = '';
            $n = strlen($s);
            $i = 0;
            $lastAnchor = [];
            while ($i < $n) {
                $run = strcspn($s, '\'"', $i);
                $out .= substr($s, $i, $run);
                $i += $run;
                if ($i >= $n) {
                    break;
                }
                $quote = $s[$i];
                $out .= $quote . '?';
                $end = $quote === "'"
                    ? self::anchoredEnd($s, $i, $lastAnchor)
                    : self::colonQuoteEnd($s, $i);
                if ($end === null) {
                    $end = self::closingQuote($s, $i);
                }
                if ($end === false) {
                    break; // aspa sem fechamento: descarta o resto
                }
                $out .= $quote;
                $i = $end + 1;
            }

            // 3. Números após "value", "=" ou ":"
            return (string) preg_replace('/(\bvalue\s*|[=:]\s*)[-+]?\d[\w.]*/i', '$1?', $out);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Fim do valor de um formato ancorado (`entry '...' for key`,
     * `value: '...' for column` do MySQL; `value '...' to data type` do SQL
     * Server), dado o índice da aspa de abertura: a última âncora da mensagem
     * ou, sem ela, a última aspa (MySQL) ou null (SQL Server, regra
     * genérica). Null se a aspa não abre um desses formatos; false se não há
     * aspa de fechamento. `$last` guarda o `strrpos` de cada âncora, para que
     * cada busca reversa rode uma vez por mensagem.
     *
     * @param array<string, int|false> $last
     */
    private static function anchoredEnd(string $s, int $open, array &$last): int|false|null
    {
        $before = strtolower(substr($s, max(0, $open - 7), min(7, $open)));
        $fallbackToLastQuote = true;
        if (str_ends_with($before, 'entry ')) {
            $anchor = "' for key ";
        } elseif (str_ends_with($before, 'value: ')) {
            $anchor = "' for column ";
        } elseif (str_ends_with($before, 'value ')) {
            $anchor = "' to data type ";
            $fallbackToLastQuote = false;
        } else {
            return null;
        }
        $end = $last[$anchor] ??= strrpos($s, $anchor);
        if ($end === false || $end <= $open) {
            if (!$fallbackToLastQuote) {
                return null;
            }
            $end = $last["'"] ??= strrpos($s, "'");
        }
        return $end !== false && $end > $open ? $end : false;
    }

    /**
     * Para `: "...` (PostgreSQL não escapa `"` internas): índice da última `"`
     * da mesma linha, se estiver depois da abertura; senão null (regra
     * genérica). Só percorre a linha da abertura.
     */
    private static function colonQuoteEnd(string $s, int $open): ?int
    {
        if ($open < 2 || substr($s, $open - 2, 2) !== ': ') {
            return null;
        }
        $n = strlen($s);
        $eol = strpos($s, "\n", $open);
        $eol = $eol === false ? $n : $eol;
        $end = strrpos($s, '"', $eol - $n - 1);
        return $end !== false && $end > $open ? $end : null;
    }

    /**
     * Índice da aspa que fecha a aberta em `$open`, pulando `\x` e a aspa
     * dobrada (`''`/`""`). False se não fecha. Avança sempre: custo linear.
     */
    private static function closingQuote(string $s, int $open): int|false
    {
        $quote = $s[$open];
        $n = strlen($s);
        $j = $open + 1;
        while ($j < $n) {
            $j += strcspn($s, $quote . '\\', $j);
            if ($j >= $n) {
                return false;
            }
            if ($s[$j] === '\\') {
                $j += 2;
                continue;
            }
            if ($j + 1 < $n && $s[$j + 1] === $quote) {
                $j += 2;
                continue;
            }
            return $j;
        }
        return false;
    }

    /** Mensagem da exceção para gravação: vazia, limpa (PDO) ou como veio. */
    private static function messageOf(Throwable $e, bool $storeMessage): string
    {
        if (!$storeMessage) {
            return '';
        }
        $message = $e->getMessage();
        if ($e instanceof \PDOException) {
            $message = self::scrubMessage($message);
        }
        return self::cut($message, self::MAX_MESSAGE);
    }

    /**
     * "Classe::função" de um frame (chaves ausentes viram vazio). Closures do
     * PHP 8.4 (`{closure:arquivo:linha}`) viram `{closure}` para não
     * reintroduzir a linha no hash.
     *
     * @param array<string, mixed> $frame
     */
    private static function frameName(array $frame): string
    {
        $fn = is_string($frame['function'] ?? null) ? $frame['function'] : '';
        if (str_starts_with($fn, '{closure')) {
            $fn = '{closure}';
        }
        $cls = is_string($frame['class'] ?? null) ? $frame['class'] : '';
        return $cls !== '' ? $cls . '::' . $fn : $fn;
    }

    /** Corta em caracteres UTF-8 sem quebrar multibyte; tolera entrada inválida. */
    private static function cut(string $s, int $max): string
    {
        // Limita antes de scrub para não processar megabytes
        $s = substr($s, 0, $max * 4 + 4);
        $s = mb_scrub($s, 'UTF-8');
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    private static function buildTrace(Throwable $e, bool $storeMessage): string
    {
        $out = '';
        $over = false;
        $seen = [];
        $current = $e;
        $links = 0;

        while ($current !== null) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;

            if ($current !== $e) {
                $out .= "\n\nCaused by: " . get_class($current)
                    . ($storeMessage ? ': ' . self::messageOf($current, true) : '')
                    . ' (' . self::cut((string) $current->getFile(), self::MAX_FILE) . ':' . $current->getLine() . ")\n";
            }

            $i = 0;
            foreach ($current->getTrace() as $frame) {
                $fn = (string) ($frame['function'] ?? '');
                $cls = isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '';
                $where = isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')' : '[internal function]';
                $out .= ($i === 0 ? '' : "\n") . '#' . $i . ' ' . $where . ': ' . $cls . $fn . '()';
                $i++;
                if (strlen($out) > self::MAX_TRACE_BYTES) {
                    $over = true;
                    break 2;
                }
            }

            $current = $current->getPrevious();
            if ($current !== null && ++$links > self::MAX_PREVIOUS) {
                $over = true;
                break;
            }
        }

        if (!$over && strlen($out) <= self::MAX_TRACE_BYTES) {
            return $out;
        }

        return mb_strcut($out, 0, self::MAX_TRACE_BYTES - strlen(self::SUFFIX), 'UTF-8') . self::SUFFIX;
    }

    /** @return array{exception_class: string, message: string, file: string, line_no: int, trace: string, trace_hash: string} */
    private static function minimal(string $class, string $file, int $line): array
    {
        return [
            'exception_class' => $class,
            'message' => '',
            'file' => $file,
            'line_no' => $line,
            'trace' => '',
            'trace_hash' => sha1($class . '|' . $file . ':' . $line),
        ];
    }
}
