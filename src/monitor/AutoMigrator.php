<?php

namespace Psf\Monitor;

/**
 * Instala as tabelas do monitor em runtime quando `auto_migrate` está ligado.
 *
 * Usa o mesmo `Installer` do `monitor:install`. Recusa em `prd`, serializa
 * a migração com `flock` não bloqueante num arquivo derivado da chave do banco
 * e, com o lock em mãos, confere de novo se as tabelas ainda faltam.
 * Nunca lança: qualquer erro vira `UNAVAILABLE`, com a causa em `lastError()`.
 */
final class AutoMigrator
{
    /** Tabelas instaladas agora (ou outro request já tinha instalado). */
    public const MIGRATED = 'migrated';

    /** Outro request está migrando: o lote é descartado, sem contar falha. */
    public const LOCKED = 'locked';

    /** Não foi possível migrar (Phinx ausente, sem DDL, I/O...). */
    public const UNAVAILABLE = 'unavailable';

    /** Ambiente `prd`: auto_migrate é ignorado. */
    public const REFUSED = 'refused';

    private string $lockDir;
    private ?string $lastError = null;

    /** Implementação do flock (seam de teste): fn($handle, int $op, &$wouldBlock): bool. */
    private \Closure $flock;

    /**
     * @param Installer     $installer   instalador do banco do monitor
     * @param string        $environment `config['environment']` (exatamente `prd` recusa)
     * @param string|null   $lockDir     diretório do arquivo de lock (padrão sys_get_temp_dir())
     * @param \Closure|null $flock       implementação do flock (seam de teste)
     */
    public function __construct(
        private Installer $installer,
        private string $environment,
        ?string $lockDir = null,
        ?\Closure $flock = null
    ) {
        $this->lockDir = rtrim($lockDir ?? sys_get_temp_dir(), '/\\');
        $this->flock   = $flock ?? static function ($handle, int $operation, &$wouldBlock): bool {
            return flock($handle, $operation, $wouldBlock);
        };
    }

    /** Tenta instalar o esquema; devolve uma das constantes da classe. */
    public function run(): string
    {
        $this->lastError = null;

        try {
            if ($this->environment === 'prd') {
                return self::REFUSED;
            }

            if (!class_exists(\Phinx\Migration\Manager::class)) {
                $this->lastError = 'auto_migrate requer robmorgan/phinx';
                return self::UNAVAILABLE;
            }

            $path   = $this->lockPath();
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                $this->lastError = "não foi possível abrir o lock {$path}";
                return self::UNAVAILABLE;
            }

            try {
                $wouldBlock = false;
                if (!($this->flock)($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                    if ($wouldBlock) {
                        // Contenção real: outro request está migrando.
                        return self::LOCKED;
                    }
                    // Falha do flock em si (FS sem suporte etc.): não descarta em silêncio.
                    $this->lastError = "flock falhou no lock {$path}";
                    return self::UNAVAILABLE;
                }
                try {
                    // Outro request pode ter migrado enquanto esperávamos.
                    if (!$this->installer->isInstalled()) {
                        $this->installer->install();
                    }
                    return self::MIGRATED;
                } finally {
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
        } catch (\Throwable $e) {
            $this->lastError = (string) preg_replace('/\s+/', ' ', get_class($e) . ': ' . $e->getMessage());
            return self::UNAVAILABLE;
        }
    }

    /** Causa do último `UNAVAILABLE` (null nos demais resultados). */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** Arquivo de lock: `<lockDir>/psf-monitor-<md5(database)>.lock`. */
    private function lockPath(): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . 'psf-monitor-' . md5($this->installer->database()) . '.lock';
    }
}
