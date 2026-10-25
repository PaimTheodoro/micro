<?php

namespace Psf\Monitor;

/**
 * Circuit breaker do monitor.
 *
 * Protege a aplicação de um banco de monitoramento lento ou fora do ar:
 * após N falhas consecutivas o breaker abre e fica aberto durante o cooldown.
 * Usa APCu quando disponível e ativo no SAPI atual; senão, um arquivo
 * `psf-monitor-<md5(key)>.json` com `{failures, open_until}`.
 * Nunca lança: qualquer erro de I/O é tratado como "fechado".
 */
final class CircuitBreaker
{
    private \Closure $clock;
    private string $dir;
    private string $id;

    /**
     * @param string        $key             identifica o recurso protegido (ex.: `db:<database>`)
     * @param int           $failures        falhas seguidas até abrir
     * @param int           $cooldownSeconds tempo aberto após abrir
     * @param \Closure|null $clock           relógio em segundos (seam de teste; padrão time())
     * @param string|null   $dir             diretório do arquivo-flag (padrão sys_get_temp_dir())
     */
    public function __construct(
        private string $key,
        private int $failures,
        private int $cooldownSeconds,
        ?\Closure $clock = null,
        ?string $dir = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->dir = rtrim($dir ?? sys_get_temp_dir(), '/\\');
        $this->id = 'psf-monitor-' . md5($key);
    }

    /** Indica se o breaker está aberto agora. */
    public function isOpen(): bool
    {
        return $this->openUntil($this->load()) > $this->now();
    }

    /** Registra uma falha; retorna true somente na chamada que abre o breaker. */
    public function recordFailure(): bool
    {
        $state = $this->load();
        if ($this->openUntil($state) > $this->now()) {
            return false;
        }
        $count = (int) ($state['failures'] ?? 0) + 1;
        if ($count >= $this->failures) {
            $this->save(['failures' => 0, 'open_until' => $this->now() + $this->cooldownSeconds]);
            return true;
        }
        $this->save(['failures' => $count, 'open_until' => 0]);
        return false;
    }

    /**
     * Registra sucesso: zera o contador de falhas e fecha o breaker.
     * Só grava se houver algo a zerar (evita I/O em todo request gravado).
     */
    public function recordSuccess(): void
    {
        $state = $this->load();
        if ((int) ($state['failures'] ?? 0) === 0 && $this->openUntil($state) === 0) {
            return;
        }
        $this->save(['failures' => 0, 'open_until' => 0]);
    }

    /** Abre imediatamente; retorna true se estava fechado. */
    public function trip(): bool
    {
        $wasClosed = !$this->isOpen();
        $this->save(['failures' => 0, 'open_until' => $this->now() + $this->cooldownSeconds]);
        return $wasClosed;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function openUntil(array $state): int
    {
        return (int) ($state['open_until'] ?? 0);
    }

    /** APCu disponível e ativo no SAPI atual. */
    private function useApcu(): bool
    {
        if (!function_exists('apcu_fetch') || !filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        return PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN);
    }

    private function path(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $this->id . '.json';
    }

    private function load(): array
    {
        try {
            if ($this->useApcu()) {
                $v = apcu_fetch($this->id, $ok);
                return $ok && is_array($v) ? $v : [];
            }
            $raw = @file_get_contents($this->path());
            $v = $raw === false ? null : json_decode($raw, true);
            return is_array($v) ? $v : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function save(array $state): void
    {
        try {
            if ($this->useApcu()) {
                apcu_store($this->id, $state);
                return;
            }
            @file_put_contents($this->path(), json_encode($state), LOCK_EX);
        } catch (\Throwable) {
            // Breaker nunca lança.
        }
    }
}
