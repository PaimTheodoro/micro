<?php

namespace Psf\Monitor\Notification\Channels;

use Psf\Monitor\Notification\{Notification, NotifierInterface};
use Psf\Utils\Email;

/**
 * E-mail via `Psf\Utils\Email` (PHPMailer). Opções: `to` (lista de e-mails ou
 * pares `[nome, e-mail]`), `from` (`[nome, e-mail]`), `subjectPrefix` e `smtp`
 * (servidor; omitido = `config['smtp']`). Um envio por destinatário; se algum
 * falhar, os demais são tentados e a exceção final lista quantos falharam.
 */
final class EmailNotifier implements NotifierInterface
{
    /** @var \Closure(array, array, string, array): string|bool */
    private \Closure $sender;

    /** @param array<string, mixed> $options */
    public function __construct(private array $options = [], ?callable $sender = null)
    {
        $this->sender = $sender !== null
            ? \Closure::fromCallable($sender)
            : function (array $from, array $to, string $subject, array $content): string|bool {
                $server = $this->options['smtp'] ?? null;

                return (new Email(is_array($server) ? $server : null))->send($from, $to, $subject, $content);
            };
    }

    public function notify(Notification $notification): void
    {
        $recipients = (array) ($this->options['to'] ?? []);
        if ($recipients === []) {
            throw new \InvalidArgumentException('EmailNotifier: opção to ausente');
        }

        $from    = (array) ($this->options['from'] ?? ['PSF Monitor', 'monitor@localhost']);
        $subject = (string) ($this->options['subjectPrefix'] ?? '') . $notification->title();
        $content = [
            'html' => '<pre style="font-family:monospace">' . htmlspecialchars($notification->text()) . '</pre>',
            'alt'  => $notification->text(),
        ];

        $failed = 0;
        foreach ($recipients as $recipient) {
            $to = is_array($recipient) ? array_values($recipient) : [(string) $recipient, (string) $recipient];
            if (($this->sender)($from, $to, $subject, $content) !== true) {
                $failed++;
            }
        }

        if ($failed > 0) {
            throw new \RuntimeException("EmailNotifier: {$failed} de " . count($recipients) . ' envio(s) falharam');
        }
    }
}
