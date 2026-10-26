<?php

namespace Psf\Monitor\Command;

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Pruner;
use Psf\Monitor\Rollup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `monitor:maintain [-e banco] [--max-hours N]`: rollup por hora seguido da limpeza
 * de brutos e agregados (o comando que o projeto agenda). Valida `--max-hours` e a
 * retenção antes de qualquer escrita. A limpeza roda mesmo se o rollup falhar
 * (exit code 1 nesse caso).
 */
class MonitorMaintain extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('monitor:maintain')
            ->setDescription('Agrega os registros brutos e apaga o que está fora da retenção')
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_REQUIRED,
                "Chave do banco em config['db'] (padrão: banco configurado para o monitor)"
            )
            ->addOption(
                'max-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Máximo de horas agregadas por execução',
                '48'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Valida tudo antes de qualquer escrita (o rollup também não pode rodar)
            $maxHours = CommandOptions::maxHours($input->getOption('max-hours'));
            $rawDays = CommandOptions::retentionDays('rawDays');
            $aggregateDays = CommandOptions::retentionDays('aggregateDays');
            $opt = $input->getOption('environment');
            $db = is_string($opt) && $opt !== '' ? $opt : Config::database();
            $dbConfig = \PSF::config("db")[$db] ?? null;
            if (!is_array($dbConfig)) {
                throw new \RuntimeException("Banco '{$db}' não encontrado em config['db'].");
            }
            $pdo = Connection::open($db);
            $dialect = DialectFactory::fromConfig($dbConfig);
            $now = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));

            // Falha do rollup não impede a limpeza: o teto de 4 x rawDays precisa valer
            // mesmo com o rollup travado (senão as tabelas brutas crescem sem limite).
            $failed = false;
            try {
                $result = (new Rollup($pdo, $dialect))->run($now, $maxHours);
                $output->writeln("Horas agregadas: " . $result['hours']);
                $output->writeln("Registros gravados: " . $result['rows']);
                $output->writeln("Rollup até: " . ($result['watermark'] ?? 'nunca'));
                RollupReport::skipped($output, $result['skipped']);
            } catch (\Throwable $e) {
                $output->writeln('<error>Falha ao agregar o monitor: ' . $e->getMessage() . \Psf\Monitor\MissingTable::suffix($e) . '</error>');
                $failed = true;
            }

            try {
                $pruner = new Pruner($pdo, $dialect);
                $output->writeln("Linhas apagadas: " . $pruner->pruneRaw($now, $rawDays));
                $output->writeln("Agregados apagados: " . $pruner->pruneAggregates($now, $aggregateDays));
            } catch (\Throwable $e) {
                $output->writeln('<error>Falha ao limpar o monitor: ' . $e->getMessage() . \Psf\Monitor\MissingTable::suffix($e) . '</error>');
                $failed = true;
            }
            return $failed ? Command::FAILURE : Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha na manutenção do monitor: ' . $e->getMessage() . \Psf\Monitor\MissingTable::suffix($e) . '</error>');
            return Command::FAILURE;
        }
    }
}
