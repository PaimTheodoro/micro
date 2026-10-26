<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectFactory;
use Psf\Database\Dialect\DialectInterface;
use Psf\Enumerators\DBDriver;
use Psf\Helper\ConfigKeys;

/**
 * API de leitura (somente leitura) dos dados do monitor.
 *
 * Agregados vêm de `psf_monitor_rollups` (rápido, retenção de agregados);
 * só `request()` lê as tabelas brutas. Devolve arrays simples, com números
 * convertidos para float.
 *
 * Intervalos: `from`/`to` são convertidos para UTC; um registro de rollup entra
 * quando `bucket_hour >= hora(from)` e `bucket_hour < to` (a hora de `from` é
 * arredondada para baixo, `to` é exclusivo). `from >= to` devolve `[]`.
 *
 * Falhas de banco propagam como `\PDOException`; histograma corrompido nunca
 * lança (vira histograma vazio: não contribui para o intervalo; percentis 0.0
 * só se todas as horas do intervalo estiverem corrompidas).
 */
final class Reader
{
    private const TABLE  = 'psf_monitor_rollups';
    private const FORMAT = 'Y-m-d H:i:s';

    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 1000;

    /** Chaves por lote em `IN (...)` (SQL Server aceita no máximo 2100 parâmetros). */
    private const CHUNK = 500;

    /**
     * Whitelist de ordenação de `routes()`: cada opção mapeia para uma expressão
     * SQL fixa — o valor recebido nunca é interpolado. `avgMs` é calculado em
     * SQL com guarda de divisão por zero; o `* 1.0` evita divisão inteira no
     * SQLite (colunas decimais podem ser guardadas como INTEGER lá).
     */
    private const ROUTE_ORDER = [
        'sumMs'  => 'SUM({sum_ms})',
        'hits'   => 'SUM({hits})',
        'errors' => 'SUM({errors})',
        'maxMs'  => 'MAX({max_ms})',
        'avgMs'  => 'CASE WHEN SUM({hits}) > 0 THEN SUM({sum_ms}) * 1.0 / SUM({hits}) ELSE 0 END',
    ];

    private ?DialectInterface $dialect = null;

    /**
     * @param string|null $database chave de config['db'] (padrão: `settings.monitor.database`)
     * @param \PDO|null    $pdo      conexão já aberta; sem ela, abre a conexão dedicada no primeiro uso
     */
    public function __construct(
        private ?string $database = null,
        private ?\PDO $pdo = null,
    ) {
    }

    /**
     * Rotas (metric `request`) ordenadas de forma decrescente por `$orderBy`
     * (`sumMs|hits|errors|maxMs|avgMs`; qualquer outro valor vira `sumMs`; o alias legado `sum_ms` etc. também vale).
     *
     * @return array<int, array{key: string, method: string, route: string, hits: float, errors: float, errorRate: float, avgMs: float, maxMs: float, p50Ms: float, p95Ms: float, p99Ms: float}>
     */
    public function routes(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20, string $orderBy = 'sumMs'): array
    {
        $items = $this->ranking('request', $from, $to, $limit, $orderBy);
        foreach ($items as &$item) {
            // Chave gravada pelo Rollup: "MÉTODO rota" (separada no primeiro espaço).
            $parts = explode(' ', $item['key'], 2);
            [$method, $route] = count($parts) === 2 ? $parts : ['', $item['key']];
            $item = ['key' => $item['key'], 'method' => $method, 'route' => $route] + array_slice($item, 1);
        }
        unset($item);
        return $items;
    }

    /**
     * Chamadas externas (metric `external`) por `tipo:alvo`, com as mesmas regras
     * de `routes()` (faixa, limite, `$orderBy` em lista fixa, percentis fundidos).
     * `kind`/`target` são separados no PRIMEIRO `:` da chave (o alvo pode ter `:`,
     * ex.: host IPv6).
     *
     * @return array<int, array{key: string, kind: string, target: string, hits: float, errors: float, errorRate: float, avgMs: float, maxMs: float, p50Ms: float, p95Ms: float, p99Ms: float}>
     */
    public function external(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20, string $orderBy = 'sumMs'): array
    {
        $items = $this->ranking('external', $from, $to, $limit, $orderBy);
        foreach ($items as &$item) {
            $parts = explode(':', $item['key'], 2);
            [$kind, $target] = count($parts) === 2 ? $parts : ['', $item['key']];
            $item = ['key' => $item['key'], 'kind' => $kind, 'target' => $target] + array_slice($item, 1);
        }
        unset($item);
        return $items;
    }

