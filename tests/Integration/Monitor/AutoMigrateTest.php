<?php

use Psf\Monitor\AutoMigrator;
use Psf\Monitor\CircuitBreaker;
use Psf\Monitor\Installer;
use Psf\Monitor\Monitor;
use Psf\Monitor\Sinks\SinkInterface;

/** Tabelas existentes no arquivo SQLite do teste. */
function autoTables(string $sqlite): array
{
    $pdo = new PDO('sqlite:' . $sqlite);
    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
}

function autoCount(string $sqlite, string $table): int
{
    $pdo = new PDO('sqlite:' . $sqlite);
    return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

/** Estado gravado pelo breaker de teste (fallback em arquivo, APCu desligado no CLI). */
function autoBreakerState(): array
{
    $raw = @file_get_contents(test()->dir . '/psf-monitor-' . md5('test') . '.json');
    $v   = $raw === false ? null : json_decode($raw, true);
    return is_array($v) ? $v : [];
}

/** Linhas do error_log redirecionado. */
function autoLogLines(): array
{
    $raw = @file_get_contents(test()->log);
    return $raw === false ? [] : array_values(array_filter(explode("\n", $raw), fn ($l) => str_contains($l, '[PSF][monitor]')));
}

/** Liga o monitor com auto_migrate e injeta breaker e migrator de teste; faz o boot. */
function autoStart(?Installer $installer = null, string $env = 'dev', ?SinkInterface $sink = null): void
{
    Monitor::reset();
    test()->breaker = new CircuitBreaker('test', 3, 300, null, test()->dir);
    Monitor::useBreaker(test()->breaker);
    Monitor::useSink($sink);
    Monitor::useAutoMigrator(new AutoMigrator($installer ?? new Installer(), $env, test()->dir));
    Monitor::boot('GET');
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/psf-monitor-auto-' . uniqid('', true);
    mkdir($this->dir);
    $this->log = $this->dir . '/error.log';
    $this->previousLog = ini_set('error_log', $this->log);
    $this->server = $_SERVER;
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
});

afterEach(function () {
    ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
    $_SERVER = $this->server;
    foreach (glob($this->dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('does nothing when auto_migrate is off', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0]]);
    autoStart();

    Monitor::flush(200);

    expect(autoTables($sqlite))->not->toContain('psf_monitor_requests');
    expect(autoBreakerState()['failures'] ?? 0)->toBe(1);
});

it('creates the tables and writes the batch', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    autoStart();

    Monitor::flush(200);

    expect(autoTables($sqlite))->toContain('psf_monitor_requests', 'psf_monitor_state', 'psf_monitor_rollups');
    expect(autoCount($sqlite, 'psf_monitor_requests'))->toBe(1);
    expect($this->breaker->isOpen())->toBeFalse();
    expect(autoLogLines())->toBe([]);
});

it('refuses to migrate in prd', function () {
    $sqlite = monitorUseConfig(
        ['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true],
        ['environment' => 'prd']
    );
    autoStart(null, 'prd');

    expect((new AutoMigrator(new Installer(), 'prd', $this->dir))->run())->toBe(AutoMigrator::REFUSED);

    Monitor::flush(200);

    expect(autoTables($sqlite))->not->toContain('psf_monitor_requests');
    expect($this->breaker->isOpen())->toBeTrue();
    $lines = autoLogLines();
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('prd')->toContain('monitor:install');
});

it('takes the environment from the PSF config by default', function () {
    $sqlite = monitorUseConfig(
        ['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true],
        ['environment' => 'prd']
    );
    Monitor::reset();
    $this->breaker = new CircuitBreaker('test', 3, 300, null, $this->dir);
    Monitor::useBreaker($this->breaker);
    Monitor::boot('GET');

    Monitor::flush(200);

    expect(autoTables($sqlite))->not->toContain('psf_monitor_requests');
    expect($this->breaker->isOpen())->toBeTrue();
    expect(autoLogLines())->toHaveCount(1);
});

it('discards the batch without failing when the lock is taken', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);

    $lock = fopen($this->dir . '/psf-monitor-' . md5('default') . '.lock', 'c');
    expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect((new AutoMigrator(new Installer(), 'dev', $this->dir))->run())->toBe(AutoMigrator::LOCKED);

        autoStart();
        Monitor::flush(200);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    expect(autoTables($sqlite))->not->toContain('psf_monitor_requests');
    expect($this->breaker->isOpen())->toBeFalse();
    expect(autoBreakerState()['failures'] ?? 0)->toBe(0);
    expect(autoLogLines())->toBe([]);
});

