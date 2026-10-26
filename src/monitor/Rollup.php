<?php

namespace Psf\Monitor;

use Psf\Database\Dialect\DialectInterface;

/**
 * Agrega as horas completas das tabelas brutas em `psf_monitor_rollups`.
 *
 * Idempotente: cada hora é recalculada inteira a partir das linhas brutas e
 * substitui os registros daquela `bucket_hour` (DELETE + INSERT numa transação),
 * e só então o watermark avança. A agregação é feita em SQL portável (GROUP BY,
 * SUM/CASE/MAX); o PHP só monta a chave e codifica o histograma.
 */
final class Rollup
{
    /** Margem após o fim da hora antes de agregá-la (requests ainda gravando). */
    public const GRACE_SECONDS = 300;

    /**
     * Horas já processadas recalculadas a cada execução (linhas brutas atrasadas
     * com `ts` de até 2 h antes do watermark convergem; mais antigas se perdem).
     */
    public const REAGGREGATE_HOURS = 2;

    /** Tamanho máximo de `metric_key` (varchar 191). */
    private const KEY_LENGTH = 191;

    private const FORMAT = 'Y-m-d H:i:s';
    private const TABLE = 'psf_monitor_rollups';

    /**
     * @param \PDO             $pdo     conexão do banco do monitor (ERRMODE de exceções)
     * @param DialectInterface $dialect dialeto desse banco (quoting e datas)
     */
    public function __construct(
        private \PDO $pdo,
        private DialectInterface $dialect,
    ) {
    }

    /**
     * Processa, em ordem, as horas completas desde o watermark (ou desde a hora
     * da linha bruta mais antiga), no máximo `$maxHours` por execução.
     *
     * Com watermark, antes reagrega as últimas `REAGGREGATE_HOURS` horas já
     * processadas (linhas brutas que chegaram atrasadas, ex.: request iniciado às
     * 10:58 e gravado às 11:06). Essas horas não contam em `$maxHours` nem em
     * `hours`/`rows`; o watermark nunca volta.
     *
     * Hora nova que falha por violação de integridade (SQLSTATE `23xxx`, ex.: chave
     * duplicada por collation do banco) é desfeita e pulada: o watermark avança
     * numa transação própria, ela conta em `skipped` (e em `$maxHours`) e seus
     * brutos ficam até o prune — os agregados dela se perdem. Qualquer outra falha
     * desfaz só aquela hora e propaga a exceção.
     *
     * @return array{hours: int, rows: int, watermark: ?string, reaggregated: int, skipped: int}
     * @throws \InvalidArgumentException `$maxHours` < 1
     * @throws \PDOException             falha de banco (fora violação de integridade) ou ao ler o watermark
     */
    public function run(\DateTimeImmutable $now, int $maxHours = 48): array
    {
        if ($maxHours < 1) {
            throw new \InvalidArgumentException('maxHours inválido: informe um inteiro >= 1.');
        }

        $utc       = new \DateTimeZone('UTC');
        $nowTs     = $now->setTimezone($utc)->getTimestamp();
        $watermarker = new Watermark($this->pdo, $this->dialect);
        $watermark = $watermarker->read();
        $oldest    = $this->oldestRawTs();

        $start = $watermark ?? $oldest;
        if ($start === null) {
            return ['hours' => 0, 'rows' => 0, 'watermark' => null, 'reaggregated' => 0, 'skipped' => 0];
        }

        $hour = $this->floorHour($start);
        $reaggregated = 0;
        if ($watermark !== null && $oldest !== null) {
            $reaggregated = $this->reaggregate($hour, $this->floorHour($oldest), $nowTs);
        }

        $hours   = 0;
        $rows    = 0;
        $skipped = 0;
        while ($hours + $skipped < $maxHours && $hour + 3600 + self::GRACE_SECONDS <= $nowTs) {
            $from = gmdate(self::FORMAT, $hour);
            $to   = gmdate(self::FORMAT, $hour + 3600);

            $written = $this->processHour($from, $to, $watermarker);
            if ($written === null) {
                // Hora desfeita por violação de integridade: avança sem ela (falha aqui propaga).
                $watermarker->write($to);
                $skipped++;
            } else {
                $rows += $written;
                $hours++;
            }
            $watermark = $to;
            $hour += 3600;
        }

        return [
            'hours'        => $hours,
            'rows'         => $rows,
            'watermark'    => $watermark,
            'reaggregated' => $reaggregated,
            'skipped'      => $skipped,
        ];
    }

