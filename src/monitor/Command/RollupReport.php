<?php

namespace Psf\Monitor\Command;

use Symfony\Component\Console\Output\OutputInterface;

/** Saída compartilhada do rollup em `monitor:rollup` e `monitor:maintain` */
final class RollupReport
{
    /**
     * Imprime `Horas puladas: N` e um aviso quando alguma hora foi pulada por
     * violação de integridade (nada se `$skipped` < 1).
     */
    public static function skipped(OutputInterface $output, int $skipped): void
    {
        if ($skipped < 1) {
            return;
        }
        $output->writeln("Horas puladas: {$skipped}");
        $output->writeln(
            '<comment>Aviso: horas puladas por violação de integridade não têm agregados; '
            . 'as linhas brutas delas ficam até a limpeza (prune).</comment>'
        );
    }
}