it('is idempotent on later requests', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);

    autoStart();
    Monitor::flush(200);
    autoStart();
    Monitor::flush(200);

    expect(autoCount($sqlite, Installer::MIGRATION_TABLE))->toBe(6);
    expect(autoCount($sqlite, 'psf_monitor_requests'))->toBe(2);
});

it('returns migrated without migrating when another request already did', function () {
    monitorUseConfig(['enabled' => true, 'auto_migrate' => true]);
    $installer = new class () extends Installer {
        public int $installs = 0;

        public function install(): void
        {
            $this->installs++;
        }

        public function isInstalled(): bool
        {
            return true;
        }
    };

    expect((new AutoMigrator($installer, 'dev', $this->dir))->run())->toBe(AutoMigrator::MIGRATED);
    expect($installer->installs)->toBe(0);
});

it('opens the breaker when migrating is not possible', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    $installer = new class () extends Installer {
        public function install(): void
        {
            throw new RuntimeException('sem permissão de DDL');
        }
    };

    $migrator = new AutoMigrator($installer, 'dev', $this->dir);
    expect($migrator->run())->toBe(AutoMigrator::UNAVAILABLE);
    expect($migrator->lastError())->toContain('sem permissão de DDL');

    autoStart($installer);
    Monitor::flush(200);
    autoStart($installer);
    Monitor::flush(200);

    expect(autoTables($sqlite))->not->toContain('psf_monitor_requests');
    expect((new CircuitBreaker('test', 3, 300, null, $this->dir))->isOpen())->toBeTrue();
    $lines = autoLogLines();
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('sem permissão de DDL');
});

it('does not migrate when the write fails with the schema present', function () {
    monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    $installer = new class () extends Installer {
        public int $installs = 0;

        public function install(): void
        {
            $this->installs++;
        }
    };
    $sink = new class () implements SinkInterface {
        public function write(array $batch): void
        {
            throw new RuntimeException('timeout');
        }

        public function schemaInstalled(): bool
        {
            return true;
        }
    };

    autoStart($installer, 'dev', $sink);
    Monitor::flush(200);

    expect($installer->installs)->toBe(0);
    expect(autoBreakerState()['failures'] ?? 0)->toBe(1);
});

it('does not migrate when the schema check itself fails', function () {
    monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    $installer = new class () extends Installer {
        public int $installs = 0;

        public function install(): void
        {
            $this->installs++;
        }
    };
    $sink = new class () implements SinkInterface {
        public function write(array $batch): void
        {
            throw new RuntimeException('connection refused');
        }

        public function schemaInstalled(): bool
        {
            throw new RuntimeException('connection refused');
        }
    };

    autoStart($installer, 'dev', $sink);
    Monitor::flush(200);

    expect($installer->installs)->toBe(0);
    expect(autoBreakerState()['failures'] ?? 0)->toBe(1);
});

it('reports unavailable when the lock file cannot be opened', function () {
    monitorUseConfig(['enabled' => true, 'auto_migrate' => true]);

    $migrator = new AutoMigrator(new Installer(), 'dev', $this->dir . '/nao-existe');
    expect($migrator->run())->toBe(AutoMigrator::UNAVAILABLE)
        ->and($migrator->lastError())->toContain('lock');
});

