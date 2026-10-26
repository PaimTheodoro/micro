<?php

namespace Psf\Monitor\Notification;

/**
 * Fatos de um request que disparou um aviso. Imutável; o conteúdo já passou
 * pela mesma limpeza do monitor (mensagens de PDOException e fatais sem
 * valores, nunca URL completa, query string ou corpo do request).
 */
final class Notification
{
    /** Motivos possíveis em `$reasons`. */
    public const REASONS = ['status', 'exception', 'fatal', 'slowRequest', 'slowQuery', 'externalError', 'slowExternal'];

    /**
     * @param list<string> $reasons        motivos (ver REASONS) pelos quais o request é notável
     * @param list<array{class: string, message: string, file: string, line: int, traceHash: string, fatal: bool}> $exceptions
     * @param list<array{kind: string, target: string, status: ?int, durationMs: float}> $externalErrors
     * @param string       $fingerprint    agrupa ocorrências iguais (traceHash da primeira exceção, ou rota + status)
     * @param int          $suppressed     avisos iguais engolidos pelo throttle desde o último enviado
     */
    public function __construct(
        public readonly array $reasons,
        public readonly string $requestId,
        public readonly string $route,
        public readonly string $method,
        public readonly int $status,
        public readonly float $durationMs,
        public readonly string $env,
        public readonly ?string $release,
        public readonly ?string $userId,
        public readonly array $exceptions,
        public readonly array $externalErrors,
        public readonly string $fingerprint,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly int $suppressed = 0,
    ) {
    }

    /** Cópia com o contador de avisos suprimidos (preenchido pelo Dispatcher). */
    public function withSuppressed(int $suppressed): self
    {
        return new self(
            $this->reasons, $this->requestId, $this->route, $this->method, $this->status,
            $this->durationMs, $this->env, $this->release, $this->userId, $this->exceptions,
            $this->externalErrors, $this->fingerprint, $this->occurredAt, $suppressed
        );
    }

    /** Título curto, ex.: `[prd] 500 GET /v1/usuarios/{id:int} — PDOException`. */
    public function title(): string
    {
        $head = '[' . $this->env . '] ' . $this->status . ' ' . $this->method . ' ' . $this->route;
        $first = $this->exceptions[0]['class'] ?? null;

        return $first !== null ? $head . ' — ' . $first : $head;
    }

    /** Texto multilinha pronto para chat/e-mail (sem HTML). */
    public function text(): string
    {
        $lines = [$this->title()];

        $first = $this->exceptions[0] ?? null;
        if ($first !== null) {
            if ($first['message'] !== '') {
                $lines[] = $first['message'];
            }
            $lines[] = $first['file'] . ':' . $first['line'];
        }

        $lines[] = 'Request: ' . $this->requestId . ' · ' . round($this->durationMs) . ' ms'
            . ($this->userId !== null ? ' · usuário ' . $this->userId : '')
            . ($this->release !== null ? ' · release ' . $this->release : '');
        $lines[] = 'Motivos: ' . implode(', ', $this->reasons);

        foreach ($this->externalErrors as $call) {
            $lines[] = 'Externa: ' . $call['kind'] . ':' . $call['target'] . ' → ' . ($call['status'] ?? 'falha');
        }

        if (count($this->exceptions) > 1) {
            $lines[] = '+' . (count($this->exceptions) - 1) . ' exceção(ões) no mesmo request';
        }
        if ($this->suppressed > 0) {
            $lines[] = $this->suppressed . ' aviso(s) igual(is) suprimido(s) desde o último';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> payload JSON/array (chaves camelCase) */
    public function toArray(): array
    {
        return [
            'title'          => $this->title(),
            'reasons'        => $this->reasons,
            'requestId'      => $this->requestId,
            'route'          => $this->route,
            'method'         => $this->method,
            'status'         => $this->status,
            'durationMs'     => round($this->durationMs, 2),
            'env'            => $this->env,
            'release'        => $this->release,
            'userId'         => $this->userId,
            'exceptions'     => $this->exceptions,
            'externalErrors' => $this->externalErrors,
            'fingerprint'    => $this->fingerprint,
            'occurredAt'     => $this->occurredAt->format(\DATE_ATOM),
            'suppressed'     => $this->suppressed,
        ];
    }
}
