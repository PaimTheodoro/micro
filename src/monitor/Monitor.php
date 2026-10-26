<?php

namespace Psf\Monitor;

use Psf\Monitor\Sinks\DatabaseSink;
use Psf\Monitor\Sinks\SinkInterface;

/**
 * Fachada do monitor (observabilidade).
 *
 * `boot()` é chamado no construtor do Router; durante o request os ganchos
 * apenas guardam contexto em memória; no shutdown `flush()` aplica a
 * amostragem e grava via sink, protegido pelo circuit breaker.
 * Desligado, tudo vira no-op (uma checagem de booleano).
 */
final class Monitor
{
    /** Tamanho máximo do rótulo da rota (coluna varchar 191). */
    private const ROUTE_MAX = 191;

    /** Tamanho máximo do user_id. */
    private const USER_MAX = 64;

    /** Tamanhos das colunas (em caracteres) de method, env e app_release. */
    private const METHOD_MAX  = 10;
    private const ENV_MAX     = 20;
    private const RELEASE_MAX = 64;

    /** Tipos aceitos de chamada externa (outro tipo é ignorado). */
    private const EXTERNAL_KINDS = ['http', 'mail', 'storage'];

    /** Tamanho máximo do `target` da chamada externa (coluna varchar 191). */
    private const TARGET_MAX = 191;

    /** Maior duração gravável (ms) nas colunas decimal(10,2). */
    private const DURATION_MAX_MS = 99999999.99;

    /** Rótulo gravado quando o `target` vem vazio ou com UTF-8 inválido. */
    private const INVALID_TARGET = '(invalido)';

    /** Rótulo para requests que não casaram nenhuma rota. */
    private const UNMATCHED = '(unmatched)';

    private static bool $booted = false;
    private static bool $flushed = false;
    private static bool $ignored = false;

    /**
     * O shutdown é registrado uma única vez por processo; `reset()` não limpa
     * esta flag (o flush vira no-op enquanto não houver um novo boot).
     */
    private static bool $shutdownRegistered = false;

    private static ?string $requestId = null;
    private static ?float $start = null;
    private static ?string $bootMethod = null;

    /** @var array{route?: string, method?: string, version?: int, user_id?: ?string} */
    private static array $context = [];

    private static ?SinkInterface $sink = null;
    private static ?CircuitBreaker $breaker = null;
    private static ?AutoMigrator $migrator = null;

    /** Encerra a resposta ao cliente antes da gravação (padrão: fastcgi_finish_request). */
    private static ?\Closure $finisher = null;

    /** Gera o id do request quando o de entrada não serve (padrão: 16 bytes aleatórios em hex). */
    private static ?\Closure $idGenerator = null;

    /** Lê o último erro do PHP para detectar fatal (padrão: error_get_last). */
    private static ?\Closure $fatalProvider = null;

    /** Eventos do request (criado sob demanda com `maxEventsPerRequest`). */
    private static ?Buffer $buffer = null;

    /** @var array<int, true> Ids (spl_object_id) das exceções já registradas. */
    private static array $seenExceptions = [];

    /** Tipos de erro que encerram o script (contam como fatal). */
    private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /**
     * Inicializa o monitor para o request atual (idempotente).
     * Roda no construtor do Router: nunca lança. Em caso de erro o monitor
     * fica "sem boot" e os demais ganchos e o flush viram no-op.
     */
    public static function boot(?string $method = null): void
    {
        try {
            if (self::$booted || !self::enabled()) {
                return;
            }

            self::$booted     = true;
            self::$flushed    = false;
            self::$bootMethod = self::stringOr($method ?? ($_SERVER['REQUEST_METHOD'] ?? null), 'GET');
            self::$start      = is_numeric($_SERVER['REQUEST_TIME_FLOAT'] ?? null)
                ? (float) $_SERVER['REQUEST_TIME_FLOAT']
                : microtime(true);
            self::$requestId  = self::resolveRequestId(
                is_string($_SERVER['HTTP_X_REQUEST_ID'] ?? null) ? $_SERVER['HTTP_X_REQUEST_ID'] : null
            );

            // Preflight CORS (OPTIONS respondido pelo applyCors) não é registrado.
            self::$ignored = strtoupper(self::$bootMethod) === 'OPTIONS'
                && isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);

            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function([self::class, 'flush']);
            }

