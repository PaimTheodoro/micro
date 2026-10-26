<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\{Notification, NotifierInterface};

/**
 * Base dos canais que fazem POST JSON numa URL. Opções:
 * `webhookUrl` (obrigatória), `timeoutSeconds` (padrão 3), `headers` (mapa).
 * Falha de transporte ou status fora de 2xx lança exceção SEM a URL na
 * mensagem (a URL costuma ser um segredo).
 */
abstract class WebhookChannel implements NotifierInterface
{
    /** @var \Closure(string, string, array<int, string>, int): ?int */
    private \Closure $transport;

    /** @param array<string, mixed> $options */
    public function __construct(protected array $options = [], ?callable $transport = null)
    {
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : Transport::post(...);
    }

    /** @return array<string, mixed> corpo JSON do canal */
    abstract protected function payload(Notification $notification): array;

    public function notify(Notification $notification): void
    {
        $url = $this->options['webhookUrl'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new \InvalidArgumentException(static::class . ': opção webhookUrl ausente');
        }

        $body = json_encode($this->payload($notification), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new \RuntimeException(static::class . ': payload inválido');
        }

        $headers = ['Content-Type: application/json'];
        foreach ((array) ($this->options['headers'] ?? []) as $name => $value) {
            $headers[] = str_replace(["\r", "\n"], '', $name . ': ' . $value);
        }
        $headers = $this->extraHeaders($body, $headers);

        $status = ($this->transport)($url, $body, $headers, (int) ($this->options['timeoutSeconds'] ?? 3));

        if ($status === null || $status < 200 || $status >= 300) {
            throw new \RuntimeException(static::class . ': envio falhou (' . ($status ?? 'sem resposta') . ')');
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    protected function extraHeaders(string $body, array $headers): array
    {
        return $headers;
    }
}
