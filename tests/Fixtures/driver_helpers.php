<?php

/*
|--------------------------------------------------------------------------
| Helpers da suíte de drivers (tests/Drivers)
|--------------------------------------------------------------------------
|
| A suíte roda o monitor contra MySQL e PostgreSQL REAIS (docker-compose.drivers.yml)
| e só é executada com PSF_DRIVERS=1; sem isso todo teste é pulado com o motivo.
| Cada teste ganha um banco novo e descartável (nome único), apagado no afterEach
| mesmo quando o teste falha.
|
*/

use Psf\Database\Connect;
use Psf\Database\Dialect\DialectFactory;
use Psf\Database\Dialect\DialectInterface;
use Psf\Enumerators\DBDriver;
use Psf\Model\MetadataCache;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Monitor;

/** Motivo do skip quando a suíte está desligada. */
const DRIVERS_SKIP_REASON = 'Suíte de drivers desligada: suba os bancos (docker compose -f docker-compose.drivers.yml up -d --wait) '
    . 'e rode com PSF_DRIVERS=1 (composer test:drivers).';

/** A suíte só roda com PSF_DRIVERS=1. */
function driversEnabled(): bool
{
    return getenv('PSF_DRIVERS') === '1';
}

/** Variável de ambiente com padrão (os padrões batem com o docker-compose.drivers.yml). */
function driverEnv(string $name, string $default): string
{
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * Conexão do servidor (sem banco) para o driver.
 *
 * @return array{driver: DBDriver, host: string, port: int, username: string, password: string}
 */
function driverServer(string $driver): array
{
    return match ($driver) {
        'mysql' => [
            'driver'   => DBDriver::MySQL,
            'host'     => driverEnv('PSF_MYSQL_HOST', '127.0.0.1'),
            'port'     => (int) driverEnv('PSF_MYSQL_PORT', '33306'),
            'username' => driverEnv('PSF_MYSQL_USER', 'root'),
            'password' => driverEnv('PSF_MYSQL_PASSWORD', 'psf_test_root'),
        ],
        'pgsql' => [
            'driver'   => DBDriver::PostgreSQL,
            'host'     => driverEnv('PSF_PGSQL_HOST', '127.0.0.1'),
            'port'     => (int) driverEnv('PSF_PGSQL_PORT', '35432'),
            'username' => driverEnv('PSF_PGSQL_USER', 'postgres'),
            'password' => driverEnv('PSF_PGSQL_PASSWORD', 'psf_test_root'),
        ],
        default => throw new InvalidArgumentException("Driver de teste desconhecido: {$driver}"),
    };
}

/** PDO administrativo (cria/apaga bancos), um por driver. */
function driverAdminPdo(string $driver): PDO
{
    static $pdos = [];
    if (isset($pdos[$driver])) {
        return $pdos[$driver];
    }
    $s   = driverServer($driver);
    $dsn = $driver === 'mysql'
        ? "mysql:host={$s['host']};port={$s['port']};charset=utf8mb4"
        : "pgsql:host={$s['host']};port={$s['port']};dbname=postgres";
    $pdo = new PDO($dsn, $s['username'], $s['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    return $pdos[$driver] = $pdo;
}

/** Estado da suíte: bancos e arquivos a remover no afterEach. */
function &driverState(): array
{
    static $state = ['databases' => [], 'files' => []];
    return $state;
}

/** Cria um banco vazio de nome único no servidor do driver e o registra para limpeza. */
function driverCreateDatabase(string $driver): string
{
    $name = 'psf_t_' . bin2hex(random_bytes(6));
    $state = &driverState();
    $state['databases'][] = [$driver, $name];
    driverAdminPdo($driver)->exec('CREATE DATABASE ' . $name);
    return $name;
}

/**
 * Cria um banco descartável, grava o config do PSF para ele (db.default +
 * settings.monitor), inicializa o PSF e zera o estado do monitor e do Connect.
 *
 * @param array $monitor settings.monitor
 * @param array $top     mesclado ao config com array_replace_recursive
 * @return string nome do banco criado
 */
function driverUse(string $driver, array $monitor = [], array $top = []): string
{
    $name   = driverCreateDatabase($driver);
    $server = driverServer($driver);

    $config = [
        'db' => [
            'default' => [
                'driver'   => $server['driver'],
                'host'     => $server['host'],
                'port'     => $server['port'],
                'database' => $name,
                'username' => $server['username'],
                'password' => $server['password'],
            ],
        ],
        'jwt'      => ['secret' => 'psf-test-secret-key-do-not-use-in-production'],
        'settings' => ['monitor' => $monitor],
    ];
    $config = array_replace_recursive($config, $top);

    $file = sys_get_temp_dir() . '/psf-drivers-cfg-' . uniqid('', true) . '.php';
    $state = &driverState();
    $state['files'][] = $file;
    file_put_contents($file, '<?php use Psf\Enumerators\DBDriver; return ' . var_export($config, true) . ';');

    PSF::init(['config' => $file]);
    driverResetStatics();

    return $name;
}

/** Zera o estado estático do monitor e do Connect. */
function driverResetStatics(): void
{
    Config::reset();
    Monitor::reset();
    Connect::$connect = null;
    Connect::$tables  = null;
    Connect::clearColumnsCache();
    MetadataCache::clearCache();
}

/** Apaga os bancos e arquivos do teste e restaura o config padrão. Nunca lança. */
function driverCleanup(): void
{
    driverResetStatics();
    gc_collect_cycles();

    $state = &driverState();
    foreach ($state['databases'] as [$driver, $name]) {
        try {
            driverAdminPdo($driver)->exec($driver === 'pgsql'
                ? "DROP DATABASE IF EXISTS {$name} WITH (FORCE)"
                : "DROP DATABASE IF EXISTS {$name}");
        } catch (Throwable $e) {
            fwrite(STDERR, "[drivers] não foi possível apagar {$name}: {$e->getMessage()}\n");
        }
    }
    foreach ($state['files'] as $file) {
        @unlink($file);
    }
    $state = ['databases' => [], 'files' => []];

    PSF::init(['config' => dirname(__DIR__) . '/Fixtures/config.php']);
    Config::reset();
}

/** Conexão dedicada do monitor no banco do teste. */
function driverPdo(): PDO
{
    return Connection::open('default');
}

/** Dialeto do banco do teste. */
function driverDialect(): DialectInterface
{
    return DialectFactory::fromConfig(\PSF::config('db', 'default'));
}

/** Data UTC. */
function driverAt(string $ts, string $tz = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($ts, new DateTimeZone($tz));
}

/** Contagem de linhas de uma tabela. */
function driverCount(PDO $pdo, string $table, string $where = ''): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM {$table} {$where}")->fetchColumn();
}

/* ---------------------------------------------------------------------
 | Linhas completas das tabelas brutas (todas as colunas NOT NULL preenchidas)
 * ------------------------------------------------------------------- */

function drvRequestRow(array $over = []): array
{
    return $over + [
        'request_id' => 'req-' . bin2hex(random_bytes(4)), 'ts' => '2026-10-24 12:00:00', 'method' => 'GET',
        'route' => '/usuarios', 'version' => 1, 'status' => 200, 'duration_ms' => 12.5, 'memory_kb' => 2048,
        'user_id' => null, 'env' => 'dev', 'app_release' => null, 'dropped_events' => 0, 'sample_weight' => 1.0,
    ];
}

function drvQueryRow(array $over = []): array
{
    return $over + [
        'request_id' => 'req-x', 'ts' => '2026-10-24 12:00:00', 'sql_hash' => str_repeat('a', 40),
        'sql_text' => 'SELECT ?', 'duration_ms' => 1.5, 'connection_name' => 'default', 'sample_weight' => 1.0,
    ];
}

function drvExceptionRow(array $over = []): array
{
    return $over + [
        'request_id' => 'req-x', 'ts' => '2026-10-24 12:00:00', 'exception_class' => 'RuntimeException',
        'message' => 'falhou', 'file' => '/app/x.php', 'line_no' => 10, 'trace_hash' => str_repeat('b', 40),
        'trace' => '#0 {main}', 'sample_weight' => 1.0,
    ];
}

function drvExternalRow(array $over = []): array
{
    return $over + [
        'request_id' => 'req-x', 'ts' => '2026-10-24 12:00:00', 'kind' => 'http', 'target' => 'api.exemplo.com',
        'duration_ms' => 12.5, 'status' => 200, 'sample_weight' => 1.0,
    ];
}

/** INSERT de uma linha com SQL próprio do teste (não passa pelo DatabaseSink). */
function drvInsert(PDO $pdo, string $table, array $row): void
{
    $cols = array_keys($row);
    $pdo->prepare(
        'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')'
    )->execute(array_combine(array_map(fn ($c) => ':' . $c, $cols), array_values($row)));
}

/** Request bruto (atalho dos testes de rollup/reader). */
function drvRequest(PDO $pdo, string $ts, string $route = 'users/{id:int}', int $status = 200, float $ms = 10.0, float $weight = 1.0, string $method = 'GET', ?string $id = null): void
{
    drvInsert($pdo, 'psf_monitor_requests', drvRequestRow([
        'request_id' => $id ?? uniqid('r', true), 'ts' => $ts, 'method' => $method, 'route' => $route,
        'status' => $status, 'duration_ms' => $ms, 'sample_weight' => $weight, 'env' => 'test', 'memory_kb' => 0,
    ]));
}

/** Registro de rollup com valores conhecidos. */
function drvRollup(PDO $pdo, string $hour, string $metric, string $key, float $hits, float $sumMs = 0.0, float $maxMs = 0.0, float $errors = 0.0, ?array $histogram = null, ?string $sample = null, ?string $lastTs = null): void
{
    drvInsert($pdo, 'psf_monitor_rollups', [
        'bucket_hour' => $hour, 'metric' => $metric, 'metric_key' => $key, 'hits' => $hits, 'errors' => $errors,
        'sum_ms' => $sumMs, 'max_ms' => $maxMs,
        'histogram' => \Psf\Monitor\Histogram::encode($histogram ?? \Psf\Monitor\Histogram::empty()),
        'sample_text' => $sample, 'last_ts' => $lastTs,
    ]);
}

/** @return array<int, array<string, mixed>> */
function drvRollupRows(PDO $pdo, ?string $metric = null): array
{
    $sql = 'SELECT bucket_hour, metric, metric_key, hits, errors, sum_ms, max_ms, histogram, sample_text, last_ts '
        . 'FROM psf_monitor_rollups ' . ($metric !== null ? "WHERE metric = '{$metric}' " : '')
        . 'ORDER BY bucket_hour, metric, metric_key';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function drvWatermark(PDO $pdo): string|false
{
    return $pdo->query("SELECT value FROM psf_monitor_state WHERE name = 'rollup_watermark'")->fetchColumn();
}

/* ---------------------------------------------------------------------
 | Introspecção do esquema por driver
 * ------------------------------------------------------------------- */

/**
 * Colunas de uma tabela: nome => [type, nullable, length, precision, scale, identity].
 *
 * @return array<string, array{type: string, nullable: bool, length: ?int, precision: ?int, scale: ?int, identity: bool}>
 */
function driverColumns(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        $sql = 'SELECT column_name AS c, data_type AS t, is_nullable AS n, character_maximum_length AS l,'
            . ' numeric_precision AS p, numeric_scale AS s, extra AS x'
            . ' FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?';
    } else {
        $sql = "SELECT column_name AS c, data_type AS t, is_nullable AS n, character_maximum_length AS l,"
            . " numeric_precision AS p, numeric_scale AS s,"
            . " CASE WHEN is_identity = 'YES' OR column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS x"
            . " FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $out[(string) $r['c']] = [
            'type'      => strtolower((string) $r['t']),
            'nullable'  => $r['n'] === 'YES',
            'length'    => $r['l'] === null ? null : (int) $r['l'],
            'precision' => $r['p'] === null ? null : (int) $r['p'],
            'scale'     => $r['s'] === null ? null : (int) $r['s'],
            'identity'  => str_contains(strtolower((string) $r['x']), 'auto_increment'),
        ];
    }
    return $out;
}

/**
 * Índices de uma tabela: nome => colunas em ordem; a chave primária vem como `PRIMARY`.
 *
 * @return array<string, array<int, string>>
 */
function driverIndexes(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare(
            'SELECT index_name AS i, column_name AS c FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index'
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT CASE WHEN ix.indisprimary THEN 'PRIMARY' ELSE i.relname END AS i, a.attname AS c"
            . ' FROM pg_class t'
            . ' JOIN pg_index ix ON t.oid = ix.indrelid'
            . ' JOIN pg_class i ON i.oid = ix.indexrelid'
            . ' CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum'
            . " WHERE t.relname = ? AND t.relnamespace = 'public'::regnamespace ORDER BY i.relname, k.ord"
        );
    }
    $stmt->execute([$table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $out[(string) $r['i']][] = (string) $r['c'];
    }
    return $out;
}

/** Tabelas do banco do teste (minúsculas). */
function driverTables(PDO $pdo): array
{
    return array_map('strtolower', $pdo->query(driverDialect()->listTablesQuery())->fetchAll(PDO::FETCH_COLUMN));
}
