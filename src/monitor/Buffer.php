<?php

namespace Psf\Monitor;

/**
 * Buffer em memória dos eventos de uma requisição (queries, chamadas externas e exceções).
 *
 * Tem tetos para limitar o uso de memória; o excedente é contado em
 * droppedEvents(). Nunca lança exceção e não inspeciona o conteúdo dos eventos.
 */
final class Buffer
{
    /** Teto de exceções guardadas por request (o fatal não conta: ver addFatal()). */
    public const EXCEPTION_CAP = 20;

    /** Tamanho máximo (em caracteres) do SQL cru guardado por query. */
    public const RAW_SQL_MAX = 8000;

    private int $maxEvents;
    /** @var array<int, array{sql: string, duration_ms: float, connection: string, ts: int}> */
    private array $queries = [];
    /** @var array<int, array<mixed>> */
    private array $exceptions = [];
    /** @var array<int, array{kind: string, target: string, duration_ms: float, status: ?int, ts: int}> */
    private array $externals = [];
    private float $maxQueryMs = 0.0;
    private float $maxExternalMs = 0.0;
    private int $dropped = 0;

    /**
     * @param int $maxEvents teto por tipo de evento (`max_events_per_request`; mínimo 1):
     *                       queries e chamadas externas têm baterias separadas
     */
    public function __construct(int $maxEvents)
    {
        $this->maxEvents = max(1, $maxEvents);
    }

    /**
     * Guarda uma query executada (SQL cru cortado em RAW_SQL_MAX). Acima do
     * teto a query é descartada e contada em droppedEvents(), mas sua duração
     * ainda atualiza maxQueryMs().
     *
     * @param string $connection chave de config['db']
     * @param int    $ts         timestamp Unix do fim da execução
     */
    public function addQuery(string $sql, float $durationMs, string $connection, int $ts): void
    {
        // O maior tempo é sempre registrado, mesmo quando a query é descartada.
        if ($durationMs > $this->maxQueryMs) {
            $this->maxQueryMs = $durationMs;
        }

        if (count($this->queries) >= $this->maxEvents) {
            $this->dropped++;
            return;
        }

        $this->queries[] = [
            'sql' => self::cut($sql),
            'duration_ms' => $durationMs,
            'connection' => $connection,
            'ts' => $ts,
        ];
    }

    /** @return array<int, array{sql: string, duration_ms: float, connection: string, ts: int}> */
    public function queries(): array
    {
        return $this->queries;
    }

    /** Maior duração de query vista no request, inclusive das descartadas. */
    public function maxQueryMs(): float
    {
        return $this->maxQueryMs;
    }

    /**
     * Guarda uma chamada externa (HTTP, e-mail, storage). Acima do teto a
     * chamada é descartada e contada em droppedEvents(), mas sua duração
     * ainda atualiza maxExternalMs().
     *
     * @param string   $kind   `http`, `mail` ou `storage` (já validado pelo Monitor)
     * @param string   $target rótulo seguro (já cortado pelo Monitor)
     * @param int|null $status código recebido; null em falha
     * @param int      $ts     timestamp Unix do fim da chamada
     */
    public function addExternal(string $kind, string $target, float $durationMs, ?int $status, int $ts): void
    {
        // O maior tempo é sempre registrado, mesmo quando a chamada é descartada.
        if ($durationMs > $this->maxExternalMs) {
            $this->maxExternalMs = $durationMs;
        }

        if (count($this->externals) >= $this->maxEvents) {
            $this->dropped++;
            return;
        }

        $this->externals[] = [
            'kind' => $kind,
            'target' => $target,
            'duration_ms' => $durationMs,
            'status' => $status,
            'ts' => $ts,
        ];
    }

    /** @return array<int, array{kind: string, target: string, duration_ms: float, status: ?int, ts: int}> */
    public function externals(): array
    {
        return $this->externals;
    }

    /** Maior duração de chamada externa vista no request, inclusive das descartadas. */
    public function maxExternalMs(): float
    {
        return $this->maxExternalMs;
    }

    /**
     * Guarda um evento de exceção, respeitando EXCEPTION_CAP.
     *
     * @param array<mixed> $event Guardado como veio.
     * @return bool true se guardado; false se descartado (contado em droppedEvents())
     */
    public function addException(array $event): bool
    {
        if (count($this->exceptions) >= self::EXCEPTION_CAP) {
            $this->dropped++;
            return false;
        }

        $this->exceptions[] = $event;
        return true;
    }

    /**
     * Guarda o erro fatal do request ignorando o teto (no máximo teto + 1
     * entradas): é o evento que derrubou o request e nunca é descartado.
     *
     * @param array<mixed> $event Guardado como veio.
     */
    public function addFatal(array $event): void
    {
        $this->exceptions[] = $event;
    }

    /** @return array<int, array<mixed>> */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    /** Total de eventos descartados (queries, chamadas externas e exceções acima dos tetos). */
    public function droppedEvents(): int
    {
        return $this->dropped;
    }

    /** Indica se há alguma exceção (ou fatal) guardada. */
    public function hasExceptions(): bool
    {
        return $this->exceptions !== [];
    }

    /** Corta o SQL em RAW_SQL_MAX caracteres sem partir caractere UTF-8. */
    private static function cut(string $sql): string
    {
        // Evita custo em SQLs claramente menores que o teto (1 byte >= 1 caractere).
        if (strlen($sql) <= self::RAW_SQL_MAX) {
            return mb_check_encoding($sql, 'UTF-8') ? $sql : self::sanitize($sql);
        }

        // Limita o trabalho a um prefixo: RAW_SQL_MAX caracteres cabem em
        // RAW_SQL_MAX * 4 bytes (máximo de 4 bytes por caractere UTF-8). Um
        // caractere partido no fim do prefixo vira '?' além do corte.
        $sql = substr($sql, 0, self::RAW_SQL_MAX * 4);

        if (!mb_check_encoding($sql, 'UTF-8')) {
            $sql = self::sanitize($sql);
        }

        return mb_substr($sql, 0, self::RAW_SQL_MAX, 'UTF-8');
    }

    /** Substitui sequências inválidas por '?' para garantir UTF-8 válido. */
    private static function sanitize(string $sql): string
    {
        $prev = mb_substitute_character();
        mb_substitute_character(0x3F);
        $clean = mb_convert_encoding($sql, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);
        return $clean;
    }
}