    /**
     * Miolo comum de `routes()` e `external()`: top-N de uma métrica com taxa de
     * erro, média e percentis do histograma fundido (só do top-N).
     *
     * @return array<int, array{key: string, hits: float, errors: float, errorRate: float, avgMs: float, maxMs: float, p50Ms: float, p95Ms: float, p99Ms: float}>
     */
    private function ranking(string $metric, \DateTimeInterface $from, \DateTimeInterface $to, int $limit, string $orderBy): array
    {
        $range = $this->range($from, $to);
        if ($range === null) {
            return [];
        }

        // Aceita camelCase (`sumMs`) e o alias legado (`sum_ms`); fora da lista vira `sumMs`
        $order = self::ROUTE_ORDER[ConfigKeys::camelKey($orderBy)] ?? self::ROUTE_ORDER['sumMs'];
        $rows  = $this->aggregate($metric, $range, $this->limit($limit), $order);
        $hists = $this->histograms($metric, $range, array_column($rows, 'k'));

        $out = [];
        foreach ($rows as $row) {
            $key    = (string) $row['k'];
            $hits   = (float) $row['hits'];
            $errors = (float) $row['errors'];
            $maxMs  = (float) $row['max_ms'];
            $hist   = $hists[$key] ?? Histogram::empty();

            $out[] = [
                'key'        => $key,
                'hits'       => $hits,
                'errors'     => $errors,
                'errorRate' => $this->ratio($errors, $hits),
                'avgMs'      => $this->ratio((float) $row['sum_ms'], $hits),
                'maxMs'      => $maxMs,
                'p50Ms'      => Histogram::percentile($hist, 50, $maxMs),
                'p95Ms'      => Histogram::percentile($hist, 95, $maxMs),
                'p99Ms'      => Histogram::percentile($hist, 99, $maxMs),
            ];
        }
        return $out;
    }

    /**
     * Queries (metric `query`) por `sql_hash`, ordenadas por tempo total
     * decrescente; `sql` é o `sample_text` da hora mais recente do hash.
     *
     * @return array<int, array{sqlHash: string, sql: ?string, hits: float, avgMs: float, maxMs: float, p95Ms: float}>
     */
    public function slowQueries(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20): array
    {
        $range = $this->range($from, $to);
        if ($range === null) {
            return [];
        }

        $rows  = $this->aggregate('query', $range, $this->limit($limit), self::ROUTE_ORDER['sumMs']);
        $keys  = array_column($rows, 'k');
        $hists = $this->histograms('query', $range, $keys);
        $texts = $this->latestTexts('query', $rows);

        $out = [];
        foreach ($rows as $row) {
            $key   = (string) $row['k'];
            $hits  = (float) $row['hits'];
            $maxMs = (float) $row['max_ms'];
            $out[] = [
                'sqlHash' => $key,
                'sql'      => $texts[$key] ?? null,
                'hits'     => $hits,
                'avgMs'    => $this->ratio((float) $row['sum_ms'], $hits),
                'maxMs'    => $maxMs,
                'p95Ms'    => Histogram::percentile($hists[$key] ?? Histogram::empty(), 95, $maxMs),
            ];
        }
        return $out;
    }