            if (!headers_sent()) {
                foreach (self::responseHeaders() as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação: volta ao estado "sem boot".
            self::$booted     = false;
            self::$ignored    = false;
            self::$requestId  = null;
            self::$start      = null;
            self::$bootMethod = null;
        }
    }

    /** Indica se o monitor está ligado. */
    public static function enabled(): bool
    {
        return Config::enabled();
    }

    /** Id do request atual (null se desligado ou sem boot). */
    public static function requestId(): ?string
    {
        return self::enabled() ? self::$requestId : null;
    }

    /** Usa o id de entrada se for seguro; senão gera 16 bytes aleatórios em hex. */
    public static function resolveRequestId(?string $incoming): string
    {
        if ($incoming !== null && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $incoming) === 1) {
            return $incoming;
        }
        return self::$idGenerator !== null
            ? (string) (self::$idGenerator)()
            : bin2hex(random_bytes(16));
    }

    /** Headers de resposta do monitor (`X-Request-Id`), se ligado e exposto. */
    public static function responseHeaders(): array
    {
        if (!self::enabled() || !Config::get('exposeRequestId') || self::$requestId === null) {
            return [];
        }
        return ['X-Request-Id' => self::$requestId];
    }

    /** Registra o padrão da rota casada (nunca a URL real), método e versão. */
    public static function route(string $pattern, string $method, int $version): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            self::$context['route']   = self::cut($pattern, self::ROUTE_MAX);
            self::$context['method']  = $method;
            self::$context['version'] = $version;
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /** Registra o usuário autenticado (objeto ou array; só valores escalares). */
    public static function user(mixed $auth): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            self::$context['user_id'] = self::extractUserId($auth);
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /** Contexto atual: request_id, route, method, version, user_id. */
    public static function snapshot(): array
    {
        if (!self::enabled()) {
            return [];
        }
        return [
            'request_id' => self::$requestId,
            'route'      => self::$context['route'] ?? null,
            'method'     => self::$context['method'] ?? self::$bootMethod,
            'version'    => self::$context['version'] ?? null,
            'user_id'    => self::$context['user_id'] ?? null,
        ];
    }

    /** Marca o início de uma query (`hrtime`); null se desligado. */
    public static function queryStart(): ?int
    {
        try {
            if (!self::enabled()) {
                return null;
            }
            $now = hrtime(true);
            return is_int($now) ? $now : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Registra uma query executada. Barato: guarda só o SQL cru, a duração,
     * a conexão e o timestamp; normalização e hash ficam para o flush.
     *
     * @param int|null $start      valor devolvido por queryStart() (null = ignora)
     * @param string   $connection chave de config['db'] usada pela query
     */
    public static function queryEnd(?int $start, string $sql, string $connection = 'default'): void
    {
        try {
            if ($start === null || !self::enabled()) {
                return;
            }
            $durationMs = self::clampDuration((hrtime(true) - $start) / 1e6);
            self::buffer()->addQuery($sql, $durationMs, $connection, time());
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /**
     * Registra uma exceção. Só guarda o objeto (a descrição é feita no flush,
     * depois de liberar a resposta); o mesmo objeto repetido é ignorado.
     */
    public static function exception(\Throwable $e): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $id = spl_object_id($e);
            if (isset(self::$seenExceptions[$id])) {
                return;
            }
            // Só marca como vista se foi guardada: o buffer mantém a referência,
            // então o id não é reaproveitado; descartadas contam em dropped_events.
            if (self::buffer()->addException(['throwable' => $e])) {
                self::$seenExceptions[$id] = true;
            }
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /**
     * Registra uma chamada externa já medida. Barato: só guarda no buffer.
     * Tipo fora de `http|mail|storage` ou duração inválida (negativa, NaN,
     * infinita) é ignorado; duração acima de 99999999.99 ms é limitada a esse
     * valor (coluna decimal(10,2)); nunca lança.
     *
     * `null` = falha/erro. ATENÇÃO: omitir `$status` registra `null`, que conta
     * como ERRO nos agregados; para uma chamada bem-sucedida informe o código
     * (ex.: 200) ou use `trackExternal()`, que registra 200 quando a chamada
     * retorna sem erro.
     *
     * @param string   $kind   `http`, `mail` ou `storage`
     * @param string   $target rótulo seguro (ver ExternalTarget), gravado como veio (até 191 caracteres)
     * @param int|null $status código recebido; null em falha (conta como erro no rollup)
     */
    public static function external(string $kind, string $target, float $durationMs, ?int $status = null): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            self::recordExternal($kind, $target, $durationMs, $status);
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /** Marca o início de uma chamada externa (`hrtime`); null se desligado. */
    public static function externalStart(): ?int
    {
        try {
            if (!self::enabled()) {
                return null;
            }
            $now = hrtime(true);
            return is_int($now) ? $now : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Registra o fim de uma chamada externa iniciada com externalStart().
     *
     * @param int|null $start valor devolvido por externalStart() (null = ignora)
     */
    public static function externalEnd(?int $start, string $kind, string $target, ?int $status = null): void
    {
        try {
            if ($start === null || !self::enabled()) {
                return;
            }
            self::recordExternal($kind, $target, max(0.0, (hrtime(true) - $start) / 1e6), $status);
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /**
     * Executa `$call` medindo-a como chamada externa. Devolve exatamente o
     * retorno de `$call` e relança exatamente a exceção que ela lançar (a
     * chamada é registrada uma vez, com `status = null`). Desligado, só
     * executa `$call`. A contabilidade do monitor nunca lança.
     *
     * Status gravado: `null` = falha/erro (regra do rollup: `status IS NULL
     * OR status >= 500`). Sem `$statusFrom`, chamada que retorna normalmente
     * conta como `200`; com `$statusFrom`, vale o que ele devolver (inteiro,
     * string só de dígitos ou float inteiro); `null`, exceção ou outro valor
     * viram `null` (erro).
     *
     * @param \Closure      $call       a chamada real
     * @param \Closure|null $statusFrom `fn (mixed $result): ?int` que extrai o status do retorno
     */
    public static function trackExternal(string $kind, string $target, \Closure $call, ?\Closure $statusFrom = null): mixed
    {
        $start = self::externalStart();
        if ($start === null) {
            return $call();
        }

        try {
            $result = $call();
        } catch (\Throwable $e) {
            self::externalEnd($start, $kind, $target, null);
            throw $e;
        }

        $status = 200;
        if ($statusFrom !== null) {
            try {
                $status = self::toStatus($statusFrom($result));
            } catch (\Throwable) {
                $status = null;
            }
        }

        self::externalEnd($start, $kind, $target, $status);
        return $result;
    }

    /**
     * Eventos crus do request (diagnóstico/testes): queries com o SQL ainda
     * não normalizado, exceções como guardadas, chamadas externas e o total descartado.
     */
    public static function events(): array
    {
        if (!self::enabled()) {
            return [];
        }
        return [
            'queries'    => self::$buffer?->queries() ?? [],
            'exceptions' => self::$buffer?->exceptions() ?? [],
            'external'   => self::$buffer?->externals() ?? [],
            'dropped'    => self::$buffer?->droppedEvents() ?? 0,
        ];
    }

    /**
     * Fecha o request: aplica a amostragem e grava via sink.
     * Roda no shutdown; nunca lança.
     *
     * Ordem: decide (status/fatal → peso → breaker) → libera a resposta →
     * só então descreve exceções, normaliza SQL e monta o lote único.
     *
     * @param int|null   $status status HTTP (padrão: http_response_code(), ou 200)
     * @param float|null $rand   sorteio em [0, 1) para a amostragem (seam de teste)
     */
    public static function flush(?int $status = null, ?float $rand = null): void
    {
        try {
            if (!self::$booted || self::$flushed || !self::enabled()) {
                return;
            }
            self::$flushed = true;

            if (self::$ignored) {
                return;
            }

            $status ??= (int) (http_response_code() ?: 200);
            $start      = self::$start ?? microtime(true);
            $durationMs = max(0.0, (microtime(true) - $start) * 1000);

            $fatal = self::readFatal();
            if ($fatal !== null) {
                self::buffer()->addFatal(['fatal' => $fatal]);
                $status = max($status, 500);
            }

            $buffer = self::$buffer;
            $weight = Sampler::weight(
                $status,
                $durationMs,
                $buffer !== null && $buffer->hasExceptions(),
                $buffer !== null ? $buffer->maxQueryMs() : 0.0,
                $rand,
                $buffer !== null ? $buffer->maxExternalMs() : 0.0
            );
            if ($weight === null) {
                return;
            }

            $breaker = self::breaker();
            if ($breaker->isOpen()) {
                return;
            }

            $sink = self::sink();

            // Só agora (gravação decidida) fecha a saída para o cliente.
            self::finishResponse();

            // Trabalho pesado (descrição, normalização) só depois de liberar a resposta.
            $batch = ['requests' => [self::requestRow($status, $durationMs, $weight, $start)]];
            if ($buffer !== null) {
                $queries = self::queryRows($buffer, $weight);
                if ($queries !== []) {
                    $batch['queries'] = $queries;
                }
                $exceptions = self::exceptionRows($buffer, $weight, $start);
                if ($exceptions !== []) {
                    $batch['exceptions'] = $exceptions;
                }
                $external = self::externalRows($buffer, $weight);
                if ($external !== []) {
                    $batch['external'] = $external;
                }
            }

            try {
                $sink->write($batch);
                $breaker->recordSuccess();
            } catch (\Throwable $e) {
                self::handleWriteFailure($sink, $breaker, $batch, $e);
            }
        } catch (\Throwable) {
            // O monitor nunca derruba a aplicação.
        }
    }

    /** Limpa todo o estado do request (não desfaz o registro do shutdown). */
    public static function reset(): void
    {
        self::$booted     = false;
        self::$flushed    = false;
        self::$ignored    = false;
        self::$requestId  = null;
        self::$start      = null;
        self::$bootMethod = null;
        self::$context    = [];
        self::$sink       = null;
        self::$breaker    = null;
        self::$migrator   = null;
        self::$finisher   = null;
        self::$idGenerator = null;
        self::$fatalProvider = null;
        self::$buffer      = null;
        self::$seenExceptions = [];
    }

    /** Seam de teste: substitui a leitura do último erro (null volta a error_get_last). */
    public static function useFatalProvider(?callable $provider): void
    {
        self::$fatalProvider = $provider === null ? null : \Closure::fromCallable($provider);
    }

    /** Seam de teste: substitui a geração do request id (null volta ao padrão). */
    public static function useIdGenerator(?callable $generator): void
    {
        self::$idGenerator = $generator === null ? null : \Closure::fromCallable($generator);
    }

    /** Seam de teste: substitui o encerramento da resposta (null volta ao padrão). */
    public static function useFinisher(?callable $finisher): void
    {
        self::$finisher = $finisher === null ? null : \Closure::fromCallable($finisher);
    }

    /**
     * Encerra a resposta ao cliente (FPM) para que a gravação não some latência.
     * Só é chamado quando o request vai mesmo ser gravado: requests não
     * registrados não têm a saída fechada.
     */
    private static function finishResponse(): void
    {
        if (self::$finisher !== null) {
            (self::$finisher)();
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /** Seam de teste: substitui o AutoMigrator (null volta ao padrão). */
    public static function useAutoMigrator(?AutoMigrator $migrator): void
    {
        self::$migrator = $migrator;
    }

    /** Seam de teste: substitui o sink (null volta ao padrão). */
    public static function useSink(?SinkInterface $sink): void
    {
        self::$sink = $sink;
    }

    /** Seam de teste: substitui o breaker (null volta ao padrão). */
    public static function useBreaker(?CircuitBreaker $breaker): void
    {
        self::$breaker = $breaker;
    }

    private static function requestRow(int $status, float $durationMs, float $weight, float $start): array
    {
        $env     = \PSF::config('environment');
        $release = Config::get('release');

        return [
            'request_id'     => self::$requestId,
            'ts'             => gmdate('Y-m-d H:i:s', (int) $start),
            'method'         => self::cleanMethod(self::$context['method'] ?? self::$bootMethod),
            'route'          => self::$context['route'] ?? self::UNMATCHED,
            'version'        => self::$context['version'] ?? 0,
            'status'         => $status,
            'duration_ms'    => round($durationMs, 2),
            'memory_kb'      => intdiv(memory_get_peak_usage(true), 1024),
            'user_id'        => self::$context['user_id'] ?? null,
            'env'            => is_string($env) && $env !== '' ? self::cut($env, self::ENV_MAX) : 'dev',
            'app_release'    => is_scalar($release) && !is_bool($release)
                ? self::cut((string) $release, self::RELEASE_MAX)
                : null,
            'dropped_events' => self::$buffer?->droppedEvents() ?? 0,
            'sample_weight'  => $weight,
        ];
    }

    /** Linhas de `queries`: SQL normalizado (sem literais) e hash de agrupamento. */
    private static function queryRows(Buffer $buffer, float $weight): array
    {
        $rows = [];
        foreach ($buffer->queries() as $query) {
            try {
                $sql = self::cut(SqlNormalizer::normalize($query['sql']), SqlNormalizer::MAX_LENGTH);
                $rows[] = [
                    'request_id'      => self::$requestId,
                    'ts'              => gmdate('Y-m-d H:i:s', $query['ts']),
                    'sql_hash'        => SqlNormalizer::hash($sql),
                    'sql_text'        => $sql,
                    'duration_ms'     => round($query['duration_ms'], 2),
                    'connection_name' => self::cut($query['connection'], 64),
                    'sample_weight'   => $weight,
                ];
            } catch (\Throwable) {
                // Evento com problema é descartado; a linha do request continua.
            }
        }
        return $rows;
    }

    /** Linhas de `external`: mesmo peso do request, `ts` do fim de cada chamada. */
    private static function externalRows(Buffer $buffer, float $weight): array
    {
        $rows = [];
        foreach ($buffer->externals() as $call) {
            try {
                $rows[] = [
                    'request_id'    => self::$requestId,
                    'ts'            => gmdate('Y-m-d H:i:s', $call['ts']),
                    'kind'          => $call['kind'],
                    'target'        => $call['target'],
                    'duration_ms'   => round($call['duration_ms'], 2),
                    'status'        => $call['status'],
                    'sample_weight' => $weight,
                ];
            } catch (\Throwable) {
                // Evento com problema é descartado; a linha do request continua.
            }
        }
        return $rows;
    }

    /**
     * Valida e guarda uma chamada externa no buffer (pode lançar: os chamadores
     * públicos engolem). Tipo desconhecido ou duração inválida são ignorados.
     */
    private static function recordExternal(string $kind, string $target, float $durationMs, ?int $status): void
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::EXTERNAL_KINDS, true)) {
            return;
        }
        if (is_nan($durationMs) || is_infinite($durationMs) || $durationMs < 0.0) {
            return;
        }
        $durationMs = self::clampDuration($durationMs);
        $target = $target !== '' && mb_check_encoding($target, 'UTF-8')
            ? self::cut($target, self::TARGET_MAX)
            : self::INVALID_TARGET;

        self::buffer()->addExternal($kind, $target, $durationMs, $status, time());
    }

    /**
     * Limita uma duração ao intervalo gravável em decimal(10,2):
     * [0, DURATION_MAX_MS]; NaN vira 0.
     */
    private static function clampDuration(float $durationMs): float
    {
        if (is_nan($durationMs)) {
            return 0.0;
        }
        return min(self::DURATION_MAX_MS, max(0.0, $durationMs));
    }

    /**
     * Converte o valor devolvido por `statusFrom` em status: inteiro, string só
     * de dígitos ou float com valor inteiro; qualquer outro valor vira null.
     */
    private static function toStatus(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && strlen($value) <= 9 && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e9) {
            return (int) $value;
        }
        return null;
    }

    /** Linhas de `exceptions`, descritas só agora (após liberar a resposta). */
    private static function exceptionRows(Buffer $buffer, float $weight, float $start): array
    {
        $rows = [];
        $ts   = gmdate('Y-m-d H:i:s', (int) $start);
        $storeMessage = (bool) Config::get('storeExceptionMessage', true);
        foreach ($buffer->exceptions() as $event) {
            try {
                if (($event['throwable'] ?? null) instanceof \Throwable) {
                    $described = ExceptionDescriber::describe($event['throwable'], $storeMessage);
                } elseif (is_array($event['fatal'] ?? null)) {
                    $described = ExceptionDescriber::fatal($event['fatal'], $storeMessage);
                } else {
                    continue;
                }
                $rows[] = ['request_id' => self::$requestId, 'ts' => $ts]
                    + $described
                    + ['sample_weight' => $weight];
            } catch (\Throwable) {
                // Evento com problema é descartado; a linha do request continua.
            }
        }
        return $rows;
    }

    /** Último erro do PHP, se for de um tipo fatal; senão null. Nunca lança. */
    private static function readFatal(): ?array
    {
        try {
            $error = self::$fatalProvider !== null ? (self::$fatalProvider)() : error_get_last();
            if (!is_array($error) || !is_int($error['type'] ?? null)) {
                return null;
            }
            return ($error['type'] & self::FATAL_TYPES) !== 0 ? $error : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function buffer(): Buffer
    {
        return self::$buffer ??= new Buffer((int) Config::get('maxEventsPerRequest'));
    }

    private static function extractUserId(mixed $auth): ?string
    {
        // `Router::$auth` pode ter qualquer forma: nada aqui pode lançar.
        try {
            if (!Config::get('storeUser')) {
                return null;
            }

            $property = Config::get('userIdProperty', 'id');
            if (!is_int($property) && (!is_string($property) || $property === '')) {
                return null;
            }

            $value = null;
            if (is_object($auth)) {
                $value = isset($auth->{$property}) ? $auth->{$property} : null;
            } elseif (is_array($auth)) {
                $value = $auth[$property] ?? null;
            }

            if (!is_int($value) && !is_string($value) && !is_float($value)) {
                return null;
            }
            return self::cut((string) $value, self::USER_MAX);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Mantém só letras A-Z (maiúsculas), até o tamanho da coluna; vazio vira GET. */
    private static function cleanMethod(?string $method): string
    {
        $clean = substr((string) preg_replace('/[^A-Z]/', '', strtoupper((string) $method)), 0, self::METHOD_MAX);
        return $clean !== '' ? $clean : 'GET';
    }

    /** Corta em `$max` caracteres sem partir caractere multibyte (UTF-8). */
    private static function cut(string $value, int $max): string
    {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    private static function sink(): SinkInterface
    {
        return self::$sink ??= new DatabaseSink();
    }

    private static function breaker(): CircuitBreaker
    {
        return self::$breaker ??= new CircuitBreaker(
            'db:' . Config::database(),
            (int) Config::get('breaker.failures'),
            (int) Config::get('breaker.cooldownSeconds')
        );
    }

    /**
     * Trata a falha de gravação. Com `autoMigrate` ligado e o esquema
     * comprovadamente ausente, tenta instalar e regravar o lote uma vez;
     * qualquer outra falha segue o fluxo normal do breaker.
     */
    private static function handleWriteFailure(
        SinkInterface $sink,
        CircuitBreaker $breaker,
        array $batch,
        \Throwable $e
    ): void {
        if (Config::get('autoMigrate') && self::schemaMissing($sink)) {
            $migrator = self::autoMigrator();

            switch ($migrator->run()) {
                case AutoMigrator::MIGRATED:
                    // Uma única regravação; sem nova tentativa de migrar.
                    try {
                        $sink->write($batch);
                        $breaker->recordSuccess();
                    } catch (\Throwable $retry) {
                        if ($breaker->recordFailure()) {
                            self::logFailure($retry);
                        }
                    }
                    return;

                case AutoMigrator::LOCKED:
                    // Outro request está migrando: descarta o lote sem contar falha.
                    return;

                case AutoMigrator::REFUSED:
                    if ($breaker->trip()) {
                        error_log('[PSF][monitor] autoMigrate ignorado em prd; rode monitor:install');
                    }
                    return;

                default:
                    if ($breaker->trip()) {
                        error_log('[PSF][monitor] autoMigrate indisponível: '
                            . substr((string) ($migrator->lastError() ?? 'causa desconhecida'), 0, 500));
                    }
                    return;
            }
        }

        if ($breaker->recordFailure()) {
            self::logFailure($e);
        }
    }

    /**
     * Confirma, consultando o banco, que as tabelas faltam. Sink sem verificação
     * de esquema, ou verificação que falha (banco fora do ar), conta como "não".
     */
    private static function schemaMissing(SinkInterface $sink): bool
    {
        if (!method_exists($sink, 'schemaInstalled')) {
            return false;
        }
        try {
            return $sink->schemaInstalled() === false;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function autoMigrator(): AutoMigrator
    {
        if (self::$migrator === null) {
            $env = \PSF::config('environment');
            self::$migrator = new AutoMigrator(new Installer(), is_string($env) && $env !== '' ? $env : 'dev');
        }
        return self::$migrator;
    }

    /** Um único error_log quando o breaker abre, com a causa. */
    private static function logFailure(\Throwable $e): void
    {
        $cause = preg_replace('/\s+/', ' ', get_class($e) . ': ' . $e->getMessage());
        $hint  = \Psf\Monitor\MissingTable::matches($e)
            ? ' Tabelas ausentes: rode monitor:install.'
            : '';

        error_log(sprintf(
            '[PSF][monitor] gravação suspensa por %ds após falhas consecutivas: %s.%s',
            (int) Config::get('breaker.cooldownSeconds'),
            substr((string) $cause, 0, 500),
            $hint
        ));
    }

    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
