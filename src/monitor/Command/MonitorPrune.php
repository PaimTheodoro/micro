<?php

namespace Psf\Monitor\Command;

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Pruner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `monitor:prune [-e banco]`: apaga brutos fora de `retention.rawDays` (respeitando
 * o watermark, com teto de 4 x rawDays) e agregados fora de `retention.aggregateDays`.
 * Valida a retenção antes de apagar. Saída: `Linhas apagadas`, `Agregados apagados`.
 */
class MonitorPrune extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('monitor:prune')
            ->setDescription('Apaga registros brutos do monitor fora da retenção')
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_REQUIRED,
                "Chave do banco em config['db'] (padrão: banco configurado para o monitor)"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $opt = $input->getOption('environment');
            $db = is_string($opt) && $opt !== '' ? $opt : Config::database();
            $dbConfig = \PSF::config("db")[$db] ?? null;
            if (!is_array($dbConfig)) {
                throw new \RuntimeException("Banco '{$db}' não encontrado em config['db'].");
            }
            $rawDays = CommandOptions::retentionDays('rawDays');
            $aggregateDays = CommandOptions::retentionDays('aggregateDays');
            $pruner = new Pruner(Connection::open($db), DialectFactory::fromConfig($dbConfig));
            $now = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
            $deleted = $pruner->pruneRaw($now, $rawDays);
            $output->writeln("Linhas apagadas: " . $deleted);
            $output->writeln("Agregados apagados: " . $pruner->pruneAggregates($now, $aggregateDays));
            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha ao limpar o monitor: ' . $e->getMessage() . \Psf\Monitor\MissingTable::suffix($e) . '</error>');
            return Command::FAILURE;
        }
    }
}