    /**
     * Exceções (metric `exception`) por classe, ordenadas por `hits`
     * decrescente; `lastTs` é a maior ocorrência e `message` o `sample_text`
     * da hora mais recente da classe.
     *
     * @return array<int, array{exceptionClass: string, hits: float, lastTs: ?string, message: ?string}>
     */
    public function exceptions(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 20): array
    {
        $range = $this->range($from, $to);
        if ($range === null) {
            return [];
        }

        $rows  = $this->aggregate('exception', $range, $this->limit($limit), self::ROUTE_ORDER['hits']);
        $texts = $this->latestTexts('exception', $rows);

        $out = [];
        foreach ($rows as $row) {
            $key   = (string) $row['k'];
            $out[] = [
                'exceptionClass' => $key,
                'hits'            => (float) $row['hits'],
                'lastTs'           => $this->normalizeTs($row['last_ts']),
                'message'         => $texts[$key] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Série por hora (ordem crescente) da metric `request`. Horas sem nenhum
     * registro de rollup não aparecem (a série não é preenchida com zeros).
     *
     * @return array<int, array{hour: ?string, hits: float, errors: float, avgMs: float}>
     */
    public function throughput(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $range = $this->range($from, $to);
        if ($range === null) {
            return [];
        }

        $h   = $this->q('bucket_hour');
        $sql = "SELECT {$h} AS bh, SUM({$this->q('hits')}) AS hits, SUM({$this->q('errors')}) AS errors,"
            . " SUM({$this->q('sum_ms')}) AS sum_ms"
            . ' FROM ' . $this->q(self::TABLE)
            . " WHERE {$this->q('metric')} = ? AND {$h} >= ? AND {$h} < ?"
            . " GROUP BY {$h} ORDER BY {$h} ASC";

        $out = [];
        foreach ($this->fetchAll($sql, ['request', $range[0], $range[1]]) as $row) {
            $hits  = (float) $row['hits'];
            $out[] = [
                'hour'   => $this->normalizeTs($row['bh']),
                'hits'   => $hits,
                'errors' => (float) $row['errors'],
                'avgMs' => $this->ratio((float) $row['sum_ms'], $hits),
            ];
        }
        return $out;
    }

    /**
     * Detalhe de um request das tabelas **brutas**: a linha do request, suas
     * queries, exceções e chamadas externas (em ordem de `id`). As linhas vêm como o driver as
     * devolve. `null` se o request não existir (inclusive após a retenção bruta).
     *
     * @return array{request: array<string, mixed>, queries: array<int, array<string, mixed>>, exceptions: array<int, array<string, mixed>>, external: array<int, array<string, mixed>>}|null
     */
    public function request(string $requestId): ?array
    {
        if ($requestId === '') {
            return null;
        }

        $requests = $this->rawRows('psf_monitor_requests', $requestId);
        if ($requests === []) {
            return null;
        }

        return [
            'request'    => $requests[0],
            'queries'    => $this->rawRows('psf_monitor_queries', $requestId),
            'exceptions' => $this->rawRows('psf_monitor_exceptions', $requestId),
            'external'   => $this->rawRows('psf_monitor_external', $requestId),
        ];
    }

    /**
     * Top-N de uma métrica: somas, máximos e a hora mais recente por chave,
     * já ordenado e limitado em SQL.
     *
     * @param array{0: string, 1: string} $range
     * @return array<int, array<string, mixed>>
     */
    private function aggregate(string $metric, array $range, int $limit, string $orderTemplate): array
    {
        $order = strtr($orderTemplate, [
            '{sum_ms}' => $this->q('sum_ms'),
            '{hits}'   => $this->q('hits'),
            '{errors}' => $this->q('errors'),
            '{max_ms}' => $this->q('max_ms'),
        ]);
        $key = $this->q('metric_key');
        $h   = $this->q('bucket_hour');

        $sql = 'SELECT ' . $this->dialect()->topClause($limit, null)
            . "{$key} AS k, SUM({$this->q('hits')}) AS hits, SUM({$this->q('errors')}) AS errors,"
            . " SUM({$this->q('sum_ms')}) AS sum_ms, MAX({$this->q('max_ms')}) AS max_ms,"
            . " MAX({$this->q('last_ts')}) AS last_ts, MAX({$h}) AS latest"
            . ' FROM ' . $this->q(self::TABLE)
            . " WHERE {$this->q('metric')} = ? AND {$h} >= ? AND {$h} < ?"
            . " GROUP BY {$key}"
            . " ORDER BY {$order} DESC, {$key} ASC"
            . $this->dialect()->limitOffsetClause($limit, null);

        return $this->fetchAll($sql, [$metric, $range[0], $range[1]]);
    }

    /**
     * Histogramas fundidos (soma de todas as horas do intervalo), lidos só das
     * chaves pedidas, em lotes.
     *
     * @param array{0: string, 1: string} $range
     * @param array<int, mixed> $keys
     * @return array<string, float[]> chave => contagens
     */
    private function histograms(string $metric, array $range, array $keys): array
    {
        $key = $this->q('metric_key');
        $h   = $this->q('bucket_hour');

        $merged = [];
        foreach (array_chunk(array_map('strval', $keys), self::CHUNK) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $sql   = "SELECT {$key} AS k, {$this->q('histogram')} AS hist FROM " . $this->q(self::TABLE)
                . " WHERE {$this->q('metric')} = ? AND {$h} >= ? AND {$h} < ? AND {$key} IN ({$marks})";

            foreach ($this->fetchAll($sql, array_merge([$metric, $range[0], $range[1]], $chunk)) as $row) {
                $k = (string) $row['k'];
                $hist = Histogram::decode(is_string($row['hist']) ? $row['hist'] : null);
                $merged[$k] = Histogram::merge($merged[$k] ?? Histogram::empty(), $hist);
            }
        }
        return $merged;
    }

    /**
     * `sample_text` da hora mais recente de cada chave. Sem `MAX()` sobre texto
     * (o SQL Server não aceita para `text`): a hora vem do agregado (`latest`)
     * e o texto é lido pelos pares exatos (bucket_hour, metric_key), em lotes.
     *
     * @param array<int, array<string, mixed>> $rows linhas de aggregate()
     * @return array<string, ?string> chave => texto
     */
    private function latestTexts(string $metric, array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $latest = $this->normalizeTs($row['latest']);
            if ($latest !== null) {
                $pairs[] = [$latest, (string) $row['k']];
            }
        }

        $h   = $this->q('bucket_hour');
        $key = $this->q('metric_key');

        $texts = [];
        // 2 parâmetros por par + 1 da métrica: lotes de 300 ficam bem abaixo de 2100.
        foreach (array_chunk($pairs, 300) as $chunk) {
            $conds  = [];
            $params = [$metric];
            foreach ($chunk as [$bucket, $k]) {
                $conds[]  = "({$h} = ? AND {$key} = ?)";
                $params[] = SqlDate::bind($bucket, $this->dialect());
                $params[] = $k;
            }
            $sql = "SELECT {$key} AS k, {$this->q('sample_text')} AS t FROM " . $this->q(self::TABLE)
                . " WHERE {$this->q('metric')} = ? AND (" . implode(' OR ', $conds) . ')';

            foreach ($this->fetchAll($sql, $params) as $row) {
                $texts[(string) $row['k']] = $row['t'] === null ? null : (string) $row['t'];
            }
        }
        return $texts;
    }

    /**
     * Linhas brutas de um request, em ordem de `id`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rawRows(string $table, string $requestId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM ' . $this->q($table)
            . ' WHERE ' . $this->q('request_id') . ' = ? ORDER BY ' . $this->q('id') . ' ASC'
        );
        $stmt->execute([$requestId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // Colunas em snake_case no banco; camelCase na saída
        return array_map(static fn (array $row): array => ConfigKeys::camelize($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>> aliases em minúsculas (alguns drivers devolvem em maiúsculas)
     */
    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $rows[] = array_change_key_case($row, CASE_LOWER);
        }
        $stmt->closeCursor();
        return $rows;
    }

