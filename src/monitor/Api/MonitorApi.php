<?php

namespace Psf\Monitor\Api;

use Psf\Monitor\Config;
use Psf\Monitor\Reader;

/**
 * Serviço JSON opcional de leitura do monitor. Não registra rotas: o projeto
 * escreve seus próprios métodos de controller (com `middlewares: ['authentication']`)
 * e delega aqui, então autenticação e autorização continuam sendo do projeto.
 * Desligado por padrão (`settings.monitor.api.enabled`). Nunca lança: devolve
 * sempre o formato dos controllers `[mensagem, dados, status HTTP]`.
 */
final class MonitorApi
{
    private const ENDPOINTS = ['routes', 'queries', 'exceptions', 'external', 'throughput', 'request'];
    private const ORDERS    = ['sum_ms', 'hits', 'errors', 'max_ms', 'avg_ms'];
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 1000;

    private ?Reader $reader;

    public function __construct(?Reader $reader = null)
    {
        $this->reader = $reader;
    }

    /**
     * @param array<mixed> $params parâmetros da query string (qualquer tipo; validados aqui)
     * @return array{0: string, 1: array<mixed>, 2: int}
     */
    public function handle(string $endpoint, array $params): array
    {
        try {
            // Antes de qualquer acesso ao banco.
            if (Config::get('api.enabled') !== true) {
                return ['API do monitor desabilitada', [], 404];
            }
            if (!in_array($endpoint, self::ENDPOINTS, true)) {
                return ['Endpoint desconhecido', [], 404];
            }

            return $this->dispatch($endpoint, $params);
        } catch (\Throwable $e) {
            // Sem vazar a mensagem (pode conter DSN, host, usuário): só a classe vai ao log.
            error_log('[PSF][monitor] MonitorApi falhou com ' . get_class($e));
            return ['Erro ao consultar o monitor', [], 500];
        }
    }

    /** @return array{0: string, 1: array<mixed>, 2: int} */
    private function dispatch(string $endpoint, array $params): array
    {
        if ($endpoint === 'request') {
            $id = $params['id'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{8,64}\z/D', $id) !== 1) {
                return ['Parâmetro id inválido', [], 422];
            }
            $detail = $this->reader()->request($id);

            return $detail === null ? ['Requisição não encontrada', [], 404] : ['', $detail, 200];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $to  = array_key_exists('to', $params) ? $this->parseDate($params['to']) : $now;
        if ($to === null) {
            return ['Parâmetro to inválido', [], 422];
        }
        $from = array_key_exists('from', $params) ? $this->parseDate($params['from']) : $to->modify('-24 hours');
        if ($from === null) {
            return ['Parâmetro from inválido', [], 422];
        }
        if ($from >= $to) {
            return ['Intervalo inválido: from deve ser anterior a to', [], 422];
        }

        if ($endpoint === 'throughput') {
            return ['', $this->reader()->throughput($from, $to), 200];
        }

        $limit = array_key_exists('limit', $params) ? $this->parseLimit($params['limit']) : self::DEFAULT_LIMIT;
        if ($limit === null) {
            return ['Parâmetro limit inválido', [], 422];
        }

        if ($endpoint === 'routes' || $endpoint === 'external') {
            $order = 'sum_ms';
            if (array_key_exists('order_by', $params)) {
                $order = $params['order_by'];
                if (!is_string($order) || !in_array($order, self::ORDERS, true)) {
                    return ['Parâmetro order_by inválido', [], 422];
                }
            }

            return ['', $this->reader()->{$endpoint}($from, $to, $limit, $order), 200];
        }

        $data = $endpoint === 'queries'
            ? $this->reader()->slowQueries($from, $to, $limit)
            : $this->reader()->exceptions($from, $to, $limit);

        return ['', $data, 200];
    }

    private function reader(): Reader
    {
        return $this->reader ??= new Reader();
    }

    /** Inteiro (ou string só de dígitos) entre 1 e 1000; senão `null`. */
    private function parseLimit(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,4}\z/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
            return null;
        }

        return $value;
    }

    /**
     * ISO-8601 (com `T` ou espaço; `Z`, `±HH:MM` ou `±HHMM` opcionais) ou
     * `Y-m-d H:i:s` (UTC). Sem texto livre: formato estrito, sem estouro de data.
     */
    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $re = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?\z/D';
        if (preg_match($re, $value, $m) !== 1) {
            return null;
        }

        $zone = 'UTC';
        if (isset($m[3]) && $m[3] !== '' && $m[3] !== 'Z') {
            $sign = $m[3][0];
            $hh   = (int) substr($m[3], 1, 2);
            $mm   = (int) substr(str_replace(':', '', $m[3]), 3, 2);
            if ($hh > 14 || $mm > 59) {
                return null;
            }
            $zone = sprintf('%s%02d:%02d', $sign, $hh, $mm);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1] . ' ' . $m[2], new \DateTimeZone($zone));
        $err  = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($err !== false && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