    /**
     * Recalcula as horas `[watermark − REAGGREGATE_HOURS, watermark)`, nunca antes
     * da hora bruta mais antiga nem antes de `now − 1 dia` (linhas mais novas que
     * 1 dia nunca são apagadas, pois `rawDays >= 1`). Hora sem nenhuma linha
     * bruta é pulada, para não apagar um agregado cujos brutos já foram limpos.
     * Não grava o watermark. Hora que falha por violação de integridade é desfeita
     * e mantém o agregado anterior (não conta como recalculada).
     *
     * @return int horas recalculadas
     */
    private function reaggregate(int $watermarkHour, int $oldestHour, int $nowTs): int
    {
        // `now − 1 dia` arredondado PARA CIMA à hora cheia: a janela sempre começa em
        // hora cheia (senão gravaria bucket_hour fora da hora e contaria linhas em dobro).
        $dayAgoHour = intdiv($nowTs - 86400 + 3599, 3600) * 3600;
        $start = max($watermarkHour - self::REAGGREGATE_HOURS * 3600, $oldestHour, $dayAgoHour);
        $count = 0;
        for ($hour = $start; $hour < $watermarkHour; $hour += 3600) {
            $from = gmdate(self::FORMAT, $hour);
            $to   = gmdate(self::FORMAT, $hour + 3600);
            if (!$this->hasRawRows($from, $to)) {
                continue;
            }
            if ($this->processHour($from, $to, null) !== null) {
                $count++;
            }
        }
        return $count;
    }