it('treats a flock failure that is not contention as unavailable, tripping the breaker', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    $failingFlock = function ($handle, int $operation, &$wouldBlock): bool {
        $wouldBlock = false;
        return false;
    };

    $migrator = new AutoMigrator(new Installer(), 'dev', $this->dir, $failingFlock);
    expect($migrator->run())->toBe(AutoMigrator::UNAVAILABLE)
        ->and($migrator->lastError())->toContain('flock');

    Monitor::reset();
    $this->breaker = new CircuitBreaker('test', 3, 300, null, $this->dir);
    Monitor::useBreaker($this->breaker);
    Monitor::useAutoMigrator(new AutoMigrator(new Installer(), 'dev', $this->dir, $failingFlock));
    Monitor::boot('GET');
    Monitor::flush(200);

    expect(autoTables($sqlite))->not->toContain('psf_monitor_requests');
    expect($this->breaker->isOpen())->toBeTrue();
    $lines = autoLogLines();
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('flock');
});

it('rewrites the full batch (requests, queries and exceptions) after migrating', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    autoStart();
    Monitor::useFatalProvider(fn () => null);

    Monitor::queryEnd(Monitor::queryStart(), 'SELECT * FROM t WHERE id = 5');
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 2');
    Monitor::exception(new RuntimeException('boom'));
    Monitor::flush(200);

    expect(autoCount($sqlite, 'psf_monitor_requests'))->toBe(1)
        ->and(autoCount($sqlite, 'psf_monitor_queries'))->toBe(2)
        ->and(autoCount($sqlite, 'psf_monitor_exceptions'))->toBe(1);
    expect($this->breaker->isOpen())->toBeFalse();
    expect(autoLogLines())->toBe([]);
});

/** Instala o esquema completo e remove as tabelas das fases 2 e 4, simulando um projeto na fase 1. */
function autoPhase1Schema(string $sqlite): void
{
    (new Installer())->install();
    $pdo = new PDO('sqlite:' . $sqlite);
    $pdo->exec('DROP TABLE psf_monitor_queries');
    $pdo->exec('DROP TABLE psf_monitor_exceptions');
    $pdo->exec('DROP TABLE psf_monitor_external');
    // Projeto real da fase 1 só tem as 3 primeiras migrations registradas.
    $pdo->exec('DELETE FROM ' . Installer::MIGRATION_TABLE . ' WHERE version >= 20261025000001');
}

/** Registra 1 query e 1 exceção no request corrente. */
function autoRecordEvents(): void
{
    Monitor::queryEnd(Monitor::queryStart(), 'SELECT 1', 'default');
    Monitor::exception(new RuntimeException('x'));
}

it('fails safely on a phase-1 schema without auto_migrate', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0]]);
    autoPhase1Schema($sqlite);

    for ($i = 0; $i < 3; $i++) {
        Monitor::reset();
        $this->breaker = $this->breaker ?? new CircuitBreaker('test', 3, 300, null, $this->dir);
        Monitor::useBreaker($this->breaker);
        Monitor::useFatalProvider(fn () => null);
        Monitor::boot('GET');
        autoRecordEvents();
        Monitor::flush(200, 0.0);
    }

    expect($this->breaker->isOpen())->toBeTrue();
    $lines = autoLogLines();
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('monitor:install');
    expect(autoCount($sqlite, 'psf_monitor_requests'))->toBe(0);
});

it('installs the new tables with auto_migrate in dev and rewrites the batch', function () {
    $sqlite = monitorUseConfig(['enabled' => true, 'sample' => ['normal' => 1.0], 'auto_migrate' => true]);
    autoPhase1Schema($sqlite);

    autoStart();
    Monitor::useFatalProvider(fn () => null);
    autoRecordEvents();
    Monitor::flush(200, 0.0);

    expect(autoTables($sqlite))->toContain(
        'psf_monitor_requests',
        'psf_monitor_state',
        'psf_monitor_rollups',
        'psf_monitor_queries',
        'psf_monitor_exceptions',
        'psf_monitor_external'
    );
    expect(autoCount($sqlite, 'psf_monitor_requests'))->toBe(1)
        ->and(autoCount($sqlite, 'psf_monitor_queries'))->toBe(1)
        ->and(autoCount($sqlite, 'psf_monitor_exceptions'))->toBe(1);
    expect($this->breaker->isOpen())->toBeFalse();
});
