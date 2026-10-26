<?php

namespace Psf\Monitor\Notification;

use Psf\Helper\ConfigKeys;
use Psf\Monitor\Config;
use Psf\Monitor\Notification\Channels\{DiscordNotifier, EmailNotifier, LogNotifier, SlackNotifier, TeamsNotifier, WebhookNotifier};

/**
 * Motor de avisos do monitor. Lê `settings.monitor.notifiers`, decide quais
 * entradas casam com o request (`Rules`), aplica o throttle e entrega ao
 * notificador. Nunca lança: falha de um notificador vai ao `error_log` (só a
 * classe do erro e o nome da entrada, nunca URL nem mensagem) e não impede os
 * demais.
 *
 * Cada entrada (chave = nome, usado no throttle e no log):
 *   'channel' => 'log'|'webhook'|'slack'|'teams'|'discord'|'email'
 *     ou 'class' => App\Alertas\Meu::class (NotifierInterface; recebe as opções no construtor)
 *     ou 'callback' => fn (Notification $n) => ...
 *   'when' => [...]            (ver Rules; padrão: status >= 500 e fatal)
 *   'throttleSeconds' => 300   (0 desliga; um aviso por fingerprint por janela)
 *   'enabled' => true
 *   + as opções do canal (webhookUrl, to, from, ...), todas no mesmo nível
 */
final class Dispatcher
{
    private const CHANNELS = [
        'log'     => LogNotifier::class,
        'webhook' => WebhookNotifier::class,
        'slack'   => SlackNotifier::class,
        'teams'   => TeamsNotifier::class,
        'discord' => DiscordNotifier::class,
        'email'   => EmailNotifier::class,
    ];

    public const DEFAULT_THROTTLE_SECONDS = 300;

    private static ?ThrottleInterface $throttle = null;

    /** @var array<string, NotifierInterface> */
    private static array $instances = [];

    /** Seam/extensão: substitui o throttle (null volta ao padrão). */
    public static function useThrottle(?ThrottleInterface $throttle): void
    {
        self::$throttle = $throttle;
    }

    public static function reset(): void
    {
        self::$throttle  = null;
        self::$instances = [];
    }

    /**
     * Entradas ativas e normalizadas (chaves camelCase). Vazio quando não há
     * `notifiers` configurado: é a checagem barata feita em todo request.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entries(): array
    {
        $raw = Config::get('notifiers');
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $entries = [];
        foreach ($raw as $name => $entry) {
            if (!is_array($entry) || (array_key_exists('enabled', $entry) && !$entry['enabled'])) {
                continue;
            }
            $entries[(string) $name] = ConfigKeys::camelize($entry, ['headers', 'when', 'options']);
        }

        return $entries;
    }

    /**
     * Entradas cujas regras casam com os fatos do request.
     *
     * @param array<string, array<string, mixed>> $entries
     * @param array<string, mixed>                $facts
     * @return array<string, array<string, mixed>>
     */
    public static function matching(array $entries, array $facts): array
    {
        $matched = [];
        foreach ($entries as $name => $entry) {
            try {
                if (Rules::matches((array) ($entry['when'] ?? []), $facts)) {
                    $matched[$name] = $entry;
                }
            } catch (\Throwable) {
                // Regra malformada: a entrada simplesmente não dispara.
            }
        }

        return $matched;
    }

    /** @param array<string, array<string, mixed>> $entries entradas já filtradas por matching() */
    public static function send(array $entries, Notification $notification): void
    {
        foreach ($entries as $name => $entry) {
            try {
                $seconds    = isset($entry['throttleSeconds']) && is_numeric($entry['throttleSeconds'])
                    ? (int) $entry['throttleSeconds']
                    : self::DEFAULT_THROTTLE_SECONDS;
                $suppressed = self::throttle()->acquire($name . '|' . $notification->fingerprint, $seconds);
                if ($suppressed === null) {
                    continue;
                }

                self::notifier($name, $entry)->notify($notification->withSuppressed($suppressed));
            } catch (\Throwable $e) {
                error_log("[PSF][monitor] notificador '{$name}' falhou: " . get_class($e));
            }
        }
    }

    private static function throttle(): ThrottleInterface
    {
        return self::$throttle ??= new Throttle();
    }

    /** @param array<string, mixed> $entry */
    private static function notifier(string $name, array $entry): NotifierInterface
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $options = array_diff_key($entry, array_flip(['channel', 'class', 'callback', 'when', 'throttleSeconds', 'enabled']));
        if (isset($entry['options']) && is_array($entry['options'])) {
            $options = $entry['options'] + $options;
        }

        if (isset($entry['callback']) && is_callable($entry['callback'])) {
            $notifier = new class (\Closure::fromCallable($entry['callback'])) implements NotifierInterface {
                public function __construct(private \Closure $callback)
                {
                }

                public function notify(Notification $notification): void
                {
                    ($this->callback)($notification);
                }
            };
        } else {
            $class = isset($entry['class']) && is_string($entry['class'])
                ? $entry['class']
                : (self::CHANNELS[(string) ($entry['channel'] ?? '')] ?? null);

            if ($class === null || !is_subclass_of($class, NotifierInterface::class)) {
                throw new \InvalidArgumentException('entrada sem channel/class/callback válido');
            }

            $notifier = new $class($options);
        }

        return self::$instances[$name] = $notifier;
    }
}