    /** Indica se alguma tabela bruta tem linha em `[from, to)`. */
    private function hasRawRows(string $from, string $to): bool
    {
        foreach (Pruner::RAW_TABLES as $table) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q($table)
                . ' WHERE ' . $this->q('ts') . ' >= :from AND ' . $this->q('ts') . ' < :to'
            );
            $stmt->execute([':from' => $this->bind($from), ':to' => $this->bind($to)]);
            $n = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($n > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recalcula uma hora numa transação própria (e avança o watermark, se dado).
     *
     * @return ?int registros gravados, ou null se a hora foi desfeita por violação
     *              de integridade ao agregar/gravar (falha ao gravar o watermark propaga)
     */
    private function processHour(string $from, string $to, ?Watermark $watermarker): ?int
    {
        $this->pdo->beginTransaction();
        try {
            try {
                $records = [];
                foreach ($this->aggregators() as $aggregate) {
                    foreach ($aggregate($from, $to) as $record) {
                        $records[] = $record;
                    }
                }
                $records = $this->mergeByKey($records);

                $delete = $this->pdo->prepare(
                    'DELETE FROM ' . $this->q(self::TABLE) . ' WHERE ' . $this->q('bucket_hour') . ' = :h'
                );
                $delete->execute([':h' => $this->bind($from)]);

                if ($records !== []) {
                    $this->insert($from, $records);
                }
            } catch (\PDOException $e) {
                if (!self::isIntegrityViolation($e)) {
                    throw $e;
                }
                $this->pdo->rollBack();
                return null;
            }

            $watermarker?->write($to);
            $this->pdo->commit();
            return count($records);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** SQLSTATE de classe 23 (`23000`, `23505`...): violação de integridade. */
    private static function isIntegrityViolation(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        return str_starts_with($state, '23') || str_starts_with((string) $e->getCode(), '23');
    }

    /**
     * Agregadores por métrica, executados na mesma transação de cada hora.
     * Cada um recebe o intervalo `[from, to)` e devolve registros no formato
     * de `insert()`. Novas métricas entram nesta lista.
     *
     * @return array<int, callable(string, string): array<int, array<string, mixed>>>
     */
    private function aggregators(): array
    {
        return [
            fn (string $from, string $to): array => $this->aggregateRequests($from, $to),
            fn (string $from, string $to): array => $this->aggregateQueries($from, $to),
            fn (string $from, string $to): array => $this->aggregateExceptions($from, $to),
            fn (string $from, string $to): array => $this->aggregateExternal($from, $to),
        ];
    }

    /**
     * Métrica `request`: agrupa por método e rota.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRequests(string $from, string $to): array
    {
        $method = $this->q('method');
        $route  = $this->q('route');
        $status = $this->q('status');
        $ms     = $this->q('duration_ms');
        $w      = $this->q('sample_weight');
        $ts     = $this->q('ts');

        $sql = "SELECT {$method} AS m, {$route} AS r, SUM({$w}) AS hits,"
            . " SUM(CASE WHEN {$status} >= 500 THEN {$w} ELSE 0 END) AS errors,"
            . " SUM({$w} * {$ms}) AS sum_ms, MAX({$ms}) AS max_ms, MAX({$ts}) AS last_ts"
            . $this->histogramColumns($ms, $w)
            . ' FROM ' . $this->q('psf_monitor_requests')
            . " WHERE {$ts} >= :from AND {$ts} < :to"
            . " GROUP BY {$method}, {$route}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':from' => $this->bind($from), ':to' => $this->bind($to)]);

        $records = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $records[] = [
                'metric'      => 'request',
                'metric_key'  => $row['m'] . ' ' . $row['r'],
                'hits'        => (float) $row['hits'],
                'errors'      => (float) $row['errors'],
                'sum_ms'      => (float) $row['sum_ms'],
                'max_ms'      => (float) $row['max_ms'],
                'histogram'   => $this->histogramFrom($row),
                'sample_text' => null,
                'last_ts'     => $row['last_ts'],
            ];
        }
        $stmt->closeCursor();
        return $records;
    }

    /**
     * Métrica `query`: agrupa por `sql_hash`; `sample_text` é o `sql_text` da
     * linha de menor id do hash na hora.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregateQueries(string $from, string $to): array
    {
        $hash = $this->q('sql_hash');
        $ms   = $this->q('duration_ms');
        $w    = $this->q('sample_weight');
        $ts   = $this->q('ts');

        $sql = "SELECT {$hash} AS k, MIN({$this->q('id')}) AS min_id, SUM({$w}) AS hits,"
            . " SUM({$w} * {$ms}) AS sum_ms, MAX({$ms}) AS max_ms, MAX({$ts}) AS last_ts"
            . $this->histogramColumns($ms, $w)
            . ' FROM ' . $this->q('psf_monitor_queries')
            . " WHERE {$ts} >= :from AND {$ts} < :to GROUP BY {$hash}";
        $rows = $this->fetchGroups($sql, $from, $to);

        $texts = $this->sampleTexts('psf_monitor_queries', 'sql_text', array_column($rows, 'min_id'));

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'metric'      => 'query',
                'metric_key'  => $row['k'],
                'hits'        => (float) $row['hits'],
                'errors'      => 0.0,
                'sum_ms'      => (float) $row['sum_ms'],
                'max_ms'      => (float) $row['max_ms'],
                'histogram'   => $this->histogramFrom($row),
                'sample_text' => $texts[(int) $row['min_id']] ?? null,
                'last_ts'     => $row['last_ts'],
            ];
        }
        return $records;
    }

    /**
     * Métrica `exception`: agrupa por classe; todo o peso conta como erro e
     * `sample_text` é a mensagem da linha de menor id da classe na hora.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregateExceptions(string $from, string $to): array
    {
        $class = $this->q('exception_class');
        $w     = $this->q('sample_weight');
        $ts    = $this->q('ts');

        $sql = "SELECT {$class} AS k, MIN({$this->q('id')}) AS min_id, SUM({$w}) AS hits, MAX({$ts}) AS last_ts"
            . ' FROM ' . $this->q('psf_monitor_exceptions')
            . " WHERE {$ts} >= :from AND {$ts} < :to GROUP BY {$class}";
        $rows = $this->fetchGroups($sql, $from, $to);

        $texts = $this->sampleTexts('psf_monitor_exceptions', 'message', array_column($rows, 'min_id'));

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'metric'      => 'exception',
                'metric_key'  => $row['k'],
                'hits'        => (float) $row['hits'],
                'errors'      => (float) $row['hits'],
                'sum_ms'      => 0.0,
                'max_ms'      => 0.0,
                'histogram'   => Histogram::encode(Histogram::empty()),
                'sample_text' => $texts[(int) $row['min_id']] ?? null,
                'last_ts'     => $row['last_ts'],
            ];
        }
        return $records;
    }

    /**
     * Métrica `external`: agrupa por tipo e alvo (`metric_key = "<kind>:<target>"`).
     * Erro = sem status (falha de transporte) ou status >= 500; 4xx não conta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregateExternal(string $from, string $to): array
    {
        $kind   = $this->q('kind');
        $target = $this->q('target');
        $status = $this->q('status');
        $ms     = $this->q('duration_ms');
        $w      = $this->q('sample_weight');
        $ts     = $this->q('ts');

        $sql = "SELECT {$kind} AS k, {$target} AS t, SUM({$w}) AS hits,"
            . " SUM(CASE WHEN {$status} IS NULL OR {$status} >= 500 THEN {$w} ELSE 0 END) AS errors,"
            . " SUM({$w} * {$ms}) AS sum_ms, MAX({$ms}) AS max_ms, MAX({$ts}) AS last_ts"
            . $this->histogramColumns($ms, $w)
            . ' FROM ' . $this->q('psf_monitor_external')
            . " WHERE {$ts} >= :from AND {$ts} < :to"
            . " GROUP BY {$kind}, {$target}";

        $records = [];
        foreach ($this->fetchGroups($sql, $from, $to) as $row) {
            $records[] = [
                'metric'      => 'external',
                'metric_key'  => $row['k'] . ':' . $row['t'],
                'hits'        => (float) $row['hits'],
                'errors'      => (float) $row['errors'],
                'sum_ms'      => (float) $row['sum_ms'],
                'max_ms'      => (float) $row['max_ms'],
                'histogram'   => $this->histogramFrom($row),
                'sample_text' => null,
                'last_ts'     => $row['last_ts'],
            ];
        }
        return $records;
    }

    /**
     * Executa um agrupamento sobre `[from, to)` e devolve todas as linhas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchGroups(string $sql, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':from' => $this->bind($from), ':to' => $this->bind($to)]);
        $rows = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            // Aliases podem voltar em maiúsculas conforme o driver.
            $rows[] = array_change_key_case($row, CASE_LOWER);
        }
        $stmt->closeCursor();
        return $rows;
    }

    /**
     * Textos das linhas de menor id, sem `MAX()` sobre coluna de texto (não
     * portável): busca por `id IN (...)` em blocos de até 500 ids.
     *
     * @param array<int, mixed> $ids
     * @return array<int, ?string> id => texto
     */
    private function sampleTexts(string $table, string $column, array $ids): array
    {
        $texts = [];
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $stmt  = $this->pdo->prepare(
                'SELECT ' . $this->q('id') . ', ' . $this->q($column)
                . ' FROM ' . $this->q($table) . ' WHERE ' . $this->q('id') . " IN ({$marks})"
            );
            $stmt->execute($chunk);
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $texts[(int) $row[0]] = $row[1] === null ? null : (string) $row[1];
            }
            $stmt->closeCursor();
        }
        return $texts;
    }

    /**
     * Colunas `h0..h9` com `Σ peso` por bucket de `Histogram::EDGES`: o primeiro
     * bucket não tem limite inferior e o último não tem superior. As bordas são
     * constantes inteiras da classe (não entrada externa), por isso vão literais.
     */
    private function histogramColumns(string $ms, string $w): string
    {
        $edges = array_map('intval', Histogram::EDGES);
        $sql = '';
        for ($i = 0, $n = Histogram::bucketCount(); $i < $n; $i++) {
            $conds = [];
            if ($i > 0) {
                $conds[] = "{$ms} >= " . $edges[$i - 1];
            }
            if ($i < $n - 1) {
                $conds[] = "{$ms} < " . $edges[$i];
            }
            $sql .= ', SUM(CASE WHEN ' . implode(' AND ', $conds) . " THEN {$w} ELSE 0 END) AS h{$i}";
        }
        return $sql;
    }

    /** Histograma codificado a partir das colunas `h0..h9` (decimais podem vir como string). */
    private function histogramFrom(array $row): string
    {
        $counts = [];
        for ($i = 0, $n = Histogram::bucketCount(); $i < $n; $i++) {
            $counts[] = (float) ($row["h{$i}"] ?? $row["H{$i}"] ?? 0);
        }
        return Histogram::encode($counts);
    }

    /**
     * Trunca `metric_key` e funde registros que passam a ter a mesma chave
     * `(metric, metric_key)` — senão a PK da hora colidiria e o rollup travaria.
     * A comparação ignora caixa e espaços à direita (como collations comuns);
     * a chave gravada é a menor grafia em ordem binária (independe do driver).
     * Soma hits/errors/sum_ms e histogramas; máximo de max_ms e last_ts;
     * sample_text = primeiro não nulo na ordem de entrada.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    private function mergeByKey(array $records): array
    {
        $merged = [];
        foreach ($records as $r) {
            $r['metric_key'] = mb_substr((string) $r['metric_key'], 0, self::KEY_LENGTH, 'UTF-8');
            $r['last_ts']    = $this->normalizeTs($r['last_ts']);
            // Id sem caixa e sem espaços à direita: collations case-insensitive e PAD SPACE
            // tratariam as duas grafias como a mesma chave da PK. Fica a menor grafia em
            // ordem binária: a ordem das linhas do GROUP BY varia por driver.
            $id = $r['metric'] . "\0" . mb_strtolower(rtrim($r['metric_key']), 'UTF-8');

            if (!isset($merged[$id])) {
                $merged[$id] = $r;
                continue;
            }

            $m = &$merged[$id];
            if (strcmp($r['metric_key'], $m['metric_key']) < 0) {
                $m['metric_key'] = $r['metric_key'];
            }
            $m['hits']   += $r['hits'];
            $m['errors'] += $r['errors'];
            $m['sum_ms'] += $r['sum_ms'];
            $m['max_ms']  = max($m['max_ms'], $r['max_ms']);
            $m['histogram'] = Histogram::encode(
                Histogram::merge(Histogram::decode($m['histogram']), Histogram::decode($r['histogram']))
            );
            $m['sample_text'] ??= $r['sample_text'];
            if ($r['last_ts'] !== null && ($m['last_ts'] === null || $r['last_ts'] > $m['last_ts'])) {
                $m['last_ts'] = $r['last_ts'];
            }
            unset($m);
        }
        return array_values($merged);
    }

    /**
     * Grava os registros da hora. Números vão com escala fixa (sem notação
     * científica, que o SQL Server não converte para decimal).
     *
     * @param array<int, array<string, mixed>> $records
     */
    private function insert(string $bucketHour, array $records): void
    {
        $cols = ['bucket_hour', 'metric', 'metric_key', 'hits', 'errors', 'sum_ms', 'max_ms', 'histogram', 'sample_text', 'last_ts'];
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->q(self::TABLE)
            . ' (' . implode(', ', array_map(fn (string $c): string => $this->q($c), $cols)) . ')'
            . ' VALUES (:' . implode(', :', $cols) . ')'
        );

        foreach ($records as $r) {
            $stmt->execute([
                ':bucket_hour' => $this->bind($bucketHour),
                ':metric'      => $r['metric'],
                ':metric_key'  => mb_substr((string) $r['metric_key'], 0, self::KEY_LENGTH, 'UTF-8'),
                ':hits'        => $this->decimal($r['hits'], 4),
                ':errors'      => $this->decimal($r['errors'], 4),
                ':sum_ms'      => $this->decimal($r['sum_ms'], 2),
                ':max_ms'      => $this->decimal($r['max_ms'], 2),
                ':histogram'   => $r['histogram'],
                ':sample_text' => $r['sample_text'],
                ':last_ts'     => $this->bindNullable($this->normalizeTs($r['last_ts'])),
            ]);
        }
    }

