<?php

namespace Psf\Monitor\Sinks;

/**
 * Destino dos lotes do monitor. `Monitor::flush()` entrega um lote por request
 * gravado; a implementação padrão é `DatabaseSink`. Uma falha deve lançar
 * exceção (conta para o circuit breaker).
 */
interface SinkInterface
{
    /**
     * Grava um lote de eventos.
     *
     * @param array<string, list<array<string, mixed>>> $batch chave do lote => linhas (coluna => valor)
     * @throws \Throwable falha na gravação
     */
    public function write(array $batch): void;
}
