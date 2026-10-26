<?php

namespace Psf\Monitor\Notification;

/**
 * Throttle padrão: APCu quando ativo no SAPI atual (vale por servidor/pool de
 * FPM); senão um arquivo em `sys_get_temp_dir()` com `flock`. Nunca lança.
 */
final class Throttle implements ThrottleInterface
{
    private \Closure $clock;
    private string $dir;

    public function __construct(?\Closure $clock = null, ?string $dir = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->dir   = rtrim($dir ?? sys_get_temp_dir(), '/\\');
    }

    public function acquire(string $key, int $seconds): ?int
    {
        if ($seconds <= 0) {
            return 0;
        }

        try {
            $id = 'psf-notify-' . sha1($key);

            return $this->apcuEnabled() ? $this->viaApcu($id, $seconds) : $this->viaFile($id, $seconds);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function apcuEnabled(): bool
    {
        return function_exists('apcu_add') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN));
    }

    private function viaApcu(string $id, int $seconds): ?int
    {
        // apcu_add é atômico: só um request abre a janela
        if (apcu_add($id, 1, $seconds)) {
            $suppressed = (int) apcu_fetch($id . ':n');
            apcu_delete($id . ':n');

            return $suppressed;
        }

        // O contador vive um pouco mais que a janela para ser lido no próximo envio
        apcu_inc($id . ':n', 1, $success, $seconds + 3600);

        return null;
    }

    private function viaFile(string $id, int $seconds): ?int
    {
        $handle = @fopen($this->dir . DIRECTORY_SEPARATOR . $id . '.json', 'c+');
        if ($handle === false) {
            return 0;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return 0;
            }

            $now   = ($this->clock)();
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];

            if ((int) ($state['until'] ?? 0) > $now) {
                $state['suppressed'] = (int) ($state['suppressed'] ?? 0) + 1;
                $this->write($handle, $state);

                return null;
            }

            $suppressed = (int) ($state['suppressed'] ?? 0);
            $this->write($handle, ['until' => $now + $seconds, 'suppressed' => 0]);

            return $suppressed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function write($handle, array $state): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state));
    }
}