    /** Menor `ts` entre as tabelas brutas, ou null se estiverem vazias. */
    private function oldestRawTs(): ?string
    {
        $oldest = null;
        foreach (Pruner::RAW_TABLES as $table) {
            $value = $this->pdo->query(
                'SELECT MIN(' . $this->q('ts') . ') FROM ' . $this->q($table)
            )->fetchColumn();
            $ts = $this->normalizeTs($value);
            if ($ts !== null && ($oldest === null || $ts < $oldest)) {
                $oldest = $ts;
            }
        }
        return $oldest;
    }

    /** Timestamp Unix (UTC) do início da hora de um `Y-m-d H:i:s`. */
    private function floorHour(string $ts): int
    {
        $dt = new \DateTimeImmutable($ts, new \DateTimeZone('UTC'));
        return $dt->setTime((int) $dt->format('H'), 0)->getTimestamp();
    }

    /**
     * Normaliza um datetime vindo do driver (ex.: `.000` do SQL Server) para
     * `Y-m-d H:i:s`; null/vazio/inválido → null.
     */
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

    /** Valor ligado a coluna datetime (forma ISO só no SQL Server, ver SqlDate). */
    private function bind(string $ymdHis): string
    {
        return SqlDate::bind($ymdHis, $this->dialect);
    }

    private function bindNullable(?string $ymdHis): ?string
    {
        return $ymdHis === null ? null : $this->bind($ymdHis);
    }

    private function decimal(mixed $value, int $scale): string
    {
        $v = (float) $value;
        return number_format(is_finite($v) ? $v : 0.0, $scale, '.', '');
    }

    private function q(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }
}