    /**
     * Converte o intervalo para UTC: `from` arredondado para o início da hora,
     * `to` exclusivo (frações de segundo arredondam para cima, para não perder
     * a hora que começa exatamente no segundo truncado).
     *
     * @return array{0: string, 1: string}|null null quando `from >= to`
     */
    private function range(\DateTimeInterface $from, \DateTimeInterface $to): ?array
    {
        if ($from >= $to) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $f   = \DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $t   = \DateTimeImmutable::createFromInterface($to)->setTimezone($utc);

        $f = $f->setTime((int) $f->format('H'), 0);
        if ((int) $t->format('u') > 0) {
            $t = $t->setTime((int) $t->format('H'), (int) $t->format('i'), (int) $t->format('s'))->modify('+1 second');
        }
        // Valores ligados a `bucket_hour` (forma ISO só no SQL Server, ver SqlDate).
        return [
            SqlDate::bind($f->format(self::FORMAT), $this->dialect()),
            SqlDate::bind($t->format(self::FORMAT), $this->dialect()),
        ];
    }

    private function limit(int $limit): int
    {
        return max(self::MIN_LIMIT, min(self::MAX_LIMIT, $limit));
    }

    private function ratio(float $a, float $b): float
    {
        return $b > 0.0 ? $a / $b : 0.0;
    }

    /** Normaliza um datetime do driver (ex.: `.000` do SQL Server) para `Y-m-d H:i:s`. */
    private function normalizeTs(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(self::FORMAT);
        } catch (\Exception) {
            return null;
        }
    }

    /** Conexão dedicada do monitor, aberta só no primeiro uso. */
    private function pdo(): \PDO
    {
        return $this->pdo ??= Connection::open($this->databaseName());
    }

    /**
     * Dialeto do banco configurado; sem config (PDO injetado avulso), deduzido
     * do driver do PDO.
     */
    private function dialect(): DialectInterface
    {
        if ($this->dialect !== null) {
            return $this->dialect;
        }
        $config = \PSF::config('db', $this->databaseName());
        if (is_array($config)) {
            return $this->dialect = DialectFactory::fromConfig($config);
        }

        $driver = match ($this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => DBDriver::SQLite,
            'pgsql'  => DBDriver::PostgreSQL,
            'sqlsrv', 'dblib' => DBDriver::SQLServer,
            default  => DBDriver::MySQL,
        };
        return $this->dialect = DialectFactory::make($driver);
    }

    private function databaseName(): string
    {
        return $this->database ?? Config::database();
    }

    private function q(string $identifier): string
    {
        return $this->dialect()->quoteIdentifier($identifier);
    }
}
